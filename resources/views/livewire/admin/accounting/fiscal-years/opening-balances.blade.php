<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\OpeningBalance;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Ingående balanser');

new class extends Component {

    public FiscalYear $fiscalYear;

    /** account_id → string amount (kr with decimals, signed) */
    public array $amounts = [];

    public function mount(FiscalYear $fiscalYear): void
    {
        abort_unless(Auth::user()->can('accounting.admin'), 403);

        $this->fiscalYear = $fiscalYear;

        // Pre-fill with existing opening balances
        $existing = OpeningBalance::where('fiscal_year_id', $fiscalYear->id)
            ->pluck('amount', 'account_id');

        foreach ($this->accounts as $account) {
            $ore = $existing->get($account->id, 0);
            // Display as signed kronor string, 0 shown as blank
            $this->amounts[$account->id] = $ore !== 0
                ? number_format($ore / 100, 2, '.', '')
                : '';
        }
    }

    #[Computed]
    public function accounts()
    {
        return Account::active()->leaf()
            ->whereIn('type', [
                Account::TYPE_ASSET,
                Account::TYPE_LIABILITY,
                Account::TYPE_EQUITY,
            ])
            ->orderBy('code')
            ->get();
    }

    #[Computed]
    public function accountsByType(): array
    {
        $groups = [
            Account::TYPE_ASSET     => ['label' => 'Tillgångar',         'accounts' => []],
            Account::TYPE_LIABILITY => ['label' => 'Skulder',             'accounts' => []],
            Account::TYPE_EQUITY    => ['label' => 'Eget kapital',        'accounts' => []],
        ];

        foreach ($this->accounts as $account) {
            $groups[$account->type]['accounts'][] = $account;
        }

        return $groups;
    }

    public function save(): void
    {
        abort_unless(Auth::user()->can('accounting.admin'), 403);
        abort_if($this->fiscalYear->is_locked, 403);

        $userId = Auth::id();
        $fyId   = $this->fiscalYear->id;

        foreach ($this->amounts as $accountId => $raw) {
            $accountId = (int) $accountId;
            $raw       = trim((string) $raw);

            if ($raw === '' || $raw === '0' || $raw === '0.00') {
                // Delete zero/blank entries to keep the table clean
                OpeningBalance::where('fiscal_year_id', $fyId)
                    ->where('account_id', $accountId)
                    ->delete();
                continue;
            }

            // Accept comma or period as decimal separator, allow negative
            $ore = (int) round((float) str_replace(',', '.', $raw) * 100);

            OpeningBalance::updateOrCreate(
                ['fiscal_year_id' => $fyId, 'account_id' => $accountId],
                ['amount' => $ore, 'created_by' => $userId, 'updated_by' => $userId],
            );
        }

        AuditLog::record('opening_balances_saved', 'accounting', $this->fiscalYear->name, $this->fiscalYear);
        $this->dispatch('notify', message: 'Ingående balanser sparade.');
    }

    /**
     * Carry forward closing balances (UB) from the previous fiscal year as IB for this year.
     * Only balance sheet accounts (asset, liability, equity) are carried forward.
     * Income/expense closing is handled separately via journal entries (bokslutstransaktion).
     */
    public function carryForwardUb(): void
    {
        abort_unless(Auth::user()->can('accounting.admin'), 403);
        abort_if($this->fiscalYear->is_locked, 403);

        $prevFy = FiscalYear::where('ends_at', '<', $this->fiscalYear->starts_at)
            ->orderByDesc('ends_at')
            ->first();

        if (! $prevFy) {
            $this->dispatch('notify', message: 'Inget föregående räkenskapsår hittades.', type: 'error');
            return;
        }

        $balanceAccountIds = Account::active()->leaf()
            ->whereIn('type', [Account::TYPE_ASSET, Account::TYPE_LIABILITY, Account::TYPE_EQUITY])
            ->pluck('id');

        $prevIb = OpeningBalance::where('fiscal_year_id', $prevFy->id)
            ->pluck('amount', 'account_id');

        $movements = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.fiscal_year_id', $prevFy->id)
            ->whereNull('journal_lines.superseded_at')
            ->whereIn('journal_lines.account_id', $balanceAccountIds)
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) - SUM(journal_lines.credit) as net')
            ->groupBy('journal_lines.account_id')
            ->pluck('net', 'account_id');

        $userId  = Auth::id();
        $fyId    = $this->fiscalYear->id;
        $count   = 0;

        $allAccountIds = $prevIb->keys()->merge($movements->keys())->unique();

        DB::transaction(function () use ($allAccountIds, $prevIb, $movements, $fyId, $userId, &$count): void {
            foreach ($allAccountIds as $accountId) {
                $ub = (int) ($prevIb->get($accountId, 0)) + (int) ($movements->get($accountId, 0));

                if ($ub === 0) {
                    OpeningBalance::where('fiscal_year_id', $fyId)
                        ->where('account_id', $accountId)
                        ->delete();
                    continue;
                }

                OpeningBalance::updateOrCreate(
                    ['fiscal_year_id' => $fyId, 'account_id' => $accountId],
                    ['amount' => $ub, 'created_by' => $userId, 'updated_by' => $userId],
                );
                $count++;
            }
        });

        // Reload form fields
        $existing = OpeningBalance::where('fiscal_year_id', $fyId)->pluck('amount', 'account_id');
        foreach ($this->accounts as $account) {
            $ore = $existing->get($account->id, 0);
            $this->amounts[$account->id] = $ore !== 0
                ? number_format($ore / 100, 2, '.', '')
                : '';
        }

        AuditLog::record('opening_balances_carried_forward', 'accounting', $this->fiscalYear->name, $this->fiscalYear);
        $this->dispatch('notify', message: "UB från {$prevFy->name} överfört som IB ({$count} konton).");
    }

    /** Running debit/credit totals for balance check. */
    #[Computed]
    public function totals(): array
    {
        $debit  = 0;
        $credit = 0;

        foreach ($this->amounts as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') continue;
            $ore = (int) round((float) str_replace(',', '.', $raw) * 100);
            if ($ore > 0) $debit  += $ore;
            else          $credit += abs($ore);
        }

        return ['debit' => $debit, 'credit' => $credit, 'diff' => $debit - $credit];
    }
};
?>

<div>

    <div class="space-y-6">

        {{-- Balance check banner --}}
        @php $totals = $this->totals; @endphp
        @if ($totals['diff'] !== 0)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800
                        dark:border-amber-700/40 dark:bg-amber-900/20 dark:text-amber-300">
                <div class="flex items-center gap-2">
                    <x-bootstrap-icon name="exclamation-triangle-fill" class="size-4 shrink-0" />
                    <span>
                        Balansen stämmer inte — differens
                        <strong>{{ number_format(abs($totals['diff']) / 100, 2, ',', ' ') }} kr</strong>
                        (debet {{ number_format($totals['debit'] / 100, 2, ',', ' ') }} kr,
                        kredit {{ number_format($totals['credit'] / 100, 2, ',', ' ') }} kr).
                        Ingående balanser måste summera till noll.
                    </span>
                </div>
            </div>
        @elseif (count(array_filter($this->amounts, fn ($v) => $v !== '')) > 0)
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800
                        dark:border-green-700/40 dark:bg-green-900/20 dark:text-green-300">
                <div class="flex items-center gap-2">
                    <x-bootstrap-icon name="check-circle-fill" class="size-4 shrink-0" />
                    <span>Balansen stämmer — debet och kredit är lika.</span>
                </div>
            </div>
        @endif

        <form wire:submit="save">

            @foreach ($this->accountsByType as $type => $group)
                @if (empty($group['accounts'])) @continue @endif

                <div class="mb-6 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <div class="border-b border-zinc-200 bg-zinc-50 px-4 py-2.5 dark:border-zinc-700 dark:bg-zinc-800">
                        <h2 class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            {{ $group['label'] }}
                        </h2>
                    </div>
                    <table class="w-full text-sm">
                        <thead class="text-xs text-zinc-400 dark:text-zinc-500">
                            <tr>
                                <th class="px-4 py-2 text-left w-20">Konto</th>
                                <th class="px-4 py-2 text-left">Namn</th>
                                <th class="px-4 py-2 text-right w-52">Belopp (kr)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($group['accounts'] as $account)
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                    <td class="px-4 py-2 font-mono text-zinc-500">{{ $account->code }}</td>
                                    <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">{{ $account->name }}</td>
                                    <td class="px-4 py-1.5 text-right">
                                        <input
                                            type="text"
                                            wire:model.blur="amounts.{{ $account->id }}"
                                            inputmode="decimal"
                                            placeholder="0,00"
                                            class="inp w-44 text-right font-mono text-sm
                                                   placeholder:text-zinc-300 dark:placeholder:text-zinc-600"
                                        />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach

            <div class="flex items-center justify-between gap-4">
                <p class="text-xs text-zinc-400 dark:text-zinc-500">
                    Positivt belopp = debet, negativt belopp = kredit.
                    Tillgångar är normalt positiva, skulder och eget kapital normalt negativa.
                </p>
                @if (! $fiscalYear->is_locked)
                    <div class="flex items-center gap-3 shrink-0" x-data="{ confirmUb: false }">
                        <template x-if="!confirmUb">
                            <flux:button type="button" variant="ghost" @click="confirmUb = true">
                                Hämta UB från föregående år
                            </flux:button>
                        </template>
                        <template x-if="confirmUb">
                            <div class="flex items-center gap-2 text-sm">
                                <span class="text-amber-600 dark:text-amber-400">Skriver över befintliga IB — säker?</span>
                                <flux:button type="button" variant="ghost" size="sm" @click="confirmUb = false">Avbryt</flux:button>
                                <flux:button type="button" size="sm" variant="primary"
                                    wire:click="carryForwardUb" @click="confirmUb = false">
                                    Ja, hämta UB
                                </flux:button>
                            </div>
                        </template>
                        <flux:button type="submit" variant="primary">
                            Spara ingående balanser
                        </flux:button>
                    </div>
                @endif
            </div>

        </form>
    </div>
</div>
