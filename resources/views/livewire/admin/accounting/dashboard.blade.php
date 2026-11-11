<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\CostCenter;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalSeries;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Bokföring');

new class extends Component {

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.view'), 403);
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'accounts_active'   => Account::where('is_active', true)->count(),
            'accounts_total'    => Account::count(),
            'cost_centers'      => CostCenter::where('is_active', true)->count(),
            'series'            => JournalSeries::count(),
            'missing_receipts'  => JournalEntry::where('requires_receipt', true)
                ->whereDoesntHave('documents')
                ->count(),
        ];
    }

    #[Computed]
    public function openFiscalYears()
    {
        return FiscalYear::where('is_locked', false)->orderByDesc('starts_at')->get();
    }
};
?>

<div>

    {{-- Missing receipt alert — prominent when non-zero --}}
    @if ($this->stats['missing_receipts'] > 0)
        <a href="{{ route('admin.accounting.journal-entries.index', ['filterMissingReceipt' => 1]) }}"
           wire:navigate
           class="mb-6 flex items-center gap-3 rounded-lg border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/40 px-4 py-3 text-sm text-amber-800 dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-950/60 transition">
            <x-bootstrap-icon name="exclamation-triangle-fill" class="size-5 text-amber-500 shrink-0" />
            <span>
                <strong>{{ $this->stats['missing_receipts'] }}</strong>
                {{ $this->stats['missing_receipts'] === 1 ? 'verifikat saknar' : 'verifikationer saknar' }}
                kvitto — klicka för att visa
            </span>
        </a>
    @endif

    {{-- Status cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-4">
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Aktiva konton</p>
            <p class="text-2xl font-bold mt-1">{{ $this->stats['accounts_active'] }}</p>
            <p class="text-xs text-zinc-400 mt-0.5">av {{ $this->stats['accounts_total'] }} totalt</p>
        </div>
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-4">
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Kostnadsställen</p>
            <p class="text-2xl font-bold mt-1">{{ $this->stats['cost_centers'] }}</p>
            <p class="text-xs text-zinc-400 mt-0.5">aktiva</p>
        </div>
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-4">
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Verifikationsserier</p>
            <p class="text-2xl font-bold mt-1">{{ $this->stats['series'] }}</p>
            <p class="text-xs text-zinc-400 mt-0.5">konfigurerade</p>
        </div>
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-4">
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Öppna räkenskapsår</p>
            <p class="text-2xl font-bold mt-1">{{ $this->openFiscalYears->count() }}</p>
            @if ($this->openFiscalYears->isEmpty())
                <p class="text-xs text-amber-500 mt-0.5">Inget räkenskapsår</p>
            @elseif ($this->openFiscalYears->count() > 1)
                <p class="text-xs text-amber-500 mt-0.5">Flera pågår parallellt</p>
            @else
                <p class="text-xs text-zinc-400 mt-0.5">{{ $this->openFiscalYears->first()->name }}</p>
            @endif
        </div>
    </div>

    {{-- Open fiscal years — shown when more than one is active --}}
    @if ($this->openFiscalYears->count() > 1)
        <div class="mb-8 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 p-4">
            <p class="text-sm font-semibold text-amber-800 dark:text-amber-300 mb-2">
                <x-bootstrap-icon name="exclamation-triangle-fill" class="size-4 inline mr-1" />
                Flera räkenskapsår är öppna samtidigt
            </p>
            <div class="flex flex-wrap gap-3">
                @foreach ($this->openFiscalYears as $fy)
                    <a href="{{ route('admin.accounting.journal-entries.index', ['fiscal_year' => $fy->id]) }}"
                       wire:navigate
                       class="inline-flex items-center gap-2 px-3 py-1.5 rounded bg-white dark:bg-zinc-900 border border-amber-200 dark:border-amber-700 text-sm hover:border-amber-400 transition-colors">
                        <span class="font-medium">{{ $fy->name }}</span>
                        <span class="text-xs text-zinc-400">
                            {{ $fy->starts_at->format('d/m Y') }} – {{ $fy->ends_at->format('d/m Y') }}
                        </span>
                        @if ($fy->containsDate(today()))
                            <span class="text-xs text-green-600 dark:text-green-400">pågående</span>
                        @else
                            <span class="text-xs text-amber-600 dark:text-amber-400">avslutas</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @php
        $linkClass = 'flex items-center gap-3 p-4 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 hover:border-brand dark:hover:border-brand transition-colors group';
        $iconClass  = 'size-5 text-zinc-400 group-hover:text-brand-dark dark:group-hover:text-brand shrink-0';
    @endphp

    {{-- Bokföring --}}
    <h2 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-3">Bokföring</h2>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-8">
        <a href="{{ route('admin.accounting.journal-entries.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="receipt" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Verifikationer</p><p class="text-xs text-zinc-400">Registrera och sök bokföringsposter</p></div>
        </a>
        <a href="{{ route('admin.accounting.journal-entries.create') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="plus-circle" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Ny verifikation</p><p class="text-xs text-zinc-400">Manuell bokföring</p></div>
        </a>
        <a href="{{ route('admin.accounting.fiscal-years.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="calendar-range" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Räkenskapsår</p><p class="text-xs text-zinc-400">Perioder, låsning, ingående balanser</p></div>
        </a>
        <a href="{{ route('admin.accounting.accounts.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="list-columns" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Kontoplan</p><p class="text-xs text-zinc-400">BAS-konton, momskoder</p></div>
        </a>
        <a href="{{ route('admin.accounting.cost-centers.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="diagram-3" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Kostnadsställen</p><p class="text-xs text-zinc-400">Sektioner och projekt</p></div>
        </a>
        <a href="{{ route('admin.accounting.journal-series.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="journals" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Verifikationsserier</p><p class="text-xs text-zinc-400">Konfigurera A–Z-serier</p></div>
        </a>
        <a href="{{ route('admin.accounting.receipt-scan') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="robot" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">AI-kvittoskanning</p><p class="text-xs text-zinc-400">Automatiskt kontoförslag från kvittofoto</p></div>
        </a>
    </div>

    {{-- Fakturor --}}
    <h2 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-3">Fakturor</h2>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-8">
        <a href="{{ route('admin.accounting.invoices.payable.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="file-earmark-arrow-down" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Leverantörsfakturor</p><p class="text-xs text-zinc-400">Inkommande fakturor, betalning</p></div>
        </a>
        <a href="{{ route('admin.accounting.invoices.receivable.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="file-earmark-arrow-up" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Kundfakturor</p><p class="text-xs text-zinc-400">Utgående fakturor, kundreskontra</p></div>
        </a>
        <a href="{{ route('admin.accounting.vendors.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="building" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Leverantörer</p><p class="text-xs text-zinc-400">Leverantörsregister</p></div>
        </a>
        <a href="{{ route('admin.accounting.customers.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="people" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Kunder</p><p class="text-xs text-zinc-400">Kundregister</p></div>
        </a>
        <a href="{{ route('admin.accounting.inbox.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="inbox" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Fakturainkorg</p><p class="text-xs text-zinc-400">Inkommande dokument, attest</p></div>
        </a>
        <a href="{{ route('admin.accounting.invoices.pending-approval') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="card-checklist" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Väntar attest</p><p class="text-xs text-zinc-400">Fakturor inskickade för godkännande</p></div>
        </a>
        <a href="{{ route('admin.accounting.documents.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="folder2-open" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Dokument</p><p class="text-xs text-zinc-400">Kvitton och bilagor</p></div>
        </a>
    </div>

    {{-- Bank --}}
    <h2 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-3">Bank</h2>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-8">
        <a href="{{ route('admin.accounting.bank-accounts.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="bank" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Bankkonton</p><p class="text-xs text-zinc-400">Kontosaldo, importera kontoutdrag</p></div>
        </a>
        <a href="{{ route('admin.accounting.bank-transactions.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="arrow-left-right" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Banktransaktioner</p><p class="text-xs text-zinc-400">Matchning mot verifikat</p></div>
        </a>
        <a href="{{ route('admin.accounting.corporate-cards.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="credit-card" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Kontokort</p><p class="text-xs text-zinc-400">Kortköp och utlägg</p></div>
        </a>
    </div>

    {{-- Rapporter --}}
    <h2 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-3">Rapporter</h2>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-8">
        <a href="{{ route('admin.accounting.reports.balance-sheet') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="bar-chart-steps" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Balansräkning</p><p class="text-xs text-zinc-400">Tillgångar, skulder, eget kapital</p></div>
        </a>
        <a href="{{ route('admin.accounting.reports.income-statement') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="graph-up" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Resultaträkning</p><p class="text-xs text-zinc-400">Intäkter och kostnader</p></div>
        </a>
        <a href="{{ route('admin.accounting.reports.general-ledger') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="book" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Huvudbok</p><p class="text-xs text-zinc-400">Rörelser per konto</p></div>
        </a>
        <a href="{{ route('admin.accounting.reports.journal-list') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="list-ul" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Verifikationslista</p><p class="text-xs text-zinc-400">Alla verifikat, filtrerbar</p></div>
        </a>
        <a href="{{ route('admin.accounting.reports.budget-variance') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="calculator" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Budgetutfall</p><p class="text-xs text-zinc-400">Utfall mot budget</p></div>
        </a>
        <a href="{{ route('admin.accounting.fiscal-years.sie-import') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="upload" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">SIE4-import</p><p class="text-xs text-zinc-400">Importera från annat system</p></div>
        </a>
    </div>

    {{-- Inställningar --}}
    <h2 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-3">Inställningar</h2>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-8">
        <a href="{{ route('admin.accounting.journal-templates.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="layout-text-sidebar" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Konteringsmallar</p><p class="text-xs text-zinc-400">Återkommande verifikatmönster</p></div>
        </a>
        <a href="{{ route('admin.accounting.posting-rules.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="lightning-charge" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Bokföringsregler</p><p class="text-xs text-zinc-400">Automatisk kontering</p></div>
        </a>
        <a href="{{ route('admin.accounting.budgets.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="pie-chart" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Budget</p><p class="text-xs text-zinc-400">Månadsbudget per konto</p></div>
        </a>
        <a href="{{ route('admin.accounting.payment-providers.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="wallet2" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Betaltjänster</p><p class="text-xs text-zinc-400">Stripe, Swish, Bankgiro</p></div>
        </a>
        <a href="{{ route('admin.accounting.payment-terms.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="clock-history" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Betalningsvillkor</p><p class="text-xs text-zinc-400">30 dagar netto, etc.</p></div>
        </a>
        <a href="{{ route('admin.accounting.approval-rules.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="shield-check" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Attestregler</p><p class="text-xs text-zinc-400">Vilka fakturor kräver attest</p></div>
        </a>
        <a href="{{ route('admin.accounting.revision.index') }}" wire:navigate class="{{ $linkClass }}">
            <x-bootstrap-icon name="journal-text" class="{{ $iconClass }}" />
            <div><p class="font-medium text-sm">Revision</p><p class="text-xs text-zinc-400">Ändringslogg, exportera</p></div>
        </a>
    </div>

</div>
