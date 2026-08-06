@php
    $tz = \App\Support\DisplayTimezone::name();
@endphp

<div class="space-y-5">
    <x-page-header
        :title="$project->domain"
        :subtitle="$project->notes ? \Illuminate\Support\Str::limit($project->notes, 120) : null"
        :breadcrumbs="[
            ['label' => 'Work'],
            ['label' => 'Projects', 'href' => route('projects.index')],
            ['label' => $project->domain],
        ]"
        :back="route('projects.index')"
    >
        <x-slot:meta>
            <x-badge :tone="match ($project->status->value) {
                'monetized' => 'success',
                'paused', 'sold' => 'warn',
                default => 'accent',
            }" dot>{{ $project->status->label() }}</x-badge>

            @if ($project->cms)
                <x-badge tone="neutral">{{ $project->cms }}</x-badge>
            @endif

            @if ($project->niche)
                <span class="text-xs text-muted">{{ $project->niche }}</span>
            @endif
        </x-slot:meta>

        <x-slot:actions>
            @if (auth()->user()?->hasPermission('tasks.view'))
                <x-button size="sm" variant="secondary" icon="tasks" href="{{ route('tasks.index', ['projectFilter' => $project->id]) }}" wire:navigate>Tasks</x-button>
            @endif
            @if (auth()->user()?->hasPermission('articles.view'))
                <x-button size="sm" variant="secondary" icon="articles" href="{{ route('articles.index', ['projectFilter' => $project->id]) }}" wire:navigate>Articles</x-button>
            @endif
            @if (auth()->user()?->hasPermission('links.view'))
                <x-button size="sm" variant="secondary" icon="links" href="{{ route('links.index', ['projectFilter' => $project->id]) }}" wire:navigate>Links</x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Revenue this month" :value="\App\Support\Money::rounded($monthStats['revenue_paisa'])" :hint="\App\Support\Currency::code()" icon="revenue" tone="accent" />
        <x-stat label="Cost this month" :value="\App\Support\Money::rounded($monthStats['cost_paisa'])" :hint="\App\Support\Currency::code()" icon="expenses" />
        <x-stat
            label="Profit this month"
            :value="\App\Support\Money::rounded($monthStats['profit_paisa'])"
            :hint="\App\Support\Currency::code()"
            icon="pnl"
            :tone="$monthStats['profit_paisa'] >= 0 ? 'success' : 'danger'"
        />
        <x-stat label="Open tasks" :value="$monthStats['open_tasks']" icon="tasks" hint="not yet approved" />
    </div>

    {{-- Details --}}
    <x-card title="Details" icon="projects">
        <x-slot:actions>
            @can('update', $project)
                @if (! $editingDetails)
                    <x-button size="sm" variant="ghost" icon="pencil" wire:click="startEditDetails">Edit</x-button>
                @endif
            @endcan
        </x-slot:actions>

        @if ($editingDetails)
            <form wire:submit="saveDetails" class="grid gap-4 sm:grid-cols-2">
                <x-input label="Domain" wire:model="domain" :error="$errors->first('domain')" />
                <x-input label="Niche" wire:model="niche" :error="$errors->first('niche')" />
                <x-input label="CMS" wire:model="cms" :error="$errors->first('cms')" />
                <x-input label="Start date" type="date" wire:model="start_date" :error="$errors->first('start_date')" />
                <x-input label="Acquisition cost" type="number" step="0.01" min="0" :suffix="\App\Support\Currency::code()" wire:model="acquisition_cost" :error="$errors->first('acquisition_cost')" />
                <x-select label="Status" wire:model="status" :error="$errors->first('status')">
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-select>
                <x-textarea class="sm:col-span-2" label="Notes" wire:model="notes" rows="4" :error="$errors->first('notes')" />

                <div class="flex gap-2 sm:col-span-2">
                    <x-button type="submit" target="saveDetails">Save details</x-button>
                    <x-button type="button" variant="ghost" wire:click="cancelEditDetails">Cancel</x-button>
                </div>
            </form>
        @else
            <dl class="grid gap-4 text-sm sm:grid-cols-3">
                <div>
                    <dt class="font-mono text-eyebrow text-faint uppercase">Start date</dt>
                    <dd class="mt-1 font-mono text-xs text-ink-soft tabular-nums">
                        {{ $project->start_date?->timezone($tz)->format('Y-m-d') ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="font-mono text-eyebrow text-faint uppercase">Acquisition cost</dt>
                    <dd class="mt-1"><x-money :paisa="$project->acquisition_cost_paisa" :currency="\App\Support\Currency::code()" /></dd>
                </div>
                <div>
                    <dt class="font-mono text-eyebrow text-faint uppercase">CMS</dt>
                    <dd class="mt-1 text-xs text-ink-soft">{{ $project->cms ?: '—' }}</dd>
                </div>
                <div class="sm:col-span-3">
                    <dt class="font-mono text-eyebrow text-faint uppercase">Notes</dt>
                    <dd class="mt-1 text-sm whitespace-pre-wrap text-ink-soft">{{ $project->notes ?: '—' }}</dd>
                </div>
            </dl>
        @endif
    </x-card>

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- Ownership --}}
        <x-card title="Ownership" subtitle="Shares must total 100% for this project." icon="partners">
            <x-slot:actions>
                @can('manageOwnership', $project)
                    @if (! $editingOwnership)
                        <x-button size="sm" variant="ghost" icon="pencil" wire:click="startEditOwnership">Edit shares</x-button>
                    @endif
                @endcan
            </x-slot:actions>

            @if ($editingOwnership)
                <div class="space-y-2">
                    @foreach ($owners as $index => $owner)
                        <div class="grid gap-2 sm:grid-cols-[1fr_7rem_auto]" wire:key="own-{{ $index }}">
                            <x-select size="sm" wire:model="owners.{{ $index }}.user_id" placeholder="Select user…" aria-label="Owner">
                                @foreach ($users as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                @endforeach
                            </x-select>
                            <x-input size="sm" type="number" step="0.01" min="0" max="100" suffix="%" wire:model="owners.{{ $index }}.share_percent" placeholder="0" aria-label="Share percent" />
                            <x-button type="button" size="sm" square variant="danger-ghost" wire:click="removeOwnerRow({{ $index }})" aria-label="Remove owner">
                                <x-icon name="trash" class="size-3.5" />
                            </x-button>
                        </div>
                    @endforeach

                    @error('owners') <p class="text-xs text-danger">{{ $message }}</p> @enderror

                    <div class="flex flex-wrap gap-2 pt-2">
                        <x-button size="sm" variant="secondary" icon="plus" wire:click="addOwnerRow">Add owner</x-button>
                        <x-button size="sm" wire:click="saveOwnership">Save ownership</x-button>
                        <x-button size="sm" variant="ghost" wire:click="cancelOwnership">Cancel</x-button>
                    </div>
                </div>
            @else
                @forelse ($project->owners as $owner)
                    <div class="flex items-center gap-3 border-b border-line py-2 last:border-b-0">
                        <x-avatar :name="$owner->name" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm text-ink">{{ $owner->name }}</span>
                            <span class="block truncate font-mono text-[10px] text-faint">{{ $owner->email }}</span>
                        </span>
                        <span class="font-mono text-xs font-medium text-ink tabular-nums">{{ number_format($owner->pivot->share_bps / 100, 2) }}%</span>
                    </div>
                @empty
                    <p class="py-4 text-sm text-muted">No ownership recorded yet — add shares so distributions can be calculated.</p>
                @endforelse
            @endif
        </x-card>

        {{-- Team --}}
        <x-card title="Team" subtitle="Who can see and work on this project." icon="people">
            <x-slot:actions>
                @can('manageTeam', $project)
                    @if (! $editingTeam)
                        <x-button size="sm" variant="ghost" icon="pencil" wire:click="startEditTeam">Edit team</x-button>
                    @endif
                @endcan
            </x-slot:actions>

            @if ($editingTeam)
                <div class="grid gap-1.5 sm:grid-cols-2">
                    @foreach ($users as $user)
                        <x-checkbox
                            wire:key="team-{{ $user->id }}"
                            class="rounded-lg border border-line px-2.5 py-2"
                            value="{{ $user->id }}"
                            wire:model="teamMemberIds"
                            :label="$user->name"
                        />
                    @endforeach
                </div>
                <div class="mt-4 flex gap-2">
                    <x-button size="sm" wire:click="saveTeam">Save team</x-button>
                    <x-button size="sm" variant="ghost" wire:click="cancelTeam">Cancel</x-button>
                </div>
            @else
                <div class="flex flex-wrap gap-1.5">
                    @forelse ($project->teamMembers as $member)
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-line py-1 pr-2.5 pl-1">
                            <x-avatar :name="$member->name" size="xs" />
                            <span class="text-xs text-ink-soft">{{ $member->name }}</span>
                        </span>
                    @empty
                        <p class="text-sm text-muted">No team members assigned — only admins and owners can see this project.</p>
                    @endforelse
                </div>
            @endif
        </x-card>
    </div>

    {{-- Credentials vault --}}
    <x-card
        title="Credentials vault"
        subtitle="Secrets are encrypted at rest and every reveal is audit-logged."
        icon="credentials"
        padding="none"
        flush
    >
        <x-slot:actions>
            @can('create', [App\Models\Credential::class, $project])
                <x-button size="sm" icon="plus" wire:click="createCredential">Add credential</x-button>
            @endcan
        </x-slot:actions>

        @if ($showCredentialForm)
            <form wire:submit="saveCredential" class="grid gap-4 border-b border-line bg-subtle/50 px-4 py-5 sm:grid-cols-2 sm:px-6">
                <x-select label="Type" wire:model="cred_type" :error="$errors->first('cred_type')">
                    @foreach ($credentialTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-select>
                <x-input label="Label" wire:model="cred_label" :error="$errors->first('cred_label')" />
                <x-input label="Username / login" wire:model="cred_username" :error="$errors->first('cred_username')" />
                <x-input
                    label="Secret / password"
                    type="password"
                    wire:model="cred_secret"
                    :error="$errors->first('cred_secret')"
                    :hint="$editingCredentialId ? 'Leave blank to keep the existing secret.' : null"
                />
                <x-input label="URL" wire:model="cred_url" :error="$errors->first('cred_url')" />
                <x-input
                    label="Expires on"
                    type="date"
                    wire:model="cred_expires_on"
                    :error="$errors->first('cred_expires_on')"
                    hint="Required for registrar, hosting and SSL renewals."
                />
                <x-textarea
                    class="sm:col-span-2"
                    label="Metadata (JSON)"
                    wire:model="cred_metadata_json"
                    rows="3"
                    :error="$errors->first('cred_metadata_json')"
                    placeholder='{"auto_renew": true, "transfer_lock": true}'
                />

                <div class="flex gap-2 sm:col-span-2">
                    <x-button type="submit" target="saveCredential">Save credential</x-button>
                    <x-button type="button" variant="ghost" wire:click="cancelCredentialForm">Cancel</x-button>
                </div>
            </form>
        @endif

        @if ($credentials->isEmpty())
            <div class="px-6 py-10 text-center">
                <p class="text-sm font-medium text-ink">Vault is empty</p>
                <p class="mt-1 text-xs text-muted">Store registrar, hosting, CMS and analytics logins here so renewals never surprise you.</p>
            </div>
        @else
            <x-table
                flush
                :headers="[
                    'Credential',
                    'Username',
                    'Secret',
                    'URL',
                    ['label' => 'Expires', 'align' => 'right'],
                    ['label' => 'Actions', 'sr' => true],
                ]"
            >
                @foreach ($credentials as $credential)
                    @php
                        $isRevealed = isset($revealed[$credential->id]);
                        $days = $credential->daysUntilExpiry();
                    @endphp
                    <x-table.row wire:key="cred-{{ $credential->id }}">
                        <x-table.cell>
                            <p class="text-sm font-medium text-ink">{{ $credential->label }}</p>
                            <p class="font-mono text-[10px] text-faint">{{ $credential->type->label() }}</p>
                        </x-table.cell>

                        <x-table.cell mono>
                            {{ $isRevealed ? ($revealed[$credential->id]['username'] ?: '—') : $credential->maskedUsername() }}
                        </x-table.cell>

                        <x-table.cell mono>
                            {{ $isRevealed ? ($revealed[$credential->id]['secret'] ?: '—') : $credential->maskedSecret() }}
                        </x-table.cell>

                        <x-table.cell>
                            @if ($credential->url)
                                <a href="{{ $credential->url }}" target="_blank" rel="noopener" class="inline-flex max-w-[14rem] items-center gap-1 truncate text-xs text-accent hover:underline">
                                    <span class="truncate">{{ $credential->url }}</span>
                                    <x-icon name="external" class="size-3 shrink-0" />
                                </a>
                            @else
                                <span class="text-faint">—</span>
                            @endif
                        </x-table.cell>

                        <x-table.cell numeric>
                            <span class="text-ink-soft">{{ $credential->expires_on?->format('Y-m-d') ?? '—' }}</span>
                            @if ($days !== null)
                                <span class="ml-1 {{ $days <= 7 ? 'text-danger' : ($days <= 14 ? 'text-warn' : 'text-faint') }}">{{ $days }}d</span>
                            @endif
                        </x-table.cell>

                        <x-table.cell align="right" nowrap>
                            <div class="flex justify-end gap-0.5">
                                @can('reveal', $credential)
                                    @if ($isRevealed)
                                        <x-tooltip text="Hide">
                                            <x-button size="sm" square variant="ghost" wire:click="hideCredential({{ $credential->id }})" aria-label="Hide secret">
                                                <x-icon name="eye-off" class="size-3.5" />
                                            </x-button>
                                        </x-tooltip>
                                    @else
                                        <x-tooltip text="Reveal (audited)">
                                            <x-button size="sm" square variant="ghost" wire:click="revealCredential({{ $credential->id }})" wire:confirm="Reveal secret? This action is audited." aria-label="Reveal secret">
                                                <x-icon name="eye" class="size-3.5" />
                                            </x-button>
                                        </x-tooltip>
                                    @endif
                                @endcan

                                @can('update', $credential)
                                    <x-tooltip text="Edit">
                                        <x-button size="sm" square variant="ghost" wire:click="editCredential({{ $credential->id }})" aria-label="Edit credential">
                                            <x-icon name="pencil" class="size-3.5" />
                                        </x-button>
                                    </x-tooltip>
                                @endcan

                                @can('delete', $credential)
                                    <x-tooltip text="Delete">
                                        <x-button size="sm" square variant="danger-ghost" wire:click="deleteCredential({{ $credential->id }})" wire:confirm="Soft-delete this credential?" aria-label="Delete credential">
                                            <x-icon name="trash" class="size-3.5" />
                                        </x-button>
                                    </x-tooltip>
                                @endcan
                            </div>
                        </x-table.cell>
                    </x-table.row>
                @endforeach
            </x-table>
        @endif
    </x-card>

    {{-- Files --}}
    <x-card title="Files" subtitle="Contracts, invoices and handover documents." icon="worklogs">
        @can('update', $project)
            <form wire:submit="uploadFile" class="mb-5 space-y-3">
                <x-file-input
                    wire:model="upload"
                    :filename="$upload?->getClientOriginalName()"
                    :error="$errors->first('upload')"
                    hint="Project files and documents"
                />
                <div class="flex justify-end">
                    <x-button type="submit" size="sm" icon="upload" target="uploadFile">Upload</x-button>
                </div>
            </form>
        @endcan

        @if ($project->media->isEmpty())
            <p class="text-sm text-muted">No files attached yet.</p>
        @else
            <ul class="divide-y divide-line">
                @foreach ($project->media as $file)
                    <li class="flex items-center gap-3 py-2">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-line bg-subtle text-muted">
                            <x-icon name="articles" class="size-4" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm text-ink">{{ $file->original_name }}</span>
                            <span class="block font-mono text-[10px] text-faint tabular-nums">{{ number_format($file->size / 1024, 1) }} KB</span>
                        </span>
                        @can('update', $project)
                            <x-tooltip text="Remove">
                                <x-button size="sm" square variant="danger-ghost" wire:click="deleteMedia({{ $file->id }})" wire:confirm="Delete this file?" aria-label="Remove {{ $file->original_name }}">
                                    <x-icon name="trash" class="size-3.5" />
                                </x-button>
                            </x-tooltip>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
</div>
