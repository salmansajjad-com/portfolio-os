<?php

namespace App\Livewire;

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Article;
use App\Models\AttendanceDay;
use App\Models\Credential;
use App\Models\Link;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\PeopleVisibilityService;
use App\Services\ProjectMonthStatsService;
use App\Support\AppSettings;
use App\Support\DisplayTimezone;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render()
    {
        $user = Auth::user();
        $canViewProjects = $user?->hasPermission('projects.view');

        $projectsQuery = $canViewProjects
            ? Project::query()->accessibleBy($user)
            : Project::query()->whereRaw('1 = 0');

        $totalProjects = (clone $projectsQuery)->count();
        $byStatus = (clone $projectsQuery)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $thresholds = AppSettings::get('credential_expiry_thresholds', [30, 14, 7]);
        if (! is_array($thresholds) || $thresholds === []) {
            $thresholds = [30, 14, 7];
        }
        $thresholds = array_map('intval', $thresholds);

        $expiring = collect();
        if ($user?->hasPermission('credentials.view')) {
            $accessibleIds = $user->isAdmin()
                ? null
                : $user->accessibleProjectIds();

            $expiring = Credential::query()
                ->with('project')
                ->when($accessibleIds !== null, fn ($q) => $q->whereIn('project_id', $accessibleIds ?: [0]))
                ->expiringWithin($thresholds)
                ->orderBy('expires_on')
                ->limit(15)
                ->get();
        }

        $recentProjects = $canViewProjects
            ? (clone $projectsQuery)->orderByDesc('updated_at')->limit(5)->get()
            : collect();

        $tz = DisplayTimezone::name();
        $todayLocal = DisplayTimezone::today();
        // due_date is a date column (no time); compare to local calendar date string
        $myTasksDueToday = collect();
        if ($user?->hasPermission('tasks.view')) {
            $myTasksDueToday = Task::query()
                ->with('project')
                ->accessibleBy($user)
                ->where('assigned_to', $user->id)
                ->whereDate('due_date', $todayLocal)
                ->where('status', '!=', TaskStatus::Approved->value)
                ->where(fn ($q) => $q->where('is_recurrence_source', false)->orWhereNull('is_recurrence_source'))
                ->orderBy('due_date')
                ->limit(10)
                ->get();
        }

        $awaitingMyApproval = collect();
        $awaitingCount = 0;
        if ($user?->hasAnyPermission('tasks.approve', 'articles.approve', 'links.approve')) {
            if ($user->hasPermission('tasks.approve')) {
                $tasks = Task::query()->with('project')->accessibleBy($user)->awaitingApproval()->limit(5)->get()
                    ->map(fn (Task $t) => [
                        'type' => 'Task',
                        'label' => $t->title,
                        'project' => $t->project?->domain,
                        'url' => route('approvals.queue'),
                    ]);
                $awaitingMyApproval = $awaitingMyApproval->merge($tasks);
                $awaitingCount += Task::query()->accessibleBy($user)->awaitingApproval()->count();
            }
            if ($user->hasPermission('articles.approve')) {
                $arts = Article::query()->with('project')->accessibleBy($user)->awaitingApproval()->limit(5)->get()
                    ->map(fn (Article $a) => [
                        'type' => 'Article',
                        'label' => $a->title,
                        'project' => $a->project?->domain,
                        'url' => route('approvals.queue'),
                    ]);
                $awaitingMyApproval = $awaitingMyApproval->merge($arts);
                $awaitingCount += Article::query()->accessibleBy($user)->awaitingApproval()->count();
            }
            if ($user->hasPermission('links.approve')) {
                $lnks = Link::query()->with('project')->accessibleBy($user)->awaitingApproval()->limit(5)->get()
                    ->map(fn (Link $l) => [
                        'type' => 'Link',
                        'label' => $l->source_domain,
                        'project' => $l->project?->domain,
                        'url' => route('approvals.queue'),
                    ]);
                $awaitingMyApproval = $awaitingMyApproval->merge($lnks);
                $awaitingCount += Link::query()->accessibleBy($user)->awaitingApproval()->count();
            }
            $awaitingMyApproval = $awaitingMyApproval->take(8);
        }

        $openTasksPortfolio = $canViewProjects
            ? (int) Task::query()->accessibleBy($user)->open()
                ->where(fn ($q) => $q->where('is_recurrence_source', false)->orWhereNull('is_recurrence_source'))
                ->count()
            : 0;

        $teamAttendanceToday = collect();
        $canSeePeople = (bool) $user?->hasPermission('attendance.view');
        if ($canSeePeople && $user) {
            $visibility = app(PeopleVisibilityService::class);
            $ids = $visibility->viewableUserIds($user);
            $today = DisplayTimezone::today();

            if ($user->hasPermission('people.view_team') || $user->isAdmin()) {
                $teamUsers = User::query()
                    ->whereIn('id', $ids->all())
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->limit(12)
                    ->get();

                $days = AttendanceDay::query()
                    ->whereIn('user_id', $teamUsers->pluck('id'))
                    ->whereDate('local_date', $today)
                    ->get()
                    ->keyBy('user_id');

                $teamAttendanceToday = $teamUsers->map(function (User $u) use ($days) {
                    /** @var AttendanceDay|null $day */
                    $day = $days->get($u->id);

                    return [
                        'name' => $u->name,
                        'status' => $day?->status?->value ?? 'absent',
                        'status_label' => $day?->status?->label() ?? 'Not checked in',
                        'is_late' => (bool) ($day?->is_late),
                    ];
                });
            }
        }

        // One batched pass for the whole portfolio. Summing per project used to
        // drag the full P&L pipeline through a loop on the landing page.
        $monthTotals = ['revenue_paisa' => 0, 'cost_paisa' => 0];

        if ($canViewProjects) {
            $stats = app(ProjectMonthStatsService::class)
                ->forProjects((clone $projectsQuery)->pluck('id')->map(fn ($id) => (int) $id)->all());

            $monthTotals = [
                'revenue_paisa' => (int) $stats->sum('revenue_paisa'),
                'cost_paisa' => (int) $stats->sum('cost_paisa'),
            ];
        }

        return view('livewire.dashboard', [
            'totalProjects' => $totalProjects,
            'byStatus' => $byStatus,
            'statusOptions' => ProjectStatus::options(),
            'expiring' => $expiring,
            'thresholds' => $thresholds,
            'recentProjects' => $recentProjects,
            'recentOpenTasks' => Task::query()
                ->whereIn('project_id', $recentProjects->pluck('id'))
                ->open()
                ->where(fn ($q) => $q->where('is_recurrence_source', false)->orWhereNull('is_recurrence_source'))
                ->groupBy('project_id')
                ->selectRaw('project_id, COUNT(*) as total')
                ->pluck('total', 'project_id'),
            'monthRevenuePlaceholder' => $monthTotals['revenue_paisa'],
            'monthCostPlaceholder' => $monthTotals['cost_paisa'],
            'canViewProjects' => $canViewProjects,
            'canViewCredentials' => (bool) $user?->hasPermission('credentials.view'),
            'myTasksDueToday' => $myTasksDueToday,
            'awaitingMyApproval' => $awaitingMyApproval,
            'awaitingCount' => $awaitingCount,
            'openTasksPortfolio' => $openTasksPortfolio,
            'canSeeApprovals' => (bool) $user?->hasAnyPermission('tasks.approve', 'articles.approve', 'links.approve'),
            'canSeeTasks' => (bool) $user?->hasPermission('tasks.view'),
            'teamAttendanceToday' => $teamAttendanceToday,
            'canSeeTeamAttendance' => $teamAttendanceToday->isNotEmpty(),
            'canSeePeople' => $canSeePeople,
        ]);
    }
}
