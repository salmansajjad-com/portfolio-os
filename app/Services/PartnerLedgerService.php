<?php

namespace App\Services;

use App\Enums\PartnerLedgerType;
use App\Models\PartnerLedgerEntry;
use App\Models\PartnerProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PartnerLedgerService
{
    public function __construct(
        protected MoneyAuditService $audit,
    ) {}

    public function balanceFor(int $userId): int
    {
        $last = PartnerLedgerEntry::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->first();

        return (int) ($last?->balance_after_paisa ?? 0);
    }

    /**
     * Closing balances for several partners in one query.
     *
     * The partners list asked each partner for their own balance, so the page
     * cost a query per row.
     *
     * @param  array<int, int>  $userIds
     * @return Collection<int, int>
     */
    public function balancesFor(array $userIds): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        $latestIds = PartnerLedgerEntry::query()
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->selectRaw('MAX(id) as id')
            ->pluck('id');

        $balances = PartnerLedgerEntry::query()
            ->whereIn('id', $latestIds)
            ->pluck('balance_after_paisa', 'user_id');

        return collect($userIds)->mapWithKeys(fn (int $id) => [
            $id => (int) ($balances[$id] ?? 0),
        ]);
    }

    /**
     * Post a signed amount (+ credit / − debit) with running balance.
     */
    public function credit(
        int $userId,
        int $amountPaisa,
        PartnerLedgerType $type,
        string $description,
        User $actor,
        ?int $projectId = null,
        ?int $distributionRunId = null,
        ?int $distributionLineId = null,
        ?string $entryDate = null,
        ?string $notes = null,
    ): PartnerLedgerEntry {
        if ($amountPaisa === 0 && $type !== PartnerLedgerType::Adjustment) {
            // Allow zero only for explicit adjustments — still skip
        }

        return DB::transaction(function () use (
            $userId,
            $amountPaisa,
            $type,
            $description,
            $actor,
            $projectId,
            $distributionRunId,
            $distributionLineId,
            $entryDate,
            $notes,
        ) {
            // Lock partner's latest row for serial balance
            $last = PartnerLedgerEntry::query()
                ->where('user_id', $userId)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $balance = (int) ($last?->balance_after_paisa ?? 0) + $amountPaisa;

            $entry = PartnerLedgerEntry::query()->create([
                'user_id' => $userId,
                'type' => $type,
                'amount_paisa' => $amountPaisa,
                'balance_after_paisa' => $balance,
                'entry_date' => $entryDate ?? now()->toDateString(),
                'description' => $description,
                'project_id' => $projectId,
                'distribution_run_id' => $distributionRunId,
                'distribution_line_id' => $distributionLineId,
                'created_by' => $actor->id,
                'notes' => $notes,
            ]);

            $this->audit->log('partner_ledger.created', $entry, null, $entry->toArray(), $actor);

            return $entry;
        });
    }

    public function recordCapitalIn(int $userId, int $amountPaisa, User $actor, ?string $notes = null, ?string $entryDate = null): PartnerLedgerEntry
    {
        if ($amountPaisa <= 0) {
            throw new InvalidArgumentException('Capital must be positive.');
        }

        return $this->credit(
            userId: $userId,
            amountPaisa: $amountPaisa,
            type: PartnerLedgerType::CapitalIn,
            description: 'Capital contribution',
            actor: $actor,
            entryDate: $entryDate,
            notes: $notes,
        );
    }

    public function recordWithdrawal(int $userId, int $amountPaisa, User $actor, ?string $notes = null, ?string $entryDate = null): PartnerLedgerEntry
    {
        if ($amountPaisa <= 0) {
            throw new InvalidArgumentException('Withdrawal amount must be positive.');
        }

        return $this->credit(
            userId: $userId,
            amountPaisa: -1 * $amountPaisa,
            type: PartnerLedgerType::Withdrawal,
            description: 'Withdrawal / payout',
            actor: $actor,
            entryDate: $entryDate,
            notes: $notes,
        );
    }

    /**
     * @param  array{payout_method?: string|null, payout_details?: string|null, notes?: string|null, is_active?: bool}  $data
     */
    public function upsertProfile(User $partner, array $data): PartnerProfile
    {
        return PartnerProfile::query()->updateOrCreate(
            ['user_id' => $partner->id],
            [
                'payout_method' => $data['payout_method'] ?? null,
                'payout_details' => $data['payout_details'] ?? null,
                'notes' => $data['notes'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ],
        );
    }
}
