<?php

use App\Models\Accounting\BankAccount;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\Vendor;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Banktransaktioner');

new class extends Component {

    public ?int    $bankAccountId = null;
    public ?int    $vendorId      = null;
    public string  $status        = '';
    public string  $search        = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->canAny(['accounting.view', 'accounting.manage']), 403);
    }

    #[Computed]
    public function bankAccounts()
    {
        return BankAccount::orderBy('name')->get();
    }

    #[Computed]
    public function vendors()
    {
        // Only vendors that actually appear on at least one transaction
        return Vendor::whereHas('bankTransactions')->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function transactions()
    {
        $q = BankTransaction::with(['bankAccount', 'vendor'])
            ->orderByDesc('booking_date')
            ->orderByDesc('id');

        if ($this->bankAccountId) {
            $q->where('bank_account_id', $this->bankAccountId);
        }

        if ($this->vendorId) {
            $q->where('vendor_id', $this->vendorId);
        }

        if ($this->status !== '') {
            $q->where('status', $this->status);
        }

        if ($this->search !== '') {
            $q->where(function ($q2) {
                $q2->where('description', 'like', "%{$this->search}%")
                   ->orWhere('reference',  'like', "%{$this->search}%");
            });
        }

        return $q->paginate(50);
    }
};
?>

<div>

    <div class="space-y-4">

        {{-- Filters --}}
        <div class="flex flex-wrap gap-3">
            <select wire:model.live="bankAccountId" class="inp text-sm">
                <option value="">Alla konton</option>
                @foreach ($this->bankAccounts as $ba)
                    <option value="{{ $ba->id }}">{{ $ba->name }}</option>
                @endforeach
            </select>

            @if ($this->vendors->isNotEmpty())
                <select wire:model.live="vendorId" class="inp text-sm">
                    <option value="">Alla leverantörer</option>
                    @foreach ($this->vendors as $v)
                        <option value="{{ $v->id }}">{{ $v->name }}</option>
                    @endforeach
                </select>
            @endif

            <select wire:model.live="status" class="inp text-sm">
                <option value="">Alla statusar</option>
                <option value="unmatched">Omatchade</option>
                <option value="matched">Matchade</option>
                <option value="posted">Bokförda</option>
                <option value="ignored">Ignorerade</option>
            </select>

            <input type="search"
                   wire:model.live.debounce.300ms="search"
                   placeholder="Sök referens eller text…"
                   class="inp text-sm w-64" />
        </div>

        {{-- Table --}}
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800 text-xs text-zinc-500 dark:text-zinc-400 uppercase tracking-wider text-left">
                    <tr>
                        <th class="px-4 py-3 w-28">Bokfdag</th>
                        <th class="px-4 py-3 w-28">Transdag</th>
                        <th class="px-4 py-3">Referens</th>
                        <th class="px-4 py-3">Text / Leverantör</th>
                        <th class="px-4 py-3 text-right w-32">Belopp</th>
                        <th class="px-4 py-3 text-right w-32">Saldo</th>
                        <th class="px-4 py-3 w-28 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($this->transactions as $tx)
                        @php
                            $statusMap = [
                                'unmatched' => ['Omatchad', 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'],
                                'matched'   => ['Matchad',  'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'],
                                'posted'    => ['Bokförd',  'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'],
                                'ignored'   => ['Ignorerad','bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400'],
                            ];
                            [$label, $cls] = $statusMap[$tx->status] ?? ['Okänd', ''];
                        @endphp
                        <tr wire:navigate
                            onclick="window.location='{{ route('admin.accounting.bank-transactions.show', $tx) }}'"
                            class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50 {{ $tx->status === 'unmatched' ? 'bg-amber-50/30 dark:bg-amber-900/5' : '' }}">
                            <td class="px-4 py-2 font-mono text-xs text-zinc-500">
                                {{ $tx->booking_date?->format('d/m Y') ?? '–' }}
                            </td>
                            <td class="px-4 py-2 font-mono text-xs text-zinc-400">
                                {{ $tx->transaction_date?->format('d/m Y') ?? '–' }}
                            </td>
                            <td class="px-4 py-2 text-xs text-zinc-500 max-w-[140px] truncate">
                                {{ $tx->reference }}
                            </td>
                            <td class="px-4 py-2">
                                <div class="text-zinc-700 dark:text-zinc-300">{{ $tx->description }}</div>
                                @if ($tx->vendor)
                                    <div class="text-xs text-zinc-400 flex items-center gap-1 mt-0.5">
                                        <x-bootstrap-icon name="building" class="size-3 shrink-0" />
                                        {{ $tx->vendor->name }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-2 font-mono text-right {{ $tx->amount >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                {{ number_format($tx->amount / 100, 2, ',', ' ') }}
                            </td>
                            <td class="px-4 py-2 font-mono text-right text-zinc-400 text-xs">
                                {{ $tx->balance_ore !== null ? number_format($tx->balance_ore / 100, 2, ',', ' ') : '–' }}
                            </td>
                            <td class="px-4 py-2 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $cls }}">
                                    {{ $label }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-sm text-zinc-400">
                                Inga transaktioner. Importera ett kontoutdrag för att komma igång.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $this->transactions->links() }}

    </div>
</div>
