<?php

use App\Models\Accounting\CorporateCard;
use App\Models\Accounting\ExpenseClaim;
use App\Services\Accounting\CardReceiptMatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Mina utlägg');

new class extends Component {
    public bool $embedded = false;

    public function mount(): void
    {
        abort_unless(Auth::user()?->member !== null, 403);
    }

    #[Computed]
    public function member()
    {
        return Auth::user()->member;
    }

    #[Computed]
    public function cards()
    {
        $cutoff = Carbon::now()->subMonths(CorporateCard::RECEIPT_GRACE_MONTHS);

        return CorporateCard::where('member_id', $this->member->id)
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>=', $cutoff);
            })
            ->get();
    }

    #[Computed]
    public function missingReceipts()
    {
        if ($this->cards->isEmpty()) {
            return collect();
        }

        return $this->cards
            ->flatMap(fn ($card) => CardReceiptMatcher::missingReceiptsQuery($card)->get()->all())
            ->sortByDesc('transaction_date')
            ->values();
    }

    #[Computed]
    public function claims()
    {
        return ExpenseClaim::where('member_id', $this->member->id)
            ->with('costCenter', 'corporateCard', 'bankTransaction')
            ->latest('submitted_at')
            ->get();
    }
};
?>

<div>
    @if(!$embedded)
    <x-slot:header>
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold">Mina utlägg</h1>
            <a href="{{ route('expense-claims.create') }}" wire:navigate class="btn btn-primary">
                Skicka in nytt utlägg
            </a>
        </div>
    </x-slot:header>
    @else
    <div class="mb-4 flex justify-end">
        <a href="{{ route('expense-claims.create') }}" wire:navigate class="btn btn-primary">
            Skicka in nytt utlägg
        </a>
    </div>
    @endif

    @if (session('success'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-950 dark:text-green-300">
            {{ session('success') }}
        </div>
    @endif

    {{-- ── Restlista (only for card holders) ─────────────────────────────── --}}
    @if ($this->cards->isNotEmpty() && $this->missingReceipts->isNotEmpty())
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-700/40 dark:bg-amber-900/10">
            <div class="flex items-center gap-2 border-b border-amber-200 dark:border-amber-700/40 px-4 py-3">
                <x-bootstrap-icon name="exclamation-circle-fill" class="size-4 text-amber-600 dark:text-amber-400 shrink-0" />
                <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">
                    Saknade kvitton
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs font-medium text-amber-700 dark:text-amber-400 uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-2 w-28">Datum</th>
                            <th class="px-4 py-2">Butik / Beskrivning</th>
                            <th class="px-4 py-2 w-28 text-right">Belopp</th>
                            <th class="px-4 py-2 w-36"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-amber-100 dark:divide-amber-800/30">
                        @foreach ($this->missingReceipts as $tx)
                            <tr class="hover:bg-amber-50/60 dark:hover:bg-amber-900/10">
                                <td class="px-4 py-2 text-xs font-mono text-amber-700 dark:text-amber-400 whitespace-nowrap">
                                    {{ $tx->transaction_date?->format('d/m Y') ?? '–' }}
                                </td>
                                <td class="px-4 py-2 text-xs text-zinc-700 dark:text-zinc-300">
                                    {{ $tx->description }}
                                    @if ($tx->reference)
                                        <span class="font-mono text-zinc-400 dark:text-zinc-500"> · {{ $tx->reference }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right font-mono text-xs font-semibold text-zinc-800 dark:text-zinc-100 tabular-nums whitespace-nowrap">
                                    {{ format_ore(abs($tx->amount)) }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <a href="{{ route('expense-claims.create', ['tx' => $tx->id]) }}"
                                       wire:navigate
                                       class="inline-flex items-center gap-1 text-xs font-medium text-complement-light hover:underline">
                                        <x-bootstrap-icon name="upload" class="size-3.5" />
                                        Skicka kvitto
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 text-xs text-amber-700 dark:text-amber-400">
                Kassören kan inte bokföra dessa köp utan kvitto. Skicka in så snart du kan.
            </div>
        </div>
    @elseif ($this->cards->isNotEmpty() && $this->missingReceipts->isEmpty())
        <div class="mb-6 flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 dark:border-green-700/40 dark:bg-green-900/10 px-4 py-3">
            <x-bootstrap-icon name="check-circle-fill" class="size-4 text-green-500 shrink-0" />
            <p class="text-sm text-green-700 dark:text-green-300">
                Alla kortköp har kvitto. Bra jobbat!
            </p>
        </div>
    @endif

    {{-- ── Submitted claims ────────────────────────────────────────────────── --}}
    @if ($this->claims->isEmpty())
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 px-6 py-12 text-center">
            <x-bootstrap-icon name="receipt" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Du har inga inlämnade utlägg ännu.</p>
            <a href="{{ route('expense-claims.create') }}" wire:navigate class="btn btn-primary mt-4 inline-flex">
                Skicka in utlägg
            </a>
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3 w-28">Inköpsdatum</th>
                        <th class="px-4 py-3">Beskrivning</th>
                        <th class="px-4 py-3 w-32 text-right">Belopp</th>
                        <th class="px-4 py-3 w-36">Status</th>
                        <th class="px-4 py-3 w-24">Kvitto</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($this->claims as $claim)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <td class="px-4 py-3 text-zinc-500 text-xs">
                                {{ $claim->purchased_at->format('d/m Y') }}
                            </td>
                            <td class="px-4 py-3 text-zinc-800 dark:text-zinc-200">
                                {{ $claim->description }}
                                <div class="flex flex-wrap gap-1 mt-0.5">
                                    @if ($claim->isCardReceipt())
                                        <span class="inline-flex items-center gap-1 text-xs text-zinc-400">
                                            <x-bootstrap-icon name="credit-card" class="size-3" />
                                            {{ $claim->corporateCard?->card_reference }}
                                        </span>
                                    @endif
                                    @if ($claim->costCenter)
                                        <span class="text-xs text-zinc-400">· {{ $claim->costCenter->name }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-xs tabular-nums">
                                {{ format_ore($claim->amount_ore) }}
                            </td>
                            <td class="px-4 py-3">
                                @if ($claim->isSubmitted())
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                                        <x-bootstrap-icon name="hourglass-split" class="size-3" />
                                        Granskas
                                    </span>
                                @elseif ($claim->isApproved())
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">
                                        <x-bootstrap-icon name="check-circle-fill" class="size-3" />
                                        {{ $claim->isCardReceipt() ? 'Mottaget' : 'Godkänt' }}
                                    </span>
                                @elseif ($claim->isRejected())
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">
                                        <x-bootstrap-icon name="x-circle-fill" class="size-3" />
                                        Avvisat
                                    </span>
                                    @if ($claim->review_note)
                                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400 italic">{{ $claim->review_note }}</p>
                                    @endif
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <a href="{{ route('expense-claims.receipt.inline', $claim) }}"
                                   target="_blank"
                                   class="inline-flex items-center gap-1 text-xs text-complement-light hover:underline">
                                    <x-bootstrap-icon name="file-earmark" class="size-3.5" />
                                    Visa
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
