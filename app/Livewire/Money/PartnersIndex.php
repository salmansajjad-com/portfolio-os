<?php

namespace App\Livewire\Money;

use App\Models\PartnerLedgerEntry;
use App\Models\PartnerProfile;
use App\Models\User;
use App\Policies\FinancePolicy;
use App\Services\CsvExportService;
use App\Services\PartnerLedgerService;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Partners')]
class PartnersIndex extends Component
{
    use WithPagination;

    public bool $showCapital = false;

    public string $user_id = '';

    public string $amount = '';

    public string $entry_type = 'capital_in';

    public string $notes = '';

    public function mount(): void
    {
        abort_unless(FinancePolicy::viewPartners(Auth::user()), 403);
    }

    public function postEntry(PartnerLedgerService $ledger): void
    {
        abort_unless(FinancePolicy::managePartners(Auth::user()), 403);
        $this->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'entry_type' => ['required', 'in:capital_in,withdrawal'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $paisa = Money::toMinor($this->amount);
        if ($this->entry_type === 'capital_in') {
            $ledger->recordCapitalIn((int) $this->user_id, $paisa, Auth::user(), $this->notes ?: null);
            $this->dispatch('toast', message: 'Capital contribution recorded.', tone: 'success');
        } else {
            $ledger->recordWithdrawal((int) $this->user_id, $paisa, Auth::user(), $this->notes ?: null);
            $this->dispatch('toast', message: 'Withdrawal recorded.', tone: 'success');
        }

        $this->showCapital = false;
        $this->amount = '';
        $this->notes = '';
    }

    public function exportCsv(CsvExportService $csv)
    {
        abort_unless(FinancePolicy::export(Auth::user()), 403);
        $rows = PartnerLedgerEntry::query()->with('user')->orderBy('id')->get()
            ->map(fn (PartnerLedgerEntry $e) => [
                $e->id,
                $e->user?->name,
                $e->type->value,
                Money::fromMinor((int) $e->amount_paisa),
                Money::fromMinor((int) $e->balance_after_paisa),
                $e->entry_date->format('Y-m-d'),
                $e->description,
            ]);

        $code = strtolower(Currency::code());

        return $csv->download('partner-ledger.csv', [
            'id', 'partner', 'type', 'amount_'.$code, 'balance_after_'.$code, 'date', 'description',
        ], $rows);
    }

    public function render(PartnerLedgerService $ledger)
    {
        $partners = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'partner'))
            ->orWhereHas('ownedProjects')
            ->orderBy('name')
            ->get()
            ->unique('id')
            ->values();

        $balances = $ledger->balancesFor($partners->pluck('id')->map(fn ($id) => (int) $id)->all());

        return view('livewire.money.partners-index', [
            'partners' => $partners,
            'balances' => $balances,
            'canManage' => FinancePolicy::managePartners(Auth::user()),
            'profiles' => PartnerProfile::query()->get()->keyBy('user_id'),
        ]);
    }
}
