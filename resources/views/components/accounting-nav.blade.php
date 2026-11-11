@php
    $is = fn(string ...$patterns) => request()->routeIs(...$patterns);

    $activeTab = match(true) {
        $is('admin.accounting.dashboard')                                         => 'dashboard',
        $is('admin.accounting.journal-entries.*')                                 => 'verifikationer',
        $is('admin.accounting.invoices.*',
            'admin.accounting.vendors.*',
            'admin.accounting.customers.*')                                       => 'fakturor',
        $is('admin.accounting.inbox.*')                                           => 'mail',
        $is('admin.accounting.bank-transactions.*',
            'admin.accounting.bank-accounts.import',
            'admin.accounting.bank-accounts.lb-return',
            'admin.accounting.bank-accounts.swish-import',
            'admin.accounting.bank-accounts.bank-fee-import')                     => 'bank',
        $is('admin.accounting.reports.*', 'admin.accounting.revision.*')          => 'rapporter',
        $is('admin.accounting.budgets.*')                                         => 'budget',
        $is('admin.accounting.accounts.*', 'admin.accounting.cost-centers.*',
            'admin.accounting.fiscal-years.*', 'admin.accounting.journal-series.*',
            'admin.accounting.posting-rules.*', 'admin.accounting.journal-templates.*',
            'admin.accounting.payment-terms.*', 'admin.accounting.payment-providers.*',
            'admin.accounting.documents.*', 'admin.accounting.corporate-cards.*',
            'admin.accounting.bank-accounts.index')                               => 'installningar',
        default                                                                   => '',
    };

    $tab  = fn(string $key) => $activeTab === $key;
    $pill = 'flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors';
    $pa   = 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white';
    $pi   = 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200';

    $subPill = 'flex items-center rounded-md px-2 py-1 text-xs transition-colors';
    $spa     = 'bg-zinc-100 font-medium text-zinc-800 dark:bg-zinc-700 dark:text-zinc-100';
    $spi     = 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200';

    $canManage = auth()->user()?->can('accounting.manage');
    $canAdmin  = auth()->user()?->hasRole('revisor') || auth()->user()?->isAdmin();
@endphp

<x-page-header title="Bokföring" permission="accounting.view" />

<div class="sticky z-10 border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
     style="top: var(--header-height, 3.5rem)">
    <div class="px-4 py-2.5 sm:px-6 space-y-2">

        {{-- Row 1: group-level pills --}}
        <nav class="flex flex-wrap gap-1.5">

            <a href="{{ route('admin.accounting.dashboard') }}" wire:navigate
               @class([$pill, $pa => $tab('dashboard'), $pi => !$tab('dashboard')])>
                Översikt
            </a>

            <a href="{{ route('admin.accounting.journal-entries.index') }}" wire:navigate
               @class([$pill, $pa => $tab('verifikationer'), $pi => !$tab('verifikationer')])>
                Verifikationer
            </a>

            <a href="{{ route('admin.accounting.invoices.receivable.index') }}" wire:navigate
               @class([$pill, $pa => $tab('fakturor'), $pi => !$tab('fakturor')])>
                Fakturor
            </a>

            <a href="{{ route('admin.accounting.inbox.index') }}" wire:navigate
               @class([$pill, $pa => $tab('mail'), $pi => !$tab('mail')])>
                Mail
            </a>

            <a href="{{ route('admin.accounting.bank-transactions.index') }}" wire:navigate
               @class([$pill, $pa => $tab('bank'), $pi => !$tab('bank')])>
                Bank
            </a>

            <a href="{{ route('admin.accounting.reports.journal-list') }}" wire:navigate
               @class([$pill, $pa => $tab('rapporter'), $pi => !$tab('rapporter')])>
                Rapporter
            </a>

            <a href="{{ route('admin.accounting.budgets.index') }}" wire:navigate
               @class([$pill, $pa => $tab('budget'), $pi => !$tab('budget')])>
                Budget
            </a>

            @if($canManage)
            <a href="{{ route('admin.accounting.accounts.index') }}" wire:navigate
               @class([$pill, $pa => $tab('installningar'), $pi => !$tab('installningar')])>
                Inställningar
            </a>
            @endif

        </nav>

        {{-- Row 2: sub-items for active group --}}
        @if($activeTab === 'fakturor')
        <nav class="flex flex-wrap gap-1 border-t border-zinc-100 pt-2 dark:border-zinc-800">
            <a href="{{ route('admin.accounting.invoices.receivable.index') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.invoices.receivable.*'), $spi => !$is('admin.accounting.invoices.receivable.*')])>
                Kundfakturor
            </a>
            <a href="{{ route('admin.accounting.invoices.receivable.create') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.invoices.receivable.create'), $spi => !$is('admin.accounting.invoices.receivable.create')])>
                + Ny kundfaktura
            </a>
            <span class="text-zinc-200 dark:text-zinc-700 self-center">|</span>
            <a href="{{ route('admin.accounting.invoices.payable.index') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.invoices.payable.*'), $spi => !$is('admin.accounting.invoices.payable.*')])>
                Leverantörsfakturor
            </a>
            <a href="{{ route('admin.accounting.invoices.payable.create') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.invoices.payable.create'), $spi => !$is('admin.accounting.invoices.payable.create')])>
                + Ny leverantörsfaktura
            </a>
            <a href="{{ route('admin.accounting.invoices.payable.import-zip') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.invoices.payable.import-zip'), $spi => !$is('admin.accounting.invoices.payable.import-zip')])>
                Importera ZIP
            </a>
            <span class="text-zinc-200 dark:text-zinc-700 self-center">|</span>
            <a href="{{ route('admin.accounting.customers.index') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.customers.*'), $spi => !$is('admin.accounting.customers.*')])>
                Kunder
            </a>
            <a href="{{ route('admin.accounting.vendors.index') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.vendors.*'), $spi => !$is('admin.accounting.vendors.*')])>
                Leverantörer
            </a>
        </nav>
        @endif

        @if($activeTab === 'mail')
        @php
            $mailTab = request()->query('tab', 'approval');
            $mailTabs = [
                'inbox'     => 'Inkorg',
                'approval'  => 'Godkänn',
                'pending'   => 'Redo att länka',
                'all'       => 'Alla bilagor',
                'claims'    => 'Utlägg',
                'restlista' => 'Restlista',
                'senders'   => 'Avsändarlista',
            ];
        @endphp
        <nav class="flex flex-wrap gap-1 border-t border-zinc-100 pt-2 dark:border-zinc-800">
            @foreach($mailTabs as $key => $label)
                <a href="{{ route('admin.accounting.inbox.index') }}?tab={{ $key }}" wire:navigate
                   @class([$subPill, $spa => $mailTab === $key, $spi => $mailTab !== $key])>
                    {{ $label }}
                </a>
            @endforeach
        </nav>
        @endif

        @if($activeTab === 'bank')
        <nav class="flex flex-wrap gap-1 border-t border-zinc-100 pt-2 dark:border-zinc-800">
            <a href="{{ route('admin.accounting.bank-transactions.index') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.bank-transactions.*'), $spi => !$is('admin.accounting.bank-transactions.*')])>
                Transaktioner
            </a>
            <span class="text-zinc-200 dark:text-zinc-700 self-center">|</span>
            <a href="{{ route('admin.accounting.bank-accounts.lb-return') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.bank-accounts.lb-return'), $spi => !$is('admin.accounting.bank-accounts.lb-return')])>
                LB återredovisning
            </a>
            <a href="{{ route('admin.accounting.bank-accounts.swish-import') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.bank-accounts.swish-import'), $spi => !$is('admin.accounting.bank-accounts.swish-import')])>
                Importera Swish
            </a>
            <a href="{{ route('admin.accounting.bank-accounts.bank-fee-import') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.bank-accounts.bank-fee-import'), $spi => !$is('admin.accounting.bank-accounts.bank-fee-import')])>
                Bankavgifter
            </a>
            <a href="{{ route('admin.accounting.bank-accounts.import') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.bank-accounts.import'), $spi => !$is('admin.accounting.bank-accounts.import')])>
                Importera kontoutdrag
            </a>
        </nav>
        @endif

        @if($activeTab === 'rapporter')
        <nav class="flex flex-wrap gap-1 border-t border-zinc-100 pt-2 dark:border-zinc-800">
            <a href="{{ route('admin.accounting.reports.journal-list') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.reports.journal-list'), $spi => !$is('admin.accounting.reports.journal-list')])>
                Verifikationslista
            </a>
            <a href="{{ route('admin.accounting.reports.general-ledger') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.reports.general-ledger'), $spi => !$is('admin.accounting.reports.general-ledger')])>
                Huvudbok
            </a>
            <a href="{{ route('admin.accounting.reports.income-statement') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.reports.income-statement'), $spi => !$is('admin.accounting.reports.income-statement')])>
                Resultaträkning
            </a>
            <a href="{{ route('admin.accounting.reports.balance-sheet') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.reports.balance-sheet'), $spi => !$is('admin.accounting.reports.balance-sheet')])>
                Balansräkning
            </a>
            <a href="{{ route('admin.accounting.reports.budget-variance') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.reports.budget-variance'), $spi => !$is('admin.accounting.reports.budget-variance')])>
                Budgetuppföljning
            </a>
            <a href="{{ route('admin.accounting.reports.vendor-report') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.reports.vendor-report'), $spi => !$is('admin.accounting.reports.vendor-report')])>
                Leverantörsrapport
            </a>
            @if($canAdmin)
            <span class="text-zinc-200 dark:text-zinc-700 self-center">|</span>
            <a href="{{ route('admin.accounting.revision.index') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.revision.*'), $spi => !$is('admin.accounting.revision.*')])>
                Revision
            </a>
            @endif
        </nav>
        @endif

        @if($activeTab === 'installningar' && $canManage)
        <nav class="flex flex-wrap gap-1 border-t border-zinc-100 pt-2 dark:border-zinc-800">
            <a href="{{ route('admin.accounting.accounts.index') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.accounts.*'), $spi => !$is('admin.accounting.accounts.*')])>
                Konton
            </a>
            <a href="{{ route('admin.accounting.bank-accounts.index') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.bank-accounts.index'), $spi => !$is('admin.accounting.bank-accounts.index')])>
                Bankkonton
            </a>
            <a href="{{ route('admin.accounting.cost-centers.index') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.cost-centers.*'), $spi => !$is('admin.accounting.cost-centers.*')])>
                Kostnadsställen
            </a>
            <a href="{{ route('admin.accounting.journal-series.index') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.journal-series.*'), $spi => !$is('admin.accounting.journal-series.*')])>
                Verifikationsserier
            </a>
            <a href="{{ route('admin.accounting.fiscal-years.index') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.fiscal-years.*'), $spi => !$is('admin.accounting.fiscal-years.*')])>
                Räkenskapsår
            </a>
            <span class="text-zinc-200 dark:text-zinc-700 self-center">|</span>
            <a href="{{ route('admin.accounting.posting-rules.index') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.posting-rules.*'), $spi => !$is('admin.accounting.posting-rules.*')])>
                Bokföringsregler
            </a>
            <a href="{{ route('admin.accounting.journal-templates.index') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.journal-templates.*'), $spi => !$is('admin.accounting.journal-templates.*')])>
                Konteringsmallar
            </a>
            <span class="text-zinc-200 dark:text-zinc-700 self-center">|</span>
            <a href="{{ route('admin.accounting.payment-providers.index') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.payment-providers.*'), $spi => !$is('admin.accounting.payment-providers.*')])>
                Betaltjänster
            </a>
            <a href="{{ route('admin.accounting.payment-terms.index') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.payment-terms.*'), $spi => !$is('admin.accounting.payment-terms.*')])>
                Betalningsvillkor
            </a>
            <a href="{{ route('admin.accounting.documents.index') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.documents.*'), $spi => !$is('admin.accounting.documents.*')])>
                Dokument
            </a>
            <a href="{{ route('admin.accounting.corporate-cards.index') }}" wire:navigate
               @class([$subPill, $spa => $is('admin.accounting.corporate-cards.*'), $spi => !$is('admin.accounting.corporate-cards.*')])>
                Kontokort
            </a>
        </nav>
        @endif

    </div>
</div>
