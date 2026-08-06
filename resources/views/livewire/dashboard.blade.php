@php
    $tz = \App\Support\DisplayTimezone::name();
    $today = now($tz);
    $canSeeProfit = (bool) auth()->user()?->hasPermission('pnl.view');
    $monthProfit = $monthRevenuePlaceholder - $monthCostPlaceholder;
    $tasksDueCount = $myTasksDueToday->count();
    $urgentExpiring = $expiring->filter(fn ($credential) => ($credential->daysUntilExpiry() ?? 99) <= 7)->count();
    $hasAnything = $canSeeApprovals || $canSeeTasks || $canViewProjects || $canViewCredentials || $canSeePeople;
@endphp

<div class="space-y-6">
    <x-page-header
        title="Home"
        :subtitle="'What needs you today — '.$today->format('l j F').' · '.$tz"
        :breadcrumbs="[['label' => 'Overview']]"
    >
        <x-slot:actions>
            @if ($canSeeApprovals && $awaitingCount > 0)
                <x-button icon="approvals" href="{{ route('approvals.queue') }}" wire:navigate>
                    Review {{ $awaitingCount }} {{ \Illuminate\Support\Str::plural('item', $awaitingCount) }}
                </x-button>
            @elseif ($canSeeTasks)
                <x-button variant="secondary" icon="tasks" href="{{ route('tasks.index', ['mineOnly' => '1']) }}" wire:navigate>
                    My tasks
                </x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if (! $hasAnything)
        <x-empty-state
            icon="inbox"
            title="Your account has no sections yet"
            description="Roles decide what appears here. Ask an admin to assign you a role and this page will fill up with your work, approvals and figures."
        />
    @endif

    {{-- The four numbers that decide what you do next. --}}
    @if ($canSeeApprovals || $canSeeTasks || $canViewCredentials || $canViewProjects)
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @if ($canSeeApprovals)
                <x-stat
                    label="Awaiting my approval"
                    :value="$awaitingCount"
                    :tone="$awaitingCount > 0 ? 'warn' : 'neutral'"
                    icon="approvals"
                    :hint="$awaitingCount > 0 ? 'in the queue' : 'queue is clear'"
                    :href="route('approvals.queue')"
                />
            @endif

            @if ($canSeeTasks)
                <x-stat
                    label="My tasks due today"
                    :value="$tasksDueCount"
                    :tone="$tasksDueCount > 0 ? 'accent' : 'neutral'"
                    icon="tasks"
                    :hint="$tasksDueCount > 0 ? 'due before midnight' : 'nothing due'"
                    :href="route('tasks.index', ['mineOnly' => '1'])"
                />
            @endif

            @if ($canViewCredentials)
                <x-stat
                    label="Expiring credentials"
                    :value="$expiring->count()"
                    :tone="$urgentExpiring > 0 ? 'danger' : ($expiring->count() > 0 ? 'warn' : 'neutral')"
                    icon="credentials"
                    :hint="$urgentExpiring > 0 ? $urgentExpiring.' inside 7 days' : 'within '.implode('/', $thresholds).' days'"
                />
            @endif

            @if ($canViewProjects)
                @if ($canSeeProfit)
                    <x-stat
                        label="Profit this month"
                        :value="\App\Support\Money::rounded($monthProfit)"
                        :tone="$monthProfit >= 0 ? 'success' : 'danger'"
                        icon="pnl"
                        :hint="\App\Support\Currency::code().' · '.\App\Support\Money::rounded($monthRevenuePlaceholder).' in, '.\App\Support\Money::rounded($monthCostPlaceholder).' out'"
                        :href="route('money.pnl')"
                    />
                @else
                    <x-stat
                        label="Revenue this month"
                        :value="\App\Support\Money::rounded($monthRevenuePlaceholder)"
                        tone="accent"
                        icon="revenue"
                        :hint="\App\Support\Currency::code().' · across your sites'"
                    />
                @endif
            @endif
        </div>
    @endif

    @if ($canSeeApprovals || $canSeeTasks)
        <div class="grid gap-4 lg:grid-cols-2">
            @if ($canSeeApprovals)
                <x-card title="Awaiting my approval" icon="approvals" padding="none" flush>
                    <x-slot:actions>
                        <x-button size="sm" variant="ghost" iconRight="arrow-right" href="{{ route('approvals.queue') }}" wire:navigate>
                            Open queue
                        </x-button>
                    </x-slot:actions>

                    @if ($awaitingMyApproval->isEmpty())
                        <div class="px-6 py-10 text-center">
                            <p class="text-sm font-medium text-ink">Queue is clear</p>
                            <p class="mt-1 text-xs text-muted">Submitted tasks, drafts and links land here for your sign-off.</p>
                        </div>
                    @else
                        <ul class="divide-y divide-line">
                            @foreach ($awaitingMyApproval as $row)
                                <li>
                                    <a
                                        href="{{ $row['url'] }}"
                                        wire:navigate
                                        class="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-subtle sm:px-6"
                                    >
                                        <x-badge tone="warn" size="sm">{{ $row['type'] }}</x-badge>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm font-medium text-ink">{{ $row['label'] }}</span>
                                            <span class="block truncate font-mono text-[10px] text-faint">{{ $row['project'] }}</span>
                                        </span>
                                        <x-icon name="chevron-right" class="size-4 shrink-0 text-faint" />
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-card>
            @endif

            @if ($canSeeTasks)
                <x-card title="My tasks due today" icon="tasks" padding="none" flush>
                    <x-slot:actions>
                        <x-button size="sm" variant="ghost" iconRight="arrow-right" href="{{ route('tasks.index', ['mineOnly' => '1']) }}" wire:navigate>
                            All mine
                        </x-button>
                    </x-slot:actions>

                    @if ($myTasksDueToday->isEmpty())
                        <div class="px-6 py-10 text-center">
                            <p class="text-sm font-medium text-ink">Nothing due today</p>
                            <p class="mt-1 text-xs text-muted">Tasks assigned to you with today’s due date show up here.</p>
                        </div>
                    @else
                        <ul class="divide-y divide-line">
                            @foreach ($myTasksDueToday as $task)
                                <li>
                                    <a
                                        href="{{ route('tasks.show', $task) }}"
                                        wire:navigate
                                        class="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-subtle sm:px-6"
                                    >
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm font-medium text-ink">{{ $task->title }}</span>
                                            <span class="block truncate font-mono text-[10px] text-faint">{{ $task->project?->domain }}</span>
                                        </span>
                                        <x-badge :tone="match ($task->status->value) {
                                            'approved' => 'success',
                                            'rejected' => 'danger',
                                            'submitted' => 'warn',
                                            default => 'neutral',
                                        }" size="sm">{{ $task->status->label() }}</x-badge>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-card>
            @endif
        </div>
    @endif

    @if ($canSeeTeamAttendance)
        <x-card title="Team attendance today" icon="attendance" :subtitle="$today->format('D j M')">
            <x-slot:actions>
                @if ($canSeePeople)
                    <x-button size="sm" variant="ghost" iconRight="arrow-right" href="{{ route('people.attendance') }}" wire:navigate>
                        Day sheet
                    </x-button>
                @endif
            </x-slot:actions>

            <ul class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($teamAttendanceToday as $row)
                    <li class="flex items-center gap-2.5 rounded-lg border border-line px-2.5 py-2">
                        <x-avatar :name="$row['name']" size="sm" />
                        <span class="min-w-0 flex-1 truncate text-sm text-ink-soft">{{ $row['name'] }}</span>
                        @if ($row['status'] === 'present')
                            <x-badge :tone="$row['is_late'] ? 'warn' : 'success'" dot size="sm">{{ $row['is_late'] ? 'Late' : 'Present' }}</x-badge>
                        @elseif ($row['status'] === 'leave')
                            <x-badge tone="warn" dot size="sm">Leave</x-badge>
                        @elseif ($row['status'] === 'holiday')
                            <x-badge tone="info" dot size="sm">Holiday</x-badge>
                        @else
                            <x-badge tone="neutral" dot size="sm">{{ $row['status_label'] }}</x-badge>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-card>
    @elseif ($canSeePeople)
        <x-card title="My people screens" icon="people" padding="sm">
            <div class="flex flex-wrap gap-2">
                <x-button size="sm" variant="secondary" icon="worklogs" href="{{ route('people.work-logs') }}" wire:navigate>Work log</x-button>
                <x-button size="sm" variant="secondary" icon="scorecard" href="{{ route('people.scorecard') }}" wire:navigate>My scorecard</x-button>
                <x-button size="sm" variant="secondary" icon="attendance" href="{{ route('people.attendance') }}" wire:navigate>Attendance</x-button>
            </div>
        </x-card>
    @endif

    @if ($canViewProjects)
        <div class="grid gap-4 lg:grid-cols-2">
            <x-card title="Portfolio mix" icon="projects" :subtitle="$totalProjects.' sites · '.$openTasksPortfolio.' open tasks'">
                <x-slot:actions>
                    <x-button size="sm" variant="ghost" iconRight="arrow-right" href="{{ route('projects.index') }}" wire:navigate>
                        All projects
                    </x-button>
                </x-slot:actions>

                @if ($totalProjects === 0)
                    <p class="py-6 text-center text-sm text-muted">No sites in your portfolio yet.</p>
                @else
                    <ul class="space-y-3">
                        @foreach ($statusOptions as $value => $label)
                            @php $count = (int) ($byStatus[$value] ?? 0); @endphp
                            <li>
                                <x-progress
                                    :value="$count"
                                    :max="max(1, $totalProjects)"
                                    :label="$label"
                                    :caption="$count"
                                    :tone="match ($value) {
                                        'monetized' => 'success',
                                        'paused', 'sold' => 'warn',
                                        'live' => 'accent',
                                        default => 'neutral',
                                    }"
                                />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>

            <x-card title="Recently touched" icon="clock" padding="none" flush>
                @if ($recentProjects->isEmpty())
                    <div class="px-6 py-10 text-center">
                        <p class="text-sm font-medium text-ink">No projects yet</p>
                        <p class="mt-1 text-xs text-muted">Add a site and its setup checklist is generated for you.</p>
                    </div>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($recentProjects as $project)
                            <li>
                                <a
                                    href="{{ route('projects.show', $project) }}"
                                    wire:navigate
                                    class="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-subtle sm:px-6"
                                >
                                    <span class="min-w-0 flex-1 truncate font-mono text-sm text-ink">{{ $project->domain }}</span>
                                    <span class="shrink-0 font-mono text-[10px] text-faint tabular-nums">{{ $recentOpenTasks[$project->id] ?? 0 }} open</span>
                                    <x-badge size="sm" :tone="match ($project->status->value) {
                                        'monetized' => 'success',
                                        'paused', 'sold' => 'warn',
                                        default => 'accent',
                                    }">{{ $project->status->label() }}</x-badge>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    @elseif ($hasAnything)
        <x-empty-state
            icon="projects"
            title="No portfolio access"
            description="You do not have projects.view yet. Ask an admin for a role assignment and the portfolio panels will appear here."
        />
    @endif

    @if ($canViewCredentials)
        <x-card
            title="Expiring soon"
            icon="credentials"
            :subtitle="'Credentials inside the '.implode(', ', $thresholds).'-day alert windows, including ones already expired.'"
            padding="none"
            flush
        >
            @if ($expiring->isEmpty())
                <div class="px-6 py-10 text-center">
                    <p class="text-sm font-medium text-ink">Nothing expiring</p>
                    <p class="mt-1 text-xs text-muted">Every credential with a date is outside the alert window.</p>
                </div>
            @else
                <x-table
                    flush
                    :headers="[
                        'Credential',
                        'Project',
                        ['label' => 'Expires', 'align' => 'right'],
                        ['label' => 'Window', 'align' => 'right'],
                    ]"
                >
                    @foreach ($expiring as $credential)
                        @php
                            $days = $credential->daysUntilExpiry();
                            $urgency = $credential->expiryUrgency($thresholds);
                        @endphp
                        <x-table.row>
                            <x-table.cell>
                                <p class="font-medium text-ink">{{ $credential->label }}</p>
                                <p class="font-mono text-[10px] text-faint">{{ $credential->type->label() }}</p>
                            </x-table.cell>
                            <x-table.cell>
                                @if ($credential->project)
                                    <a href="{{ route('projects.show', $credential->project) }}" wire:navigate class="font-mono text-xs text-accent hover:underline">
                                        {{ $credential->project->domain }}
                                    </a>
                                @else
                                    <span class="text-faint">—</span>
                                @endif
                            </x-table.cell>
                            <x-table.cell numeric muted>
                                {{ $credential->expires_on?->timezone($tz)->format('Y-m-d') }}
                            </x-table.cell>
                            <x-table.cell align="right">
                                @if ($days !== null && $days < 0)
                                    <x-badge tone="danger" size="sm">Expired {{ abs($days) }}d</x-badge>
                                @elseif ($urgency === '7' || ($days !== null && $days <= 7))
                                    <x-badge tone="danger" size="sm">{{ $days }}d</x-badge>
                                @elseif ($urgency === '14' || ($days !== null && $days <= 14))
                                    <x-badge tone="warn" size="sm">{{ $days }}d</x-badge>
                                @else
                                    <x-badge tone="neutral" size="sm">{{ $days }}d</x-badge>
                                @endif
                            </x-table.cell>
                        </x-table.row>
                    @endforeach
                </x-table>
            @endif
        </x-card>
    @endif

    @if (\App\Support\AiAvailability::enabled())
        <livewire:ai.ask :compact="true" />
    @endif
</div>
