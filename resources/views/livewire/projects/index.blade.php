@php
    $canBulk = (bool) auth()->user()?->hasPermission('projects.update');
@endphp

<div class="space-y-5">
    <x-page-header
        title="Projects"
        subtitle="Every site in the portfolio with its ownership, status and month-to-date figures."
        :breadcrumbs="[['label' => 'Work'], ['label' => 'Projects']]"
    >
        <x-slot:actions>
            @can('create', App\Models\Project::class)
                <x-button icon="plus" wire:click="create">New project</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar target="search,statusFilter">
        <x-input
            type="search"
            size="sm"
            icon="search"
            data-page-search
            class="min-w-[12rem] flex-1 sm:max-w-xs"
            wire:model.live.debounce.300ms="search"
            placeholder="Search domain, niche, CMS…"
            aria-label="Search projects"
        />

        <x-select size="sm" class="w-auto" wire:model.live="statusFilter" placeholder="All statuses" aria-label="Filter by status">
            @foreach ($statusOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </x-select>

        <x-slot:trailing>
            {{ $projects->total() }} {{ \Illuminate\Support\Str::plural('site', $projects->total()) }}
        </x-slot:trailing>
    </x-filter-bar>

    <x-modal
        :show="$showForm"
        :title="$editingProjectId ? 'Edit project' : 'New project'"
        subtitle="Ownership shares must total 100% before a project can be saved."
        close="cancel"
        size="lg"
    >
        <form wire:submit="save" id="project-form" class="grid gap-4 sm:grid-cols-2">
            <x-input label="Domain" wire:model="domain" :error="$errors->first('domain')" placeholder="example.com" required />
            <x-input label="Niche" wire:model="niche" :error="$errors->first('niche')" />
            <x-input label="CMS" wire:model="cms" :error="$errors->first('cms')" placeholder="WordPress" />
            <x-input label="Start date" type="date" wire:model="start_date" :error="$errors->first('start_date')" />
            <x-input
                label="Acquisition cost"
                type="number"
                step="0.01"
                min="0"
                :suffix="\App\Support\Currency::code()"
                wire:model="acquisition_cost"
                :error="$errors->first('acquisition_cost')"
                hint="Stored as integer minor units."
            />
            <x-select label="Status" wire:model="status" :error="$errors->first('status')">
                @foreach ($statusOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-select>

            <x-textarea class="sm:col-span-2" label="Notes" wire:model="notes" rows="3" :error="$errors->first('notes')" />

            @if ($canManageOwnership || ! $editingProjectId)
                <div class="sm:col-span-2">
                    <div class="mb-2 flex items-center justify-between">
                        <span class="text-xs font-medium text-ink-soft">Ownership · must total 100%</span>
                        <x-button type="button" size="xs" variant="ghost" icon="plus" wire:click="addOwnerRow">Add owner</x-button>
                    </div>

                    <div class="space-y-2">
                        @foreach ($owners as $index => $owner)
                            <div class="grid gap-2 sm:grid-cols-[1fr_7rem_auto]" wire:key="owner-{{ $index }}">
                                <x-select size="sm" wire:model="owners.{{ $index }}.user_id" placeholder="Select user…" aria-label="Owner">
                                    @foreach ($users as $user)
                                        <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                                    @endforeach
                                </x-select>

                                <x-input
                                    size="sm"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    max="100"
                                    suffix="%"
                                    wire:model="owners.{{ $index }}.share_percent"
                                    placeholder="0"
                                    aria-label="Share percent"
                                />

                                <x-button type="button" size="sm" square variant="danger-ghost" wire:click="removeOwnerRow({{ $index }})" aria-label="Remove owner">
                                    <x-icon name="trash" class="size-3.5" />
                                </x-button>
                            </div>
                        @endforeach
                    </div>

                    @error('owners') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    @error('owners.*') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
            @endif
        </form>

        <x-slot:footer>
            <x-button variant="ghost" wire:click="cancel">Cancel</x-button>
            <x-button type="submit" form="project-form" target="save">Save project</x-button>
        </x-slot:footer>
    </x-modal>

    <div wire:loading.delay.long.flex wire:target="search,statusFilter" class="hidden">
        <x-skeleton variant="table" class="w-full" :rows="5" :cols="6" />
    </div>

    @if ($projects->isEmpty())
        <x-empty-state
            icon="projects"
            title="No projects match"
            description="Add your first domain and its setup checklist, credential vault and ownership split are created with it."
        >
            @can('create', App\Models\Project::class)
                <x-button icon="plus" wire:click="create">New project</x-button>
            @endcan
        </x-empty-state>
    @else
        <div wire:loading.class="opacity-60" wire:target="search,statusFilter" class="transition-opacity duration-150">
            <x-table
                :headers="array_values(array_filter([
                    $canBulk ? ['label' => 'Select', 'sr' => true, 'width' => 'w-10'] : null,
                    'Domain',
                    'Status',
                    ['label' => 'Revenue', 'align' => 'right'],
                    ['label' => 'Cost', 'align' => 'right'],
                    ['label' => 'Profit', 'align' => 'right'],
                    ['label' => 'Open', 'align' => 'right'],
                    ['label' => 'Acquired', 'align' => 'right'],
                    ['label' => 'Actions', 'sr' => true],
                ]))"
            >
                @foreach ($projects as $project)
                    <x-table.row wire:key="project-{{ $project->id }}" :selected="in_array($project->id, $selectedIds)">
                        @if ($canBulk)
                            <x-table.cell tight>
                                <x-checkbox value="{{ $project->id }}" wire:model.live="selectedIds" aria-label="Select {{ $project->domain }}" />
                            </x-table.cell>
                        @endif

                        <x-table.cell>
                            <a href="{{ route('projects.show', $project) }}" wire:navigate class="font-mono text-sm font-medium text-ink hover:text-accent">
                                {{ $project->domain }}
                            </a>
                            @if ($project->niche)
                                <p class="text-xs text-muted">{{ $project->niche }}</p>
                            @endif
                        </x-table.cell>

                        <x-table.cell nowrap>
                            <x-badge size="sm" :tone="match ($project->status->value) {
                                'monetized' => 'success',
                                'paused', 'sold' => 'warn',
                                default => 'accent',
                            }">{{ $project->status->label() }}</x-badge>
                        </x-table.cell>

                        @php($stats = $monthStats[$project->id] ?? ['revenue_paisa' => 0, 'cost_paisa' => 0, 'profit_paisa' => 0, 'open_tasks' => 0])

                        <x-table.cell numeric muted>{{ \App\Support\Money::rounded($stats['revenue_paisa']) }}</x-table.cell>
                        <x-table.cell numeric muted>{{ \App\Support\Money::rounded($stats['cost_paisa']) }}</x-table.cell>
                        <x-table.cell numeric>
                            <x-money :paisa="$stats['profit_paisa']" signed />
                        </x-table.cell>
                        <x-table.cell numeric muted>{{ $stats['open_tasks'] }}</x-table.cell>
                        <x-table.cell numeric muted>{{ \App\Support\Money::rounded((int) $project->acquisition_cost_paisa) }}</x-table.cell>

                        <x-table.cell align="right" nowrap>
                            <div class="flex justify-end gap-0.5">
                                <x-tooltip text="Open">
                                    <x-button size="sm" square variant="ghost" href="{{ route('projects.show', $project) }}" wire:navigate aria-label="Open {{ $project->domain }}">
                                        <x-icon name="arrow-right" class="size-3.5" />
                                    </x-button>
                                </x-tooltip>

                                @can('update', $project)
                                    <x-tooltip text="Edit">
                                        <x-button size="sm" square variant="ghost" wire:click="edit({{ $project->id }})" aria-label="Edit {{ $project->domain }}">
                                            <x-icon name="pencil" class="size-3.5" />
                                        </x-button>
                                    </x-tooltip>
                                @endcan

                                @can('delete', $project)
                                    <x-tooltip text="Archive">
                                        <x-button size="sm" square variant="danger-ghost" wire:click="delete({{ $project->id }})" wire:confirm="Archive this project?" aria-label="Archive {{ $project->domain }}">
                                            <x-icon name="trash" class="size-3.5" />
                                        </x-button>
                                    </x-tooltip>
                                @endcan
                            </div>
                        </x-table.cell>
                    </x-table.row>
                @endforeach
            </x-table>
        </div>

        {{ $projects->links() }}
    @endif

    @if ($canBulk)
        <x-bulk-bar :count="count($selectedIds)">
            <x-select size="sm" class="w-auto" wire:model="bulkStatus" placeholder="Set status…" aria-label="Bulk status">
                @foreach ($statusOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-select>
            <x-button size="sm" variant="secondary" wire:click="applyBulkStatus" wire:confirm="Apply status to selected projects?">Apply</x-button>
        </x-bulk-bar>
    @endif
</div>
