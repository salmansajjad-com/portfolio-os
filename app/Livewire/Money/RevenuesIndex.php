<?php

namespace App\Livewire\Money;

use App\Enums\RevenueSource;
use App\Models\Project;
use App\Models\Revenue;
use App\Policies\FinancePolicy;
use App\Services\CsvExportService;
use App\Services\RevenueService;
use App\Support\Currency;
use App\Support\DisplayTimezone;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Revenue')]
class RevenuesIndex extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $projectFilter = '';

    #[Url]
    public string $monthFilter = '';

    public bool $showForm = false;

    public bool $showImport = false;

    public ?int $editingId = null;

    public string $project_id = '';

    public string $period_month = '';

    public string $source = 'adsense';

    public string $amount_usd = '';

    public string $fx_rate = '';

    public string $notes = '';

    public $importFile = null;

    /** @var array<int, int> */
    public array $selectedIds = [];

    public function mount(): void
    {
        abort_unless(FinancePolicy::viewRevenue(Auth::user()), 403);
        if ($this->monthFilter === '') {
            $this->monthFilter = DisplayTimezone::now()->format('Y-m');
        }
        $this->period_month = $this->monthFilter;
        $service = app(RevenueService::class);
        $this->fx_rate = Money::fxRateFromE6($service->defaultFxRateE6());

        if (request()->boolean('new') && FinancePolicy::manageRevenue(Auth::user())) {
            $this->create();
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        abort_unless(FinancePolicy::manageRevenue(Auth::user()), 403);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        abort_unless(FinancePolicy::manageRevenue(Auth::user()), 403);
        $row = Revenue::query()->accessibleBy(Auth::user())->findOrFail($id);
        $this->editingId = $row->id;
        $this->project_id = (string) $row->project_id;
        $this->period_month = $row->period_month->format('Y-m');
        $this->source = $row->source->value;
        $this->amount_usd = Money::fromSourceMinor((int) $row->amount_usd_cents);
        $this->fx_rate = Money::fxRateFromE6((int) $row->fx_rate_e6);
        $this->notes = (string) $row->notes;
        $this->showForm = true;
    }

    public function save(RevenueService $revenues): void
    {
        abort_unless(FinancePolicy::manageRevenue(Auth::user()), 403);

        $this->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'period_month' => ['required', 'date_format:Y-m'],
            'source' => ['required', 'in:'.implode(',', array_column(RevenueSource::cases(), 'value'))],
            'amount_usd' => ['required', 'numeric', 'min:0'],
            'fx_rate' => ['required', 'numeric', 'min:0.000001'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $payload = [
            'project_id' => (int) $this->project_id,
            'period_month' => $this->period_month,
            'source' => $this->source,
            'amount_usd' => $this->amount_usd,
            'fx_rate' => $this->fx_rate,
            'notes' => $this->notes ?: null,
        ];

        if ($this->editingId) {
            $revenues->update(Revenue::query()->accessibleBy(Auth::user())->findOrFail($this->editingId), $payload, Auth::user());
            $this->dispatch('toast', message: 'Revenue updated (FX locked on record).', tone: 'success');
        } else {
            $revenues->create($payload, Auth::user());
            $this->dispatch('toast', message: 'Revenue recorded.', tone: 'success');
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function delete(int $id, RevenueService $revenues): void
    {
        abort_unless(FinancePolicy::manageRevenue(Auth::user()), 403);
        $revenues->softDelete(Revenue::query()->accessibleBy(Auth::user())->findOrFail($id), Auth::user());
        $this->dispatch('toast', message: 'Revenue soft-deleted.', tone: 'success');
    }

    public function import(RevenueService $revenues): void
    {
        abort_unless(FinancePolicy::manageRevenue(Auth::user()), 403);
        $this->validate([
            'importFile' => ['required', 'file', 'mimes:csv,txt', 'max:4096'],
        ]);

        $contents = file_get_contents($this->importFile->getRealPath());
        $result = $revenues->importAdsenseCsv(
            $contents ?: '',
            $this->importFile->getClientOriginalName(),
            Auth::user(),
        );

        $this->dispatch(
            'toast',
            message: "Import complete: {$result['imported']} imported, {$result['skipped']} skipped.",
            tone: 'success',
        );
        $this->showImport = false;
        $this->importFile = null;
    }

    public function exportCsv(CsvExportService $csv)
    {
        abort_unless(FinancePolicy::export(Auth::user()), 403);

        $rows = $this->baseQuery()->with('project')->orderByDesc('period_month')->orderBy('id')->get()
            ->map(fn (Revenue $r) => [
                $r->id,
                $r->project?->domain,
                $r->period_month->format('Y-m'),
                $r->source->value,
                Money::fromSourceMinor((int) $r->amount_usd_cents),
                Money::fxRateFromE6((int) $r->fx_rate_e6),
                Money::fromMinor((int) $r->amount_pkr_paisa),
                $r->notes,
            ]);

        return $csv->download('revenues.csv', [
            'id', 'domain', 'period_month', 'source',
            'amount_'.strtolower(Currency::sourceCode()),
            'fx_rate',
            'amount_'.strtolower(Currency::code()),
            'notes',
        ], $rows);
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->project_id = $this->projectFilter;
        $this->period_month = $this->monthFilter ?: DisplayTimezone::now()->format('Y-m');
        $this->source = 'adsense';
        $this->amount_usd = '';
        $this->fx_rate = Money::fxRateFromE6(app(RevenueService::class)->defaultFxRateE6());
        $this->notes = '';
    }

    protected function baseQuery()
    {
        $user = Auth::user();

        return Revenue::query()
            ->accessibleBy($user)
            ->when($this->projectFilter !== '', fn ($q) => $q->where('project_id', $this->projectFilter))
            ->when($this->monthFilter !== '', function ($q) {
                $start = $this->monthFilter.'-01';
                $q->whereDate('period_month', $start);
            })
            ->when($this->search !== '', function ($q) {
                $s = '%'.$this->search.'%';
                $q->where(function ($inner) use ($s) {
                    $inner->where('notes', 'like', $s)
                        ->orWhereHas('project', fn ($p) => $p->where('domain', 'like', $s));
                });
            });
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.money.revenues-index', [
            'revenues' => $this->baseQuery()->with('project')->orderByDesc('period_month')->orderByDesc('id')->paginate(20),
            'projects' => Project::query()->accessibleBy($user)->orderBy('domain')->get(),
            'sources' => RevenueSource::options(),
            'canManage' => FinancePolicy::manageRevenue($user),
        ]);
    }
}
