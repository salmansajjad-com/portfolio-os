<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseAllocation;
use App\Models\Revenue;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Month figures for a set of projects in a fixed number of queries.
 *
 * The portfolio list and the dashboard both want revenue, cost, profit and open
 * task counts per project. Asking each project for its own totals meant the page
 * issued four queries per row — and the cost lookup dragged in the whole P&L
 * pipeline each time. On a 60-site portfolio that was hundreds of queries before
 * anything rendered. This answers the same question with four, whatever the
 * portfolio size.
 */
class ProjectMonthStatsService
{
    /**
     * @param  array<int, int>  $projectIds
     * @return Collection<int, array{revenue_paisa: int, cost_paisa: int, profit_paisa: int, open_tasks: int}>
     */
    public function forProjects(array $projectIds, ?string $yearMonth = null): Collection
    {
        if ($projectIds === []) {
            return collect();
        }

        $start = $yearMonth
            ? Carbon::parse(strlen($yearMonth) === 7 ? $yearMonth.'-01' : $yearMonth)->startOfMonth()
            : now('UTC')->startOfMonth();

        $monthStart = $start->toDateString();
        $monthEnd = $start->copy()->endOfMonth()->toDateString();

        $revenue = Revenue::query()
            ->whereIn('project_id', $projectIds)
            ->whereDate('period_month', $monthStart)
            ->groupBy('project_id')
            ->selectRaw('project_id, SUM(amount_pkr_paisa) as total')
            ->pluck('total', 'project_id');

        $direct = Expense::query()
            ->whereIn('project_id', $projectIds)
            ->where('is_shared', false)
            ->whereDate('expense_date', '>=', $monthStart)
            ->whereDate('expense_date', '<=', $monthEnd)
            ->groupBy('project_id')
            ->selectRaw('project_id, SUM(amount_paisa) as total')
            ->pluck('total', 'project_id');

        $shared = ExpenseAllocation::query()
            ->whereIn('project_id', $projectIds)
            ->whereDate('period_month', $monthStart)
            ->groupBy('project_id')
            ->selectRaw('project_id, SUM(amount_paisa) as total')
            ->pluck('total', 'project_id');

        $openTasks = Task::query()
            ->whereIn('project_id', $projectIds)
            ->open()
            ->where(function ($q) {
                $q->where('is_recurrence_source', false)
                    ->orWhereNull('is_recurrence_source');
            })
            ->groupBy('project_id')
            ->selectRaw('project_id, COUNT(*) as total')
            ->pluck('total', 'project_id');

        return collect($projectIds)->mapWithKeys(function (int $id) use ($revenue, $direct, $shared, $openTasks) {
            $rev = (int) ($revenue[$id] ?? 0);
            $cost = (int) ($direct[$id] ?? 0) + (int) ($shared[$id] ?? 0);

            return [$id => [
                'revenue_paisa' => $rev,
                'cost_paisa' => $cost,
                'profit_paisa' => $rev - $cost,
                'open_tasks' => (int) ($openTasks[$id] ?? 0),
            ]];
        });
    }
}
