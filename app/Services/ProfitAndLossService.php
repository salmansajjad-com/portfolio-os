<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseAllocation;
use App\Models\Project;
use App\Models\Revenue;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Reusable read-only P&L report (whitelist-friendly for future AI).
 */
class ProfitAndLossService
{
    public function __construct(
        protected SharedExpenseAllocationService $allocations,
    ) {}

    /**
     * @return array{
     *   from: string,
     *   to: string,
     *   projects: list<array{
     *     project_id: int,
     *     domain: string,
     *     revenue_paisa: int,
     *     direct_expense_paisa: int,
     *     shared_expense_paisa: int,
     *     total_expense_paisa: int,
     *     net_profit_paisa: int
     *   }>,
     *   totals: array{
     *     revenue_paisa: int,
     *     direct_expense_paisa: int,
     *     shared_expense_paisa: int,
     *     total_expense_paisa: int,
     *     net_profit_paisa: int
     *   }
     * }
     */
    public function report(?User $user, string $from, string $to, ?array $projectIds = null): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();

        $query = Project::query()->orderBy('domain');
        if ($user && ! $user->hasPortfolioFinanceAccess()) {
            $ids = $user->accessibleProjectIds() ?: [0];
            $query->whereIn('id', $ids);
        }
        if ($projectIds !== null) {
            $query->whereIn('id', $projectIds);
        }

        /** @var Collection<int, Project> $projects */
        $projects = $query->get();

        // Ensure shared allocations cover months in range
        $cursor = Carbon::parse($from)->startOfMonth();
        $endMonth = Carbon::parse($to)->startOfMonth();
        while ($cursor->lte($endMonth)) {
            $this->allocations->rebuildForMonth($cursor->toDateString());
            $cursor->addMonth();
        }

        $fromMonth = Carbon::parse($from)->startOfMonth()->toDateString();
        $toMonth = Carbon::parse($to)->startOfMonth()->toDateString();

        $rows = [];
        $totals = [
            'revenue_paisa' => 0,
            'direct_expense_paisa' => 0,
            'shared_expense_paisa' => 0,
            'total_expense_paisa' => 0,
            'net_profit_paisa' => 0,
        ];

        // Three grouped queries for the whole portfolio rather than three per
        // project. The loop below only reads what they returned, so the query
        // count no longer grows with the number of sites.
        $ids = $projects->pluck('id')->all();

        $revenueByProject = Revenue::query()
            ->whereIn('project_id', $ids)
            ->whereDate('period_month', '>=', $fromMonth)
            ->whereDate('period_month', '<=', $toMonth)
            ->groupBy('project_id')
            ->selectRaw('project_id, SUM(amount_pkr_paisa) as total')
            ->pluck('total', 'project_id');

        $directByProject = Expense::query()
            ->whereIn('project_id', $ids)
            ->where('is_shared', false)
            ->whereDate('expense_date', '>=', $from)
            ->whereDate('expense_date', '<=', $to)
            ->groupBy('project_id')
            ->selectRaw('project_id, SUM(amount_paisa) as total')
            ->pluck('total', 'project_id');

        $sharedByProject = ExpenseAllocation::query()
            ->whereIn('project_id', $ids)
            ->whereDate('period_month', '>=', $fromMonth)
            ->whereDate('period_month', '<=', $toMonth)
            ->groupBy('project_id')
            ->selectRaw('project_id, SUM(amount_paisa) as total')
            ->pluck('total', 'project_id');

        foreach ($projects as $project) {
            $revenue = (int) ($revenueByProject[$project->id] ?? 0);
            $direct = (int) ($directByProject[$project->id] ?? 0);
            $shared = (int) ($sharedByProject[$project->id] ?? 0);

            $totalExpense = $direct + $shared;
            $net = $revenue - $totalExpense;

            $rows[] = [
                'project_id' => $project->id,
                'domain' => $project->domain,
                'revenue_paisa' => $revenue,
                'direct_expense_paisa' => $direct,
                'shared_expense_paisa' => $shared,
                'total_expense_paisa' => $totalExpense,
                'net_profit_paisa' => $net,
            ];

            $totals['revenue_paisa'] += $revenue;
            $totals['direct_expense_paisa'] += $direct;
            $totals['shared_expense_paisa'] += $shared;
            $totals['total_expense_paisa'] += $totalExpense;
            $totals['net_profit_paisa'] += $net;
        }

        return [
            'from' => $from,
            'to' => $to,
            'projects' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * Single calendar month report.
     *
     * @return array<string, mixed>
     */
    public function forMonth(?User $user, string $yearMonth, ?array $projectIds = null): array
    {
        $start = Carbon::parse(strlen($yearMonth) === 7 ? $yearMonth.'-01' : $yearMonth)->startOfMonth();

        return $this->report(
            $user,
            $start->toDateString(),
            $start->copy()->endOfMonth()->toDateString(),
            $projectIds,
        );
    }

    public function projectRowForMonth(Project $project, string $yearMonth): array
    {
        $report = $this->forMonth(null, $yearMonth, [$project->id]);

        return $report['projects'][0] ?? [
            'project_id' => $project->id,
            'domain' => $project->domain,
            'revenue_paisa' => 0,
            'direct_expense_paisa' => 0,
            'shared_expense_paisa' => 0,
            'total_expense_paisa' => 0,
            'net_profit_paisa' => 0,
        ];
    }
}
