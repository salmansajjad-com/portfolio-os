<?php

namespace App\Livewire\Projects;

use App\Enums\CredentialType;
use App\Enums\ProjectStatus;
use App\Models\Credential;
use App\Models\Project;
use App\Models\User;
use App\Services\CredentialVaultService;
use App\Services\ProfitAndLossService;
use App\Services\ProjectOwnershipService;
use App\Support\Money;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
#[Title('Project')]
class Show extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public Project $project;

    // Inline project fields
    public string $domain = '';

    public string $niche = '';

    public string $cms = '';

    public string $start_date = '';

    public string $acquisition_cost = '0';

    public string $status = 'setup';

    public string $notes = '';

    public bool $editingDetails = false;

    // Ownership
    /** @var array<int, array{user_id: string, share_percent: string}> */
    public array $owners = [];

    public bool $editingOwnership = false;

    // Team
    /** @var array<int, int> */
    public array $teamMemberIds = [];

    public bool $editingTeam = false;

    // Credentials form
    public bool $showCredentialForm = false;

    public ?int $editingCredentialId = null;

    public string $cred_type = 'other';

    public string $cred_label = '';

    public string $cred_username = '';

    public string $cred_secret = '';

    public string $cred_url = '';

    public string $cred_expires_on = '';

    public string $cred_metadata_json = '';

    /**
     * Ids the user has chosen to reveal this page view.
     *
     * Only ids live in component state. Livewire serialises every public property
     * into `wire:snapshot` and echoes it back on each request, so a decrypted
     * secret held here would be re-transmitted for the life of the page. The
     * plaintext is resolved per render instead — see `revealedSecrets()`.
     *
     * @var array<int, int>
     */
    #[Locked]
    public array $revealedIds = [];

    // Files
    public $upload;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project->load(['owners', 'teamMembers', 'credentials', 'media']);
        $this->fillFromProject();
    }

    protected function fillFromProject(): void
    {
        $this->domain = $this->project->domain;
        $this->niche = (string) $this->project->niche;
        $this->cms = (string) $this->project->cms;
        $this->start_date = $this->project->start_date?->format('Y-m-d') ?? '';
        $this->acquisition_cost = Money::fromMinor((int) $this->project->acquisition_cost_paisa);
        $this->status = $this->project->status->value;
        $this->notes = (string) $this->project->notes;
        $this->owners = $this->project->owners->map(fn (User $u) => [
            'user_id' => (string) $u->id,
            'share_percent' => number_format($u->pivot->share_bps / 100, 2, '.', ''),
        ])->values()->all();
        $this->teamMemberIds = $this->project->teamMembers->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function startEditDetails(): void
    {
        $this->authorize('update', $this->project);
        $this->editingDetails = true;
    }

    public function cancelEditDetails(): void
    {
        $this->fillFromProject();
        $this->editingDetails = false;
        $this->resetValidation();
    }

    public function saveDetails(): void
    {
        $this->authorize('update', $this->project);

        $validated = $this->validate([
            'domain' => ['required', 'string', 'max:255'],
            'niche' => ['nullable', 'string', 'max:255'],
            'cms' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'acquisition_cost' => ['required', 'numeric', 'min:0'],
            'status' => ['required', Rule::enum(ProjectStatus::class)],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $this->project->update([
            'domain' => $validated['domain'],
            'niche' => $validated['niche'] ?: null,
            'cms' => $validated['cms'] ?: null,
            'start_date' => $validated['start_date'] ?: null,
            'acquisition_cost_paisa' => Money::toMinor($validated['acquisition_cost']),
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?: null,
        ]);

        $this->project->refresh();
        $this->fillFromProject();
        $this->editingDetails = false;
        $this->dispatch('toast', message: 'Project details saved.', tone: 'success');
    }

    public function startEditOwnership(): void
    {
        $this->authorize('manageOwnership', $this->project);
        $this->editingOwnership = true;
    }

    public function addOwnerRow(): void
    {
        $this->owners[] = ['user_id' => '', 'share_percent' => ''];
    }

    public function removeOwnerRow(int $index): void
    {
        unset($this->owners[$index]);
        $this->owners = array_values($this->owners);
    }

    public function saveOwnership(ProjectOwnershipService $ownership): void
    {
        $this->authorize('manageOwnership', $this->project);

        $validated = $this->validate([
            'owners' => ['required', 'array', 'min:1'],
            'owners.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'owners.*.share_percent' => ['required', 'numeric', 'min:0.01', 'max:100'],
        ]);

        $ownerRows = collect($validated['owners'])
            ->map(fn (array $row) => [
                'user_id' => (int) $row['user_id'],
                'share_bps' => (int) round(((float) $row['share_percent']) * 100),
            ])
            ->all();

        try {
            $ownership->sync($this->project, $ownerRows);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $this->addError($key, $message);
                }
            }

            return;
        }

        $this->project->load('owners');
        $this->fillFromProject();
        $this->editingOwnership = false;
        $this->dispatch('toast', message: 'Ownership updated.', tone: 'success');
    }

    public function cancelOwnership(): void
    {
        $this->fillFromProject();
        $this->editingOwnership = false;
        $this->resetValidation();
    }

    public function startEditTeam(): void
    {
        $this->authorize('manageTeam', $this->project);
        $this->editingTeam = true;
    }

    public function saveTeam(): void
    {
        $this->authorize('manageTeam', $this->project);

        $validated = $this->validate([
            'teamMemberIds' => ['array'],
            'teamMemberIds.*' => ['integer', 'exists:users,id'],
        ]);

        $sync = collect($validated['teamMemberIds'] ?? [])
            ->mapWithKeys(fn (int $id) => [$id => ['assignment_note' => null]])
            ->all();

        $this->project->teamMembers()->sync($sync);
        $this->project->load('teamMembers');
        $this->fillFromProject();
        $this->editingTeam = false;
        $this->dispatch('toast', message: 'Team assignments saved.', tone: 'success');
    }

    public function cancelTeam(): void
    {
        $this->fillFromProject();
        $this->editingTeam = false;
    }

    public function createCredential(): void
    {
        $this->authorize('create', [Credential::class, $this->project]);
        $this->resetCredentialForm();
        $this->showCredentialForm = true;
    }

    public function editCredential(int $credentialId): void
    {
        $credential = $this->project->credentials()->findOrFail($credentialId);
        $this->authorize('update', $credential);

        $this->editingCredentialId = $credential->id;
        $this->cred_type = $credential->type->value;
        $this->cred_label = $credential->label;
        $this->cred_username = (string) $credential->username;
        $this->cred_secret = '';
        $this->cred_url = (string) $credential->url;
        $this->cred_expires_on = $credential->expires_on?->format('Y-m-d') ?? '';
        $this->cred_metadata_json = $credential->metadata
            ? json_encode($credential->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : '';
        $this->showCredentialForm = true;
    }

    public function saveCredential(): void
    {
        $isCreating = $this->editingCredentialId === null;

        if ($isCreating) {
            $this->authorize('create', [Credential::class, $this->project]);
        } else {
            $credential = Credential::query()->findOrFail($this->editingCredentialId);
            $this->authorize('update', $credential);
        }

        $validated = $this->validate([
            'cred_type' => ['required', Rule::enum(CredentialType::class)],
            'cred_label' => ['required', 'string', 'max:255'],
            'cred_username' => ['nullable', 'string', 'max:500'],
            'cred_secret' => [$isCreating ? 'nullable' : 'nullable', 'string', 'max:2000'],
            'cred_url' => ['nullable', 'string', 'max:500'],
            'cred_expires_on' => ['nullable', 'date'],
            'cred_metadata_json' => ['nullable', 'string', 'max:10000'],
        ]);

        $metadata = [];
        if (filled($validated['cred_metadata_json'])) {
            $decoded = json_decode($validated['cred_metadata_json'], true);
            if (! is_array($decoded)) {
                $this->addError('cred_metadata_json', 'Metadata must be valid JSON object.');

                return;
            }
            $metadata = $decoded;
        }

        $payload = [
            'project_id' => $this->project->id,
            'type' => $validated['cred_type'],
            'label' => $validated['cred_label'],
            'username' => $validated['cred_username'] ?: null,
            'url' => $validated['cred_url'] ?: null,
            'expires_on' => $validated['cred_expires_on'] ?: null,
            'metadata' => $metadata,
        ];

        if ($isCreating) {
            $payload['secret'] = $validated['cred_secret'] ?: null;
            Credential::query()->create($payload);
            $this->dispatch('toast', message: 'Credential saved.', tone: 'success');
        } else {
            $credential = Credential::query()->findOrFail($this->editingCredentialId);
            if (filled($validated['cred_secret'])) {
                $payload['secret'] = $validated['cred_secret'];
            }
            $credential->update($payload);
            $this->forgetRevealed($credential->id);
            $this->dispatch('toast', message: 'Credential updated.', tone: 'success');
        }

        $this->showCredentialForm = false;
        $this->resetCredentialForm();
        $this->project->load('credentials');
    }

    public function deleteCredential(int $credentialId): void
    {
        $credential = $this->project->credentials()->findOrFail($credentialId);
        $this->authorize('delete', $credential);
        $credential->delete();
        $this->forgetRevealed($credentialId);
        $this->project->load('credentials');
        $this->dispatch('toast', message: 'Credential removed.', tone: 'success');
    }

    public function revealCredential(int $credentialId, CredentialVaultService $vault): void
    {
        $credential = $this->project->credentials()->findOrFail($credentialId);
        $this->authorize('reveal', $credential);

        $vault->reveal($credential, Auth::user(), request());

        if (! in_array($credentialId, $this->revealedIds, true)) {
            $this->revealedIds[] = $credentialId;
        }
    }

    public function hideCredential(int $credentialId): void
    {
        $this->forgetRevealed($credentialId);
    }

    protected function forgetRevealed(int $credentialId): void
    {
        $this->revealedIds = array_values(array_diff($this->revealedIds, [$credentialId]));
    }

    /**
     * Decrypt the currently revealed secrets for this render only.
     *
     * Authorisation is re-checked per credential on every render, so revoking
     * `credentials.reveal` mid-session stops the reveal immediately.
     *
     * @param  Collection<int, Credential>  $credentials
     * @return array<int, array{username: ?string, secret: ?string, metadata: array<string, mixed>}>
     */
    protected function revealedSecrets($credentials, CredentialVaultService $vault): array
    {
        if ($this->revealedIds === []) {
            return [];
        }

        $user = Auth::user();
        $revealed = [];

        foreach ($credentials as $credential) {
            if (! in_array($credential->id, $this->revealedIds, true)) {
                continue;
            }

            if (! $user?->can('reveal', $credential)) {
                continue;
            }

            $revealed[$credential->id] = $vault->plaintext($credential);
        }

        return $revealed;
    }

    public function cancelCredentialForm(): void
    {
        $this->showCredentialForm = false;
        $this->resetCredentialForm();
    }

    protected function resetCredentialForm(): void
    {
        $this->editingCredentialId = null;
        $this->cred_type = CredentialType::Other->value;
        $this->cred_label = '';
        $this->cred_username = '';
        $this->cred_secret = '';
        $this->cred_url = '';
        $this->cred_expires_on = '';
        $this->cred_metadata_json = '';
        $this->resetValidation();
    }

    public function uploadFile(): void
    {
        $this->authorize('update', $this->project);

        $this->validate([
            'upload' => ['required', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg,gif,webp,txt,csv,doc,docx,xls,xlsx,zip'],
        ]);

        $path = $this->upload->store('project-files/'.$this->project->id, 'local');

        $this->project->media()->create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $this->upload->getClientOriginalName(),
            'mime_type' => $this->upload->getMimeType(),
            'size' => $this->upload->getSize(),
            'uploaded_by' => Auth::id(),
        ]);

        $this->upload = null;
        $this->project->load('media');
        $this->dispatch('toast', message: 'File uploaded.', tone: 'success');
    }

    public function deleteMedia(int $mediaId): void
    {
        $this->authorize('update', $this->project);
        $media = $this->project->media()->findOrFail($mediaId);
        $media->deleteFile();
        $media->delete();
        $this->project->load('media');
        $this->dispatch('toast', message: 'File removed.', tone: 'success');
    }

    public function render(CredentialVaultService $vault, ProfitAndLossService $pnl)
    {
        // The credential policy reads `$credential->project`, so a page of
        // credentials became a page of project lookups without this.
        $credentials = $this->project->credentials()
            ->with('project')
            ->orderBy('type')
            ->orderBy('label')
            ->get();

        // The header stats used to call monthRevenuePaisa/monthCostPaisa/monthProfitPaisa
        // separately, each running a full P&L pass. Resolve the month once.
        $month = $pnl->monthRowsByProject(Auth::user(), now('UTC')->format('Y-m'), [(int) $this->project->id])[(int) $this->project->id]
            ?? ProfitAndLossService::emptyRow((int) $this->project->id, (string) $this->project->domain);

        return view('livewire.projects.show', [
            'statusOptions' => ProjectStatus::options(),
            'credentialTypes' => CredentialType::options(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(),
            'credentials' => $credentials,
            'revealed' => $this->revealedSecrets($credentials, $vault),
            'month' => $month,
            'openTasks' => Project::openTaskCountsFor([(int) $this->project->id])[(int) $this->project->id] ?? 0,
        ]);
    }
}
