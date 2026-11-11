<?php

use App\Models\Accounting\BankAccount;
use App\Models\Accounting\BankTransaction;
use App\Models\AuditLog;
use App\Services\Accounting\BankReconciliationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Bankavstämning — förslag');

new class extends Component {

    public ?int $bankAccountId = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        // Default to first active bank account
        $first = BankAccount::active()->orderBy('name')->first();
        if ($first) {
            $this->bankAccountId = $first->id;
        }
    }

    #[Computed]
    public function bankAccounts()
    {
        return BankAccount::active()->orderBy('name')->get();
    }

    #[Computed]
    public function unmatched()
    {
        if (! $this->bankAccountId) {
            return collect();
        }

        return BankTransaction::with(['bankAccount.account'])
            ->where('bank_account_id', $this->bankAccountId)
            ->where('status', BankTransaction::STATUS_UNMATCHED)
            ->orderBy('booking_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Auto-suggestions keyed by BankTransaction id.
     * Each value is null or ['entry', 'confidence', 'reason', 'total_candidates'].
     */
    #[Computed]
    public function suggestions(): array
    {
        $txs = $this->unmatched;
        if ($txs->isEmpty()) {
            return [];
        }

        $service = new BankReconciliationService();
        return $service->suggestBatch($txs);
    }

    /** Confirm a single suggestion: link journal entry, set status = matched. */
    public function confirm(int $txId): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $suggestion = $this->suggestions[$txId] ?? null;
        if (! $suggestion) {
            return;
        }

        $tx = BankTransaction::findOrFail($txId);

        // Guard: entry must not have been claimed in the meantime
        if (BankTransaction::where('journal_entry_id', $suggestion['entry']->id)
                ->where('id', '!=', $txId)->exists()) {
            session()->flash('error', "Verifikat {$suggestion['entry']->series}{$suggestion['entry']->number} hann matchas av en annan transaktion.");
            return;
        }

        $tx->update([
            'journal_entry_id' => $suggestion['entry']->id,
            'status'           => BankTransaction::STATUS_MATCHED,
        ]);

        AuditLog::record('bank_tx_reconciled', 'accounting',
            number_format(abs($tx->amount) / 100, 2, ',', ' ') . ' kr — ' . ($tx->description ?? ''),
            $tx);

        unset($this->unmatched, $this->suggestions);
    }

    /**
     * Confirm all high-confidence suggestions in one go.
     * Skips any entry that has already been claimed (race condition guard).
     */
    public function confirmAll(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $confirmed = 0;

        foreach ($this->suggestions as $txId => $suggestion) {
            if (! $suggestion || $suggestion['confidence'] !== 'high') {
                continue;
            }

            $tx = BankTransaction::find($txId);
            if (! $tx || $tx->status !== BankTransaction::STATUS_UNMATCHED) {
                continue;
            }

            if (BankTransaction::where('journal_entry_id', $suggestion['entry']->id)
                    ->where('id', '!=', $txId)->exists()) {
                continue;
            }

            $tx->update([
                'journal_entry_id' => $suggestion['entry']->id,
                'status'           => BankTransaction::STATUS_MATCHED,
            ]);

            $confirmed++;
        }

        unset($this->unmatched, $this->suggestions);

        AuditLog::record('bank_tx_reconcile_all', 'accounting',
            "{$confirmed} transaktioner matchade",
            null);

        session()->flash('success', "{$confirmed} transaktioner matchade.");
    }
};
?>

<div>

    <div class="space-y-5 max-w-5xl">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Bankavstämning</h2>
            <a href="{{ route('admin.accounting.bank-transactions.index') }}" wire:navigate
               class="text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                ← Alla transaktioner
            </a>
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800
                        dark:border-green-700/40 dark:bg-green-900/20 dark:text-green-300 flex items-center gap-2">
                <x-bootstrap-icon name="check-circle-fill" class="size-4 shrink-0" />
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800
                        dark:border-red-700/40 dark:bg-red-900/20 dark:text-red-300">
                {{ session('error') }}
            </div>
        @endif

        {{-- Bank account selector + bulk action --}}
        <div class="flex items-center gap-3 flex-wrap">
            <select wire:model.live="bankAccountId" class="inp text-sm">
                <option value="">Välj konto…</option>
                @foreach ($this->bankAccounts as $ba)
                    <option value="{{ $ba->id }}">{{ $ba->name }}</option>
                @endforeach
            </select>

            @php
                $highCount = collect($this->suggestions)
                    ->filter(fn($s) => $s && $s['confidence'] === 'high')
                    ->count();
            @endphp

            @if ($highCount > 0)
                <flux:button wire:click="confirmAll" variant="primary" size="sm"
                             wire:confirm="Bekräfta {{ $highCount }} högsäkra förslag?">
                    <x-bootstrap-icon name="check2-all" class="size-3.5" />
                    Bekräfta {{ $highCount }} säkra förslag
                </flux:button>
            @endif
        </div>

        {{-- Stats bar --}}
        @if ($bankAccountId)
            @php
                $total    = $this->unmatched->count();
                $withHint = collect($this->suggestions)->filter()->count();
                $highConf = $highCount ?? 0;
                $noHint   = $total - $withHint;
            @endphp
            <div class="flex gap-6 text-sm text-zinc-500 dark:text-zinc-400">
                <span>
                    <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $total }}</span>
                    omatchade
                </span>
                <span class="text-green-600 dark:text-green-400">
                    <span class="font-semibold">{{ $highConf }}</span> säkra förslag
                </span>
                @if ($withHint - $highConf > 0)
                    <span class="text-amber-600 dark:text-amber-400">
                        <span class="font-semibold">{{ $withHint - $highConf }}</span> osäkra förslag
                    </span>
                @endif
                @if ($noHint > 0)
                    <span>
                        <span class="font-semibold">{{ $noHint }}</span> utan förslag
                    </span>
                @endif
            </div>
        @endif

        {{-- Main table --}}
        @if ($this->unmatched->isNotEmpty())
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800 text-xs text-zinc-500 dark:text-zinc-400 uppercase tracking-wider text-left">
                        <tr>
                            <th class="px-4 py-3 w-28">Datum</th>
                            <th class="px-4 py-3">Text</th>
                            <th class="px-4 py-3 text-right w-28">Belopp</th>
                            <th class="px-4 py-3 w-6"></th>
                            <th class="px-4 py-3">Förslag</th>
                            <th class="px-4 py-3 w-32"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                        @foreach ($this->unmatched as $tx)
                            @php
                                $s      = $this->suggestions[$tx->id] ?? null;
                                $conf   = $s['confidence'] ?? null;
                                $rowCls = match($conf) {
                                    'high'   => 'bg-green-50/40 dark:bg-green-900/5',
                                    'medium' => 'bg-amber-50/40 dark:bg-amber-900/5',
                                    default  => '',
                                };
                            @endphp
                            <tr class="{{ $rowCls }}">
                                {{-- Transaction --}}
                                <td class="px-4 py-3 font-mono text-xs text-zinc-500 whitespace-nowrap">
                                    {{ $tx->booking_date?->format('d/m Y') ?? $tx->transaction_date?->format('d/m Y') ?? '–' }}
                                </td>
                                <td class="px-4 py-3 max-w-[220px]">
                                    <p class="truncate text-zinc-700 dark:text-zinc-300">{{ $tx->description ?: '–' }}</p>
                                    @if ($tx->reference)
                                        <p class="text-xs text-zinc-400 font-mono truncate">{{ $tx->reference }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3 font-mono text-right whitespace-nowrap
                                           {{ $tx->amount >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                    {{ ($tx->amount >= 0 ? '+' : '') . number_format($tx->amount / 100, 2, ',', ' ') }}
                                </td>

                                {{-- Arrow --}}
                                <td class="px-1 text-center text-zinc-300 dark:text-zinc-600">
                                    @if ($s)
                                        <x-bootstrap-icon name="arrow-right" class="size-3.5" />
                                    @endif
                                </td>

                                {{-- Suggestion --}}
                                <td class="px-4 py-3">
                                    @if ($s)
                                        @php $je = $s['entry']; @endphp
                                        <div class="flex items-start gap-2">
                                            {{-- Confidence badge --}}
                                            @php
                                                $badgeCls = match($conf) {
                                                    'high'   => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
                                                    'medium' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                                                    default  => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400',
                                                };
                                                $badgeLabel = match($conf) {
                                                    'high'   => 'Säker',
                                                    'medium' => 'Möjlig',
                                                    default  => 'Osäker',
                                                };
                                            @endphp
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium shrink-0 {{ $badgeCls }}">
                                                {{ $badgeLabel }}
                                            </span>
                                            <div class="min-w-0">
                                                <p class="text-sm">
                                                    <span class="font-mono font-semibold text-zinc-700 dark:text-zinc-200">
                                                        {{ $je->series }}{{ $je->number }}
                                                    </span>
                                                    <span class="text-zinc-400 text-xs ml-1">{{ $je->date?->format('d/m Y') }}</span>
                                                </p>
                                                <p class="text-xs text-zinc-500 truncate max-w-[220px]">{{ $je->description }}</p>
                                                <p class="text-xs text-zinc-400 mt-0.5">
                                                    @foreach ($je->currentLines->take(3) as $line)
                                                        {{ $line->account->code ?? '?' }}@if(!$loop->last), @endif
                                                    @endforeach
                                                    @if ($s['total_candidates'] > 1)
                                                        <span class="text-zinc-300 dark:text-zinc-600 ml-1">
                                                            (+{{ $s['total_candidates'] - 1 }} alternativ)
                                                        </span>
                                                    @endif
                                                </p>
                                                <p class="text-xs text-zinc-400 italic">{{ $s['reason'] }}</p>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-xs text-zinc-300 dark:text-zinc-600 italic">Inget förslag</span>
                                    @endif
                                </td>

                                {{-- Action --}}
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    @if ($s)
                                        <flux:button wire:click="confirm({{ $tx->id }})"
                                                     variant="{{ $conf === 'high' ? 'primary' : 'ghost' }}"
                                                     size="sm">
                                            Bekräfta
                                        </flux:button>
                                    @endif
                                    <a href="{{ route('admin.accounting.bank-transactions.show', $tx) }}"
                                       wire:navigate
                                       class="ml-2 text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                        Hantera
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        @elseif ($bankAccountId)
            <div class="rounded-lg border border-green-200 bg-green-50 dark:border-green-800/40 dark:bg-green-900/20
                        px-5 py-4 text-sm text-green-800 dark:text-green-300 flex items-center gap-2">
                <x-bootstrap-icon name="check-circle-fill" class="size-4 shrink-0" />
                Inga omatchade transaktioner på det valda kontot. Allt är avstämt!
            </div>
        @endif

    </div>
</div>
