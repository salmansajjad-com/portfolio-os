<?php

namespace App\Livewire\Tasks;

use App\Enums\RecurrenceFrequency;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\RecurringTaskGenerator;
use App\Services\TaskWorkflowService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Tasks')]
class Index extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $projectFilter = '';

    #[Url]
    public string $mineOnly = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $project_id = '';

    public string $title = '';

    public string $description = '';

    public string $type = 'ad_hoc';

    public string $assigned_to = '';

    public string $due_date = '';

    public string $recurrence_frequency = '';

    public string $time_spent_minutes = '0';

    /** @var array<int, int> */
    public array $selectedIds = [];

    public string $bulkAssignTo = '';

    public string $bulkStatus = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Task::class);

        // Remember last project filter for staff speed
        if ($this->projectFilter === '' && session()->has('tasks.last_project_id')) {
            $this->projectFilter = (string) session('tasks.last_project_id');
        }

        // Quick-create deep link (?new=1) opens the form straight away
        if (request()->boolean('new') && Auth::user()?->hasPermission('tasks.create')) {
            $this->create();
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingProjectFilter(string $value): void
    {
        $this->resetPage();
        if ($value !== '') {
            session(['tasks.last_project_id' => $value]);
        }
    }

    public function create(): void
    {
        $this->authorize('create', Task::class);
        $this->resetForm();
        $this->project_id = $this->projectFilter !== '' ? $this->projectFilter : (string) (session('tasks.last_project_id') ?? '');
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $task = Task::query()->findOrFail($id);
        $this->authorize('update', $task);

        $this->editingId = $task->id;
        $this->project_id = (string) $task->project_id;
        $this->title = $task->title;
        $this->description = (string) $task->description;
        $this->type = $task->type->value;
        $this->assigned_to = (string) ($task->assigned_to ?? '');
        $this->due_date = $task->due_date?->format('Y-m-d') ?? '';
        $this->recurrence_frequency = $task->recurrence_frequency?->value ?? '';
        $this->time_spent_minutes = (string) $task->time_spent_minutes;
        $this->showForm = true;
    }

    public function save(RecurringTaskGenerator $generator): void
    {
        if ($this->editingId) {
            $task = Task::query()->findOrFail($this->editingId);
            $this->authorize('update', $task);
        } else {
            $this->authorize('create', Task::class);
        }

        $validated = $this->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'type' => ['required', Rule::enum(TaskType::class)],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'due_date' => ['nullable', 'date'],
            'recurrence_frequency' => ['nullable', Rule::enum(RecurrenceFrequency::class)],
            'time_spent_minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $project = Project::query()->findOrFail($validated['project_id']);
        abort_unless(Auth::user()->canAccessProject($project), 403);

        $isRecurring = ($validated['type'] === TaskType::Recurring->value)
            || filled($validated['recurrence_frequency'] ?? null);

        $payload = [
            'project_id' => (int) $validated['project_id'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?: null,
            'type' => $isRecurring ? TaskType::Recurring : TaskType::from($validated['type']),
            'assigned_to' => $validated['assigned_to'] ?: null,
            'due_date' => $validated['due_date'] ?: null,
            'time_spent_minutes' => (int) ($validated['time_spent_minutes'] ?? 0),
            'recurrence_frequency' => $validated['recurrence_frequency'] ?: null,
        ];

        if ($this->editingId) {
            $task = Task::query()->findOrFail($this->editingId);
            // Don't rewrite setup template linkage type away from setup unless converting intentionally
            if ($task->type === TaskType::Setup && ! $isRecurring) {
                $payload['type'] = TaskType::Setup;
            }

            // Editing a task is not the same right as handing it to someone else.
            // The form hides the field, but hiding a field is not authorisation.
            if (! Auth::user()->can('assign', $task)) {
                unset($payload['assigned_to']);
            }

            $task->update($payload);
        } else {
            $payload['status'] = TaskStatus::Assigned;
            $payload['created_by'] = Auth::id();

            if ($isRecurring && $payload['recurrence_frequency']) {
                // Source row drives generation; first instance produced immediately
                $payload['is_recurrence_source'] = true;
                $payload['next_occurrence_date'] = $payload['due_date'] ?? now()->toDateString();
                $source = Task::query()->create($payload);
                $generator->generateForSource($source);
            } else {
                $payload['is_recurrence_source'] = false;
                Task::query()->create($payload);
            }
        }

        session(['tasks.last_project_id' => $validated['project_id']]);
        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('toast', message: 'Task saved.', tone: 'success');
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    public function applyBulkAssign(TaskWorkflowService $workflow): void
    {
        abort_unless(Auth::user()?->hasPermission('tasks.assign'), 403);

        $this->validate([
            'bulkAssignTo' => ['required', 'integer', 'exists:users,id'],
            'selectedIds' => ['required', 'array', 'min:1'],
        ]);

        $ids = Task::query()
            ->accessibleBy(Auth::user())
            ->whereIn('id', $this->selectedIds)
            ->pluck('id')
            ->all();

        $count = $workflow->bulkAssign($ids, (int) $this->bulkAssignTo, Auth::user());
        $this->selectedIds = [];
        $this->bulkAssignTo = '';
        $this->dispatch('toast', message: "Assigned {$count} task(s).", tone: 'success');
    }

    public function applyBulkStatus(TaskWorkflowService $workflow): void
    {
        abort_unless(Auth::user()?->hasAnyPermission('tasks.approve', 'tasks.assign'), 403);

        $this->validate([
            'bulkStatus' => ['required', Rule::enum(TaskStatus::class)],
            'selectedIds' => ['required', 'array', 'min:1'],
        ]);

        $status = TaskStatus::from($this->bulkStatus);
        $tasks = Task::query()->accessibleBy(Auth::user())->whereIn('id', $this->selectedIds)->get();

        $count = 0;
        foreach ($tasks as $task) {
            try {
                match ($status) {
                    TaskStatus::InProgress => $workflow->start($task, Auth::user()),
                    TaskStatus::Submitted => $this->authorize('submit', $task) && $workflow->submit($task, Auth::user()),
                    TaskStatus::Approved => $this->authorize('approve', $task) && $workflow->approve($task, Auth::user()),
                    TaskStatus::Assigned => $task->update(['status' => TaskStatus::Assigned]),
                    default => null,
                };
                $count++;
            } catch (ValidationException) {
                // skip illegal transitions
            }
        }

        $this->selectedIds = [];
        $this->bulkStatus = '';
        $this->dispatch('toast', message: "Updated {$count} task(s).", tone: 'success');
    }

    public function clearSelection(): void
    {
        $this->selectedIds = [];
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->project_id = '';
        $this->title = '';
        $this->description = '';
        $this->type = TaskType::AdHoc->value;
        $this->assigned_to = '';
        $this->due_date = '';
        $this->recurrence_frequency = '';
        $this->time_spent_minutes = '0';
        $this->resetValidation();
    }

    public function render()
    {
        $user = Auth::user();

        $tasks = Task::query()
            ->with(['project', 'assignee'])
            ->accessibleBy($user)
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('title', 'like', $term)
                        ->orWhere('description', 'like', $term);
                });
            })
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->projectFilter !== '', fn ($q) => $q->where('project_id', $this->projectFilter))
            ->when($this->mineOnly === '1', fn ($q) => $q->where('assigned_to', $user->id))
            // Hide pure recurrence source rows from main list (instances carry the work)
            ->where(function ($q) {
                $q->where('is_recurrence_source', false)
                    ->orWhereNull('is_recurrence_source');
            })
            ->orderByRaw("CASE status WHEN 'submitted' THEN 0 WHEN 'rejected' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'assigned' THEN 3 ELSE 4 END")
            ->orderBy('due_date')
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.tasks.index', [
            'tasks' => $tasks,
            'statusOptions' => TaskStatus::options(),
            'typeOptions' => TaskType::options(),
            'frequencyOptions' => RecurrenceFrequency::options(),
            'projects' => Project::query()->accessibleBy($user)->orderBy('domain')->get(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(),
            'canCreate' => $user->hasPermission('tasks.create'),
            'canAssign' => $user->hasPermission('tasks.assign'),
            'canApprove' => $user->hasPermission('tasks.approve'),
        ]);
    }
}
