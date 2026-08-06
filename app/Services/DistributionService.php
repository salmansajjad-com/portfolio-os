<?php

namespace App\Services;

use App\Enums\DistributionStatus;
use App\Enums\PartnerLedgerType;
use App\Models\DistributionLine;
use App\Models\DistributionRun;
use App\Models\PartnerLedgerEntry;
use App\Models\Project;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class DistributionService
{
    public function __construct(
        protected ProfitAndLossService $pnl,
        protected PartnerLedgerService $ledger,
        protected MoneyAuditService $audit,
    ) {}

    /**
     * Create (or rebuild if still draft same month) a draft distribution for a calendar month.
     */
    public function createDraft(string $yearMonth, User $actor, int $holdbackBps = 0, ?string $notes = null): DistributionRun
    {
        $month = Carbon::parse(strlen($yearMonth) === 7 ? $yearMonth.'-01' : $yearMonth)
            ->startOfMonth()
            ->toDateString();

        if ($holdbackBps < 0 || $holdbackBps > 10000) {
            throw new InvalidArgumentException('Holdback must be between 0 and 10000 bps.');
        }

        // Block second non-voided approved for same month (allow multiple drafts only if previous voided/draft rebuilt)
        $existingApproved = DistributionRun::query()
            ->whereDate('period_month', $month)
            ->where('status', DistributionStatus::Approved->value)
            ->exists();

        if ($existingApproved) {
            throw new RuntimeException('An approved distribution already exists for this month. Void it before creating a replacement.');
        }

        return DB::transaction(function () use ($month, $actor, $holdbackBps, $notes) {
            // Soft-delete prior drafts for the month
            DistributionRun::query()
                ->whereDate('period_month', $month)
                ->where('status', DistributionStatus::Draft->value)
                ->each(function (DistributionRun $run) {
                    $run->lines()->delete();
                    $run->delete();
                });

            $report = $this->pnl->forMonth($actor, $month);
            $snapshot = $this->captureOwnershipSnapshot(
                collect($report['projects'])->pluck('project_id')->all()
            );

            $run = DistributionRun::query()->create([
                'period_month' => $month,
                'status' => DistributionStatus::Draft,
                'holdback_bps' => $holdbackBps,
                'total_revenue_paisa' => $report['totals']['revenue_paisa'],
                'total_direct_expense_paisa' => $report['totals']['direct_expense_paisa'],
                'total_shared_expense_paisa' => $report['totals']['shared_expense_paisa'],
                'total_net_profit_paisa' => $report['totals']['net_profit_paisa'],
                'total_holdback_paisa' => 0,
                'total_credited_paisa' => 0,
                'ownership_snapshot' => $snapshot,
                'notes' => $notes,
                'created_by' => $actor->id,
            ]);

            $this->buildLines($run, $report, $snapshot, $holdbackBps);

            $this->audit->log('distribution.draft_created', $run, null, $run->fresh()->toArray(), $actor);

            return $run->fresh(['lines']);
        });
    }

    public function approve(DistributionRun $run, User $actor): DistributionRun
    {
        if ($run->status !== DistributionStatus::Draft) {
            throw new RuntimeException('Only draft distribution runs can be approved.');
        }

        return DB::transaction(function () use ($run, $actor) {
            // Take the row lock before reading status: two clicks, two tabs or a
            // retried request must not both get past the draft check and credit
            // partners twice.
            $locked = DistributionRun::query()->lockForUpdate()->find($run->id);

            if (! $locked || $locked->status !== DistributionStatus::Draft) {
                throw new RuntimeException('Distribution run is no longer a draft.');
            }

            $month = $locked->period_month->toDateString();

            $rivalApproved = DistributionRun::query()
                ->whereDate('period_month', $month)
                ->where('status', DistributionStatus::Approved->value)
                ->whereKeyNot($locked->id)
                ->lockForUpdate()
                ->exists();

            if ($rivalApproved) {
                throw new RuntimeException('An approved distribution already exists for this month. Void it before approving another.');
            }

            // Approval is the freeze point, so rebuild from current figures rather
            // than paying out whatever the draft captured. A draft raised before
            // late revenue landed would otherwise pay the stale amount, and the
            // ownership snapshot would disagree with the lines it was paid from.
            $report = $this->pnl->forMonth($actor, $month);
            $snapshot = $this->captureOwnershipSnapshot(
                collect($report['projects'])->pluck('project_id')->all()
            );

            $locked->lines()->delete();

            $locked->update([
                'total_revenue_paisa' => $report['totals']['revenue_paisa'],
                'total_direct_expense_paisa' => $report['totals']['direct_expense_paisa'],
                'total_shared_expense_paisa' => $report['totals']['shared_expense_paisa'],
                'total_net_profit_paisa' => $report['totals']['net_profit_paisa'],
                'ownership_snapshot' => $snapshot,
            ]);

            $this->buildLines($locked, $report, $snapshot, (int) $locked->holdback_bps);

            $periodLabel = $locked->period_month->format('Y-m');

            foreach ($locked->lines()->get() as $line) {
                if ((int) $line->credited_paisa === 0) {
                    continue;
                }

                $this->ledger->credit(
                    userId: (int) $line->user_id,
                    amountPaisa: (int) $line->credited_paisa,
                    type: PartnerLedgerType::ProfitCredit,
                    description: 'Profit distribution '.$periodLabel.' — project #'.$line->project_id,
                    actor: $actor,
                    projectId: (int) $line->project_id,
                    distributionRunId: (int) $locked->id,
                    distributionLineId: (int) $line->id,
                    entryDate: $locked->period_month->copy()->endOfMonth()->toDateString(),
                );
            }

            $locked->update([
                'status' => DistributionStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            $this->audit->log('distribution.approved', $locked, null, $locked->fresh()->toArray(), $actor);

            return $locked->fresh(['lines']);
        });
    }

    public function void(DistributionRun $run, User $actor, string $reason): DistributionRun
    {
        if ($run->status !== DistributionStatus::Approved) {
            throw new RuntimeException('Only approved runs can be voided.');
        }

        return DB::transaction(function () use ($run, $actor, $reason) {
            // Reverse ledger credits with adjusting withdrawal/adjustment entries
            $entries = PartnerLedgerEntry::query()
                ->where('distribution_run_id', $run->id)
                ->where('type', PartnerLedgerType::ProfitCredit->value)
                ->get();

            foreach ($entries as $entry) {
                $this->ledger->credit(
                    userId: (int) $entry->user_id,
                    amountPaisa: -1 * (int) $entry->amount_paisa,
                    type: PartnerLedgerType::Adjustment,
                    description: 'Void distribution #'.$run->id.': '.$reason,
                    actor: $actor,
                    projectId: $entry->project_id,
                    distributionRunId: (int) $run->id,
                    entryDate: now()->toDateString(),
                    notes: 'Reversal of ledger entry #'.$entry->id,
                );
            }

            $run->update([
                'status' => DistributionStatus::Voided,
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            $this->audit->log('distribution.voided', $run, null, [
                'reason' => $reason,
            ], $actor);

            return $run->fresh();
        });
    }

    /**
     * Write the per-owner lines for a run and roll the totals back onto it.
     *
     * @param  array{projects: list<array<string, mixed>>, totals: array<string, int>}  $report
     * @param  array<string, list<array{user_id: int, share_bps: int, name: string}>>  $snapshot
     */
    protected function buildLines(DistributionRun $run, array $report, array $snapshot, int $holdbackBps): void
    {
        $totalHoldback = 0;
        $totalCredited = 0;

        foreach ($report['projects'] as $row) {
            $projectId = (int) $row['project_id'];
            $owners = $snapshot[(string) $projectId] ?? $snapshot[$projectId] ?? [];

            $net = (int) $row['net_profit_paisa'];
            $distributable = max(0, $net);

            if ($owners === []) {
                // Silently skipping would leave the profit in the run totals while
                // crediting it to nobody, so the ledger would not add up.
                if ($distributable > 0) {
                    throw new RuntimeException(
                        'Project #'.$projectId.' has profit for this month but no ownership split. '
                        .'Set its owners to 100% before distributing.'
                    );
                }

                continue;
            }

            // Round once across the whole project so the shares sum to the profit exactly.
            $bpsByIndex = [];
            foreach ($owners as $index => $owner) {
                $bpsByIndex[$index] = (int) $owner['share_bps'];
            }

            $grossByIndex = Money::allocateBps($distributable, $bpsByIndex);
            $holdByIndex = Money::allocateBps(
                Money::applyBps($distributable, $holdbackBps),
                $grossByIndex
            );

            foreach ($owners as $index => $owner) {
                $gross = $grossByIndex[$index];
                $hold = min($grossByIndex[$index], $holdByIndex[$index]);
                $credit = $gross - $hold;

                DistributionLine::query()->create([
                    'distribution_run_id' => $run->id,
                    'project_id' => $projectId,
                    'user_id' => (int) $owner['user_id'],
                    'revenue_paisa' => $row['revenue_paisa'],
                    'direct_expense_paisa' => $row['direct_expense_paisa'],
                    'shared_expense_paisa' => $row['shared_expense_paisa'],
                    'net_profit_paisa' => $net,
                    'share_bps' => (int) $owner['share_bps'],
                    'gross_share_paisa' => $gross,
                    'holdback_paisa' => $hold,
                    'credited_paisa' => $credit,
                ]);

                $totalHoldback += $hold;
                $totalCredited += $credit;
            }
        }

        $run->update([
            'total_holdback_paisa' => $totalHoldback,
            'total_credited_paisa' => $totalCredited,
        ]);
    }

    /**
     * Attempting to mutate an approved run must fail.
     */
    public function assertEditable(DistributionRun $run): void
    {
        if (! $run->isEditable()) {
            throw new RuntimeException('Approved or voided distribution runs cannot be edited. Void and create a new run.');
        }
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<string, list<array{user_id: int, share_bps: int, name: string}>>
     */
    protected function captureOwnershipSnapshot(array $projectIds): array
    {
        $snapshot = [];
        $projects = Project::query()->with('owners')->whereIn('id', $projectIds)->get();

        foreach ($projects as $project) {
            $snapshot[(string) $project->id] = $project->owners->map(fn (User $u) => [
                'user_id' => (int) $u->id,
                'share_bps' => (int) $u->pivot->share_bps,
                'name' => $u->name,
            ])->values()->all();
        }

        return $snapshot;
    }
}
