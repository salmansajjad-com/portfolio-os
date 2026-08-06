<?php

namespace App\Services;

use App\Enums\ArticleStatus;
use App\Enums\LinkWorkflowStatus;
use App\Models\Article;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Link;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Support\Currency;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Expenses for Money module + idempotent Work approval hooks.
 */
class ExpenseService
{
    public function __construct(
        protected MoneyAuditService $audit,
        protected SharedExpenseAllocationService $allocations,
    ) {}

    public function ensureCategory(string $slug, string $name): ExpenseCategory
    {
        return ExpenseCategory::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'is_system' => true],
        );
    }

    /**
     * Seed system categories (idempotent).
     */
    public function seedSystemCategories(): void
    {
        $cats = [
            'domain' => 'Domain',
            'hosting' => 'Hosting',
            'ssl' => 'SSL',
            'content' => 'Content',
            'links' => 'Links',
            'tools' => 'Tools',
            'salaries' => 'Salaries',
            'transfer_fees' => 'Transfer fees',
            'other' => 'Other',
            'article_writer' => 'Article writer',
            'link_building' => 'Link building',
        ];

        foreach ($cats as $slug => $name) {
            $this->ensureCategory($slug, $name);
        }
    }

    /**
     * @param  array{
     *   project_id?: int|null,
     *   is_shared?: bool,
     *   expense_category_id?: int|null,
     *   amount_paisa?: int,
     *   amount?: string|float|int,
     *   description: string,
     *   expense_date: string,
     *   notes?: string|null,
     *   is_paid?: bool,
     *   currency?: string,
     * }  $data
     */
    public function createManual(array $data, User $actor, ?UploadedFile $receipt = null): Expense
    {
        $isShared = (bool) ($data['is_shared'] ?? false);
        $projectId = $isShared ? null : (int) ($data['project_id'] ?? 0);

        if (! $isShared && $projectId < 1) {
            throw new InvalidArgumentException('Project is required for direct expenses.');
        }

        $amount = isset($data['amount_paisa'])
            ? (int) $data['amount_paisa']
            : Money::toMinor($data['amount'] ?? 0);

        $expense = Expense::query()->create([
            'project_id' => $projectId ?: null,
            'is_shared' => $isShared,
            'expense_category_id' => $data['expense_category_id'] ?? null,
            'amount_paisa' => max(0, $amount),
            'currency' => $data['currency'] ?? Currency::code(),
            'description' => $data['description'],
            'expense_date' => $data['expense_date'],
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'notes' => $data['notes'] ?? null,
            'is_paid' => (bool) ($data['is_paid'] ?? false),
            'paid_at' => ! empty($data['is_paid']) ? now() : null,
        ]);

        if ($receipt) {
            $this->storeReceipt($expense, $receipt);
        }

        if ($expense->is_shared) {
            $this->allocations->allocateExpense($expense);
        }

        $this->audit->log('expense.created', $expense, null, $expense->toArray(), $actor);

        return $expense->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateManual(Expense $expense, array $data, User $actor, ?UploadedFile $receipt = null): Expense
    {
        if ($expense->source_type) {
            // Work-sourced: only notes / paid flag / receipt allowed
            $old = $expense->toArray();
            $expense->update([
                'notes' => $data['notes'] ?? $expense->notes,
                'is_paid' => array_key_exists('is_paid', $data) ? (bool) $data['is_paid'] : $expense->is_paid,
                'paid_at' => array_key_exists('is_paid', $data)
                    ? ((bool) $data['is_paid'] ? ($expense->paid_at ?? now()) : null)
                    : $expense->paid_at,
                'updated_by' => $actor->id,
            ]);
            if ($receipt) {
                $this->storeReceipt($expense, $receipt);
            }
            $this->audit->log('expense.updated', $expense, $old, $expense->fresh()->toArray(), $actor);

            return $expense->fresh();
        }

        $old = $expense->toArray();
        $isShared = array_key_exists('is_shared', $data) ? (bool) $data['is_shared'] : $expense->is_shared;
        $projectId = $isShared ? null : (int) ($data['project_id'] ?? $expense->project_id);

        $amount = isset($data['amount_paisa'])
            ? (int) $data['amount_paisa']
            : (array_key_exists('amount', $data)
                ? Money::toMinor($data['amount'])
                : (int) $expense->amount_paisa);

        $expense->update([
            'project_id' => $projectId ?: null,
            'is_shared' => $isShared,
            'expense_category_id' => $data['expense_category_id'] ?? $expense->expense_category_id,
            'amount_paisa' => max(0, $amount),
            'description' => $data['description'] ?? $expense->description,
            'expense_date' => $data['expense_date'] ?? $expense->expense_date->toDateString(),
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $expense->notes,
            'is_paid' => array_key_exists('is_paid', $data) ? (bool) $data['is_paid'] : $expense->is_paid,
            'paid_at' => array_key_exists('is_paid', $data)
                ? ((bool) $data['is_paid'] ? ($expense->paid_at ?? now()) : null)
                : $expense->paid_at,
            'updated_by' => $actor->id,
        ]);

        if ($receipt) {
            $this->storeReceipt($expense, $receipt);
        }

        if ($expense->is_shared) {
            $this->allocations->allocateExpense($expense->fresh());
        } else {
            $this->allocations->allocateExpense($expense->fresh()); // clears allocations
        }

        $this->audit->log('expense.updated', $expense, $old, $expense->fresh()->toArray(), $actor);

        return $expense->fresh();
    }

    public function softDelete(Expense $expense, User $actor): void
    {
        $old = $expense->toArray();
        $month = $expense->expense_date->copy()->startOfMonth()->toDateString();
        $expense->delete();
        $this->allocations->rebuildForMonth($month);
        $this->audit->log('expense.deleted', $expense, $old, null, $actor);
    }

    /**
     * @param  array<int, int>  $ids
     */
    public function bulkMarkPaid(array $ids, User $actor, bool $paid = true): int
    {
        $count = 0;
        Expense::query()->whereIn('id', $ids)->get()->each(function (Expense $expense) use ($actor, $paid, &$count) {
            $old = $expense->toArray();
            $expense->update([
                'is_paid' => $paid,
                'paid_at' => $paid ? now() : null,
                'updated_by' => $actor->id,
            ]);
            $this->audit->log($paid ? 'expense.marked_paid' : 'expense.marked_unpaid', $expense, $old, $expense->fresh()->toArray(), $actor);
            $count++;
        });

        return $count;
    }

    public function storeReceipt(Expense $expense, UploadedFile $file): void
    {
        $path = $file->store('expense-receipts/'.$expense->id, 'local');
        $expense->update([
            'receipt_path' => $path,
            'receipt_original_name' => $file->getClientOriginalName(),
        ]);
    }

    public function createFromArticleApproval(Article $article, User $actor): Expense
    {
        if ($article->status !== ArticleStatus::Approved) {
            throw new InvalidArgumentException('Article must be approved before creating expense.');
        }

        return $this->createOnce(
            source: $article,
            projectId: (int) $article->project_id,
            amountPaisa: (int) $article->cost_paisa,
            description: 'Writer cost: '.$article->title.' ('.$article->target_keyword.')',
            expenseDate: ($article->publish_date ?? now())->toDateString(),
            categorySlug: 'article_writer',
            categoryName: 'Article writer',
            actor: $actor,
            linkOnSource: 'expense_id',
        );
    }

    public function createFromLinkApproval(Link $link, User $actor): Expense
    {
        if ($link->workflow_status !== LinkWorkflowStatus::Approved) {
            throw new InvalidArgumentException('Link must be approved before creating expense.');
        }

        return $this->createOnce(
            source: $link,
            projectId: (int) $link->project_id,
            amountPaisa: (int) $link->cost_paisa,
            description: 'Link cost: '.$link->source_domain.' → '.$link->target_page,
            expenseDate: ($link->link_date ?? now())->toDateString(),
            categorySlug: 'link_building',
            categoryName: 'Link building',
            actor: $actor,
            linkOnSource: 'expense_id',
        );
    }

    /**
     * Generate due recurring expense instances (idempotent per template+month).
     */
    public function generateDueRecurring(?Carbon $asOf = null): int
    {
        $asOf = $asOf ?? now();
        $created = 0;

        RecurringExpense::query()
            ->where('is_active', true)
            ->whereDate('next_run_date', '<=', $asOf->toDateString())
            ->orderBy('id')
            ->each(function (RecurringExpense $template) use ($asOf, &$created) {
                while (
                    $template->is_active
                    && $template->next_run_date
                    && $template->next_run_date->lte($asOf)
                    && ($template->ends_on === null || $template->next_run_date->lte($template->ends_on))
                ) {
                    $date = $template->next_run_date->toDateString();
                    $exists = Expense::query()
                        ->where('recurring_expense_id', $template->id)
                        ->whereDate('expense_date', $date)
                        ->exists();

                    if (! $exists) {
                        try {
                            $expense = Expense::query()->create([
                                'project_id' => $template->is_shared ? null : $template->project_id,
                                'is_shared' => $template->is_shared,
                                'expense_category_id' => $template->expense_category_id,
                                'amount_paisa' => $template->amount_paisa,
                                'currency' => $template->currency,
                                'description' => $template->description,
                                'expense_date' => $date,
                                'source_type' => RecurringExpense::class,
                                'source_id' => $template->id,
                                'created_by' => $template->created_by,
                                'recurring_expense_id' => $template->id,
                                'is_paid' => false,
                                'notes' => 'Auto-generated from recurring expense #'.$template->id,
                            ]);

                            if ($expense->is_shared) {
                                $this->allocations->allocateExpense($expense);
                            }

                            $created++;
                        } catch (UniqueConstraintViolationException) {
                            // A parallel cron drip already generated this period.
                            // Skip it and keep advancing the schedule.
                        }
                    }

                    $next = $template->next_run_date->copy()->addMonthNoOverflow();
                    $day = min($template->day_of_month, $next->daysInMonth);
                    $template->next_run_date = $next->day($day);
                    $template->last_generated_at = now();
                    $template->save();
                    $template->refresh();
                }
            });

        return $created;
    }

    /**
     * @template T of Model
     *
     * @param  T  $source
     */
    protected function createOnce(
        Model $source,
        int $projectId,
        int $amountPaisa,
        string $description,
        string $expenseDate,
        string $categorySlug,
        string $categoryName,
        User $actor,
        string $linkOnSource,
    ): Expense {
        return DB::transaction(function () use (
            $source,
            $projectId,
            $amountPaisa,
            $description,
            $expenseDate,
            $categorySlug,
            $categoryName,
            $actor,
            $linkOnSource,
        ) {
            $source->refresh();

            if ($source->{$linkOnSource}) {
                $existing = Expense::query()->find($source->{$linkOnSource});
                if ($existing) {
                    return $existing;
                }
            }

            $existingBySource = Expense::query()
                ->where('source_type', $source::class)
                ->where('source_id', $source->getKey())
                ->first();

            if ($existingBySource) {
                if (! $source->{$linkOnSource}) {
                    $source->update([$linkOnSource => $existingBySource->id]);
                }

                return $existingBySource;
            }

            $category = $this->ensureCategory($categorySlug, $categoryName);
            $sourceKey = $source::class.':'.$source->getKey();

            try {
                $expense = Expense::query()->create([
                    'project_id' => $projectId,
                    'is_shared' => false,
                    'expense_category_id' => $category->id,
                    'amount_paisa' => max(0, $amountPaisa),
                    'currency' => Currency::code(),
                    'description' => $description,
                    'expense_date' => $expenseDate,
                    'source_type' => $source::class,
                    'source_id' => $source->getKey(),
                    'source_key' => $sourceKey,
                    'created_by' => $actor->id,
                    'is_paid' => false,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Someone approved the same item at the same moment and their
                // insert landed first. Adopt their row rather than double-charging.
                $expense = Expense::query()->where('source_key', $sourceKey)->firstOrFail();

                if (! $source->{$linkOnSource}) {
                    $source->update([$linkOnSource => $expense->id]);
                }

                return $expense;
            }

            $source->update([$linkOnSource => $expense->id]);

            $this->audit->log('expense.auto_from_work', $expense, null, $expense->toArray(), $actor);

            return $expense;
        });
    }
}
