@php
    $canUpdate = (bool) auth()->user()?->hasPermission('settings.update');
@endphp

<div class="space-y-6">
    <x-page-header
        title="Settings"
        subtitle="Defaults used across money, display time, attendance and security. Stored timestamps always stay UTC."
        :breadcrumbs="[['label' => 'Workspace']]"
    >
        <x-slot:meta>
            <x-badge tone="neutral" size="sm">{{ $base_currency }}</x-badge>
            <x-badge tone="neutral" size="sm">{{ $display_timezone }}</x-badge>
            @if (\App\Support\AiAvailability::enabled())
                <x-badge tone="success" size="sm" dot>AI enabled</x-badge>
            @else
                <x-badge tone="neutral" size="sm" dot>AI hidden</x-badge>
            @endif
        </x-slot:meta>
        <x-slot:actions>
            @if (auth()->user()?->hasPermission('task_templates.manage'))
                <x-button variant="secondary" icon="templates" href="{{ route('settings.task-templates') }}" wire:navigate>Task templates</x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <form wire:submit="save" class="max-w-2xl space-y-6">
        <x-card title="General" subtitle="Organisation identity, reporting currency and display time." icon="settings">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input class="sm:col-span-2" label="Organization name" wire:model="org_name" :error="$errors->first('org_name')" required />

                <x-input
                    label="Base currency"
                    wire:model="base_currency"
                    :error="$errors->first('base_currency')"
                    hint="ISO 4217 code, e.g. USD. Amounts are stored as integer minor units with {{ \App\Support\Currency::exponent() }} decimal place(s), set in config/money.php."
                    required
                />

                <x-input
                    label="Currency symbol"
                    wire:model="currency_symbol"
                    :error="$errors->first('currency_symbol')"
                    hint="Optional glyph shown alongside amounts, e.g. $ or €."
                />

                <x-input
                    label="Display timezone"
                    wire:model="display_timezone"
                    :error="$errors->first('display_timezone')"
                    hint="IANA name, e.g. Europe/Berlin. Stored timestamps remain UTC."
                    required
                />

                <x-input
                    type="number"
                    label="Late arrival hour (local)"
                    wire:model="late_arrival_hour"
                    :error="$errors->first('late_arrival_hour')"
                    :hint="'First login after this hour (0–23) counts as late, measured in '.\App\Support\DisplayTimezone::name().'.'"
                    min="0"
                    max="23"
                />
            </div>
        </x-card>

        <x-card title="Two-factor authentication" subtitle="Not implemented yet — this records an intention, nothing more." icon="credentials">
            <div class="mb-4 rounded-lg border border-warning/30 bg-warning/5 px-3 py-2 text-sm text-muted">
                <span class="font-medium text-ink">No challenge screen exists.</span>
                The database columns and this preference are in place, but sign-in does
                <span class="font-medium">not</span> ask for a second factor. Turning this on
                protects nothing today.
            </div>

            <x-checkbox
                wire:model="two_factor_required"
                label="Require two-factor authentication once implemented"
                hint="Stored as a preference for a future release."
            />
        </x-card>

        <x-card title="FX defaults" :subtitle="'Default rate pre-filled when recording new '.\App\Support\Currency::sourceCode().' revenue.'" icon="revenue">
            <div class="space-y-4">
                <x-input
                    :label="\App\Support\Currency::sourceCode().' → '.\App\Support\Currency::code().' rate'"
                    wire:model="fx_rate"
                    :error="$errors->first('fx_rate')"
                    :suffix="\App\Support\Currency::code()"
                    hint="Each revenue row freezes its own rate and converted amount, so changing this never rewrites history."
                />

                <x-textarea
                    label="Note"
                    wire:model="fx_note"
                    rows="3"
                    :error="$errors->first('fx_note')"
                    hint="Shown next to multi-currency inputs."
                />
            </div>
        </x-card>

        <x-card title="AI spend cap" subtitle="Soft monthly budget for Ask-your-data and helpers, estimated from token use." icon="ai">
            <div class="space-y-4">
                <x-input
                    type="number"
                    label="Monthly budget (USD cents)"
                    wire:model="ai_monthly_budget_cents"
                    :error="$errors->first('ai_monthly_budget_cents')"
                    hint="Example: 2000 = $20.00. Leave keyed env AI_MONTHLY_BUDGET_CENTS as the default seed."
                    min="0"
                />

                @if (\App\Support\AiAvailability::enabled())
                    <p class="flex items-center gap-1.5 text-xs text-success">
                        <x-icon name="check-circle" class="size-3.5" />
                        AI is currently enabled (API key present).
                    </p>
                @else
                    <p class="flex items-center gap-1.5 text-xs text-muted">
                        <x-icon name="info" class="size-3.5" />
                        AI is hidden — set AI_API_KEY or OPENAI_API_KEY / ANTHROPIC_API_KEY to enable.
                    </p>
                @endif
            </div>
        </x-card>

        <x-card title="Credential expiry alerts" subtitle="Drives the dashboard widget and credentials:check-expiry." icon="credentials">
            <div class="space-y-4">
                <x-input
                    label="Threshold days"
                    wire:model="credential_expiry_thresholds"
                    :error="$errors->first('credential_expiry_thresholds')"
                    hint="Comma-separated. Default 30,14,7."
                />

                <x-input
                    label="Extra notify emails"
                    wire:model="credential_expiry_notify_emails"
                    :error="$errors->first('credential_expiry_notify_emails')"
                    hint="Optional comma-separated list. Admins are always included when --notify runs."
                />
            </div>
        </x-card>

        @if ($canUpdate)
            <div class="sticky bottom-20 z-10 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line bg-raised/95 px-4 py-3 shadow-pop backdrop-blur sm:bottom-4">
                <p class="text-xs text-muted">Changes apply everywhere as soon as you save.</p>
                <x-button type="submit" target="save">Save settings</x-button>
            </div>
        @else
            <p class="text-xs text-muted">You have read-only access to settings.</p>
        @endif
    </form>
</div>
