<?php

use App\Mail\MembershipActivated;
use App\Models\AuditLog;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipFee;
use App\Models\Membership\MembershipPeriod;
use App\Models\Shop\Order;
use App\Services\BgMaxParser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Betalningshantering');

new class extends Component {
    use WithFileUploads;

    public string $activeTab    = 'pending';
    public string $search       = '';
    public string $confirmNote  = '';
    public string $rejectNote   = '';

    // Import state
    public $importFile          = null;
    public array $importRows    = [];
    public bool $importParsed   = false;
    public bool $importDone     = false;
    public string $importError  = '';
    public int $importConfirmed = 0;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('payments.view'), 403);
    }

    #[\Livewire\Attributes\Computed]
    public function pendingPeriods(): Collection
    {
        $search = $this->search;

        $periods = MembershipPeriod::with(['member', 'order'])
            ->where('status', MembershipPeriod::STATUS_PENDING_PAYMENT)
            ->when($search, function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->whereHas('member', fn ($q2) =>
                    $q2->where('first_name', 'like', $like)
                       ->orWhere('last_name', 'like', $like)
                       ->orWhere('membership_number', 'like', $like)
                );
            })
            ->orderBy('created_at')
            ->get();

        // Batch-load fees as fallback for legacy periods without an order
        $feeMap = MembershipFee::whereIn('year', $periods->pluck('year')->unique())
            ->get()
            ->groupBy('year')
            ->map(fn ($fees) => $fees->keyBy('membership_type'));

        return $periods->map(function ($period) use ($feeMap) {
            if ($period->order) {
                $period->computed_reference = $period->order->payment_reference ?? '';
                $period->computed_ocr       = $period->order->ocr_number ?? '';
                $period->computed_amount    = $period->order->total_ore;
            } else {
                $period->computed_reference = '';
                $period->computed_ocr       = '';
                $fee = $feeMap[$period->year][$period->membership_type] ?? null;
                $period->computed_amount = $fee?->amount ?? 0;
            }
            return $period;
        });
    }

    #[\Livewire\Attributes\Computed]
    public function recentPeriods(): Collection
    {
        return MembershipPeriod::with(['member', 'order'])
            ->where('status', MembershipPeriod::STATUS_ACTIVE)
            ->where('activated_at', '>=', now()->subDays(30))
            ->orderByDesc('activated_at')
            ->get();
    }

    // Show the refusal instead of a bare 403, so a denied action isn't mistaken for a saved one
    private function deniedConfirm(): bool
    {
        if (Auth::user()->hasPermissionTo('payments.confirm')) {
            return false;
        }

        $this->addError('payment', 'Du har inte behörighet att bekräfta eller avslå betalningar. Ingenting sparades.');

        return true;
    }

    public function confirmPayment(int $periodId): void
    {
        if ($this->deniedConfirm()) {
            return;
        }

        $period = MembershipPeriod::with('order')->findOrFail($periodId);

        if ($period->status === MembershipPeriod::STATUS_ACTIVE) {
            return;
        }

        $note  = trim($this->confirmNote);
        $order = $period->order;

        if ($order && $order->isPending()) {
            // The HandleOrderPaid listener will activate the period and send mail
            $order->markPaid(Order::METHOD_MANUAL);
        } else {
            // No order (legacy period) — activate directly
            $period->update([
                'status'       => MembershipPeriod::STATUS_ACTIVE,
                'activated_at' => now(),
            ]);

            $member = $period->member;
            if ($member && $member->status !== Member::STATUS_ACTIVE) {
                $member->update(['status' => Member::STATUS_ACTIVE]);
            }

            if ($member?->email) {
                try {
                    Mail::to($member->email)->queue(new MembershipActivated($member, $period));
                } catch (\Throwable $e) {
                    Log::error('MembershipActivated mail failed', ['member_id' => $member->id, 'error' => $e->getMessage()]);
                }
            }
        }

        AuditLog::record(
            'membership_period_activated',
            'membership_period',
            'Betalning bekräftad manuellt' . ($note ? ": {$note}" : ''),
            $period,
            ['method' => Order::METHOD_MANUAL, 'member_id' => $period->member?->id]
        );

        $this->confirmNote = '';
        $this->dispatch('payment-handled', id: $period->id);
    }

    public function rejectPayment(int $periodId): void
    {
        if ($this->deniedConfirm()) {
            return;
        }

        $period = MembershipPeriod::with('order')->findOrFail($periodId);

        if ($period->status === MembershipPeriod::STATUS_ACTIVE) {
            return;
        }

        $note  = trim($this->rejectNote);
        $order = $period->order;

        if ($order && $order->isPending()) {
            // HandleOrderPaymentFailed sends the member a "try again" mail
            $order->markFailed('admin_rejected' . ($note ? ": {$note}" : ''));
        }

        AuditLog::record(
            'membership_payment_rejected',
            'membership_period',
            'Betalning avslagen manuellt' . ($note ? ": {$note}" : ''),
            $period,
            ['member_id' => $period->member?->id]
        );

        $this->rejectNote = '';
        $this->dispatch('payment-handled', id: $period->id);
    }

    public function parseImport(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('payments.import'), 403);

        $this->importError   = '';
        $this->importRows    = [];
        $this->importParsed  = false;
        $this->importDone    = false;
        $this->importConfirmed = 0;

        $this->validate([
            'importFile' => ['required', 'file', 'mimes:txt,csv,dat', 'max:4096'],
        ]);

        $content = file_get_contents($this->importFile->getRealPath());

        try {
            $transactions = (new BgMaxParser())->parse($content);
        } catch (\InvalidArgumentException $e) {
            $this->importError = $e->getMessage();
            return;
        } catch (\Throwable $e) {
            $this->importError = 'Kunde inte läsa filen: ' . $e->getMessage();
            return;
        }

        if (empty($transactions)) {
            $this->importError = 'Inga betalningsposter hittades i filen.';
            return;
        }

        // Build reference map — indexed by both payment_reference and ocr_number
        $pending = MembershipPeriod::with(['member', 'order'])
            ->where('status', MembershipPeriod::STATUS_PENDING_PAYMENT)
            ->get();

        $feeMap = MembershipFee::whereIn('year', $pending->pluck('year')->unique())
            ->get()
            ->groupBy('year')
            ->map(fn ($fees) => $fees->keyBy('membership_type'));

        $refMap = collect();
        foreach ($pending as $period) {
            if ($period->order) {
                $expectedAmount = $period->order->total_ore;
                $entry          = ['period' => $period, 'expectedAmountOre' => $expectedAmount];

                if ($period->order->payment_reference) {
                    $refMap[strtoupper($period->order->payment_reference)] = $entry;
                }
                if ($period->order->ocr_number) {
                    $refMap[$period->order->ocr_number] = $entry;
                }
            } else {
                // Legacy period without order
                $fee            = $feeMap[$period->year][$period->membership_type] ?? null;
                $expectedAmount = $fee?->amount ?? 0;
                // No reliable reference to match against — leave unmatched
            }
        }

        $rows = [];
        foreach ($transactions as $tx) {
            $key   = strtoupper(trim($tx['reference']));
            $match = $refMap->get($key);

            $rows[] = [
                'reference'         => $tx['reference'],
                'amountOre'         => $tx['amountOre'],
                'date'              => $tx['date'],
                'sender'            => $tx['sender'],
                'periodId'          => $match ? $match['period']->id : null,
                'memberName'        => $match ? $match['period']->member->full_name : null,
                'expectedAmountOre' => $match ? $match['expectedAmountOre'] : null,
                'amountMatches'     => $match !== null &&
                    abs($match['expectedAmountOre'] - $tx['amountOre']) < 100,
                'selected'          => $match !== null,
            ];
        }

        $this->importRows   = $rows;
        $this->importParsed = true;
    }

    public function toggleImportRow(int $index): void
    {
        if (isset($this->importRows[$index])) {
            $this->importRows[$index]['selected'] = ! $this->importRows[$index]['selected'];
        }
    }

    public function confirmImport(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('payments.import'), 403);

        $confirmed = 0;

        foreach ($this->importRows as $row) {
            if (! $row['selected'] || ! $row['periodId']) {
                continue;
            }

            $period = MembershipPeriod::with('order')->find($row['periodId']);
            if (! $period || $period->status !== MembershipPeriod::STATUS_PENDING_PAYMENT) {
                continue;
            }

            $order = $period->order;

            if ($order && $order->isPending()) {
                // The HandleOrderPaid listener will activate the period and send mail
                $order->markPaid(Order::METHOD_BANKGIRO);
            } else {
                // No order (legacy period) — activate directly
                $period->update([
                    'status'       => MembershipPeriod::STATUS_ACTIVE,
                    'activated_at' => now(),
                ]);

                $member = $period->member;
                if ($member && $member->status !== Member::STATUS_ACTIVE) {
                    $member->update(['status' => Member::STATUS_ACTIVE]);
                }

                if ($member?->email) {
                    try {
                        Mail::to($member->email)->queue(new MembershipActivated($member, $period));
                    } catch (\Throwable $e) {
                        Log::error('MembershipActivated mail failed', ['member_id' => $member->id, 'error' => $e->getMessage()]);
                    }
                }
            }

            AuditLog::record(
                'membership_period_activated',
                'membership_period',
                "Betalning bekräftad via bankgiro-import (ref: {$row['reference']}, datum: {$row['date']})",
                $period,
                ['method' => Order::METHOD_BANKGIRO, 'reference' => $row['reference'], 'amount_ore' => $row['amountOre']]
            );

            $confirmed++;
        }

        $this->importConfirmed = $confirmed;
        $this->importRows      = [];
        $this->importParsed    = false;
        $this->importDone      = true;
    }
}; ?>

<div class="container mx-auto max-w-5xl px-4 py-8 sm:px-6">

    <div class="mb-6">
        <div class="flex items-center gap-2">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Betalningshantering</h1>
            <x-permission-hint permission="payments.view" />
        </div>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            Bekräfta inkomna betalningar och importera bankgiro-filer.
        </p>
    </div>

    {{-- Tab bar --}}
    <div class="mb-6 flex gap-1 rounded-xl border border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-zinc-800/50">
        <button wire:click="$set('activeTab', 'pending')"
                @class(['flex-1 rounded-lg py-2 text-sm font-medium transition-colors',
                        'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white'     => $activeTab === 'pending',
                        'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' => $activeTab !== 'pending'])>
            <span class="inline-flex items-center gap-1.5">
                Väntande
                <x-permission-hint permission="payments.confirm" />
            </span>
            @if($this->pendingPeriods->isNotEmpty())
                <span class="ml-1.5 inline-flex size-5 items-center justify-center rounded-full bg-brand text-xs font-bold text-brand-dark">
                    {{ $this->pendingPeriods->count() }}
                </span>
            @endif
        </button>
        @can('payments.import')
        <button wire:click="$set('activeTab', 'import')"
                @class(['flex-1 rounded-lg py-2 text-sm font-medium transition-colors',
                        'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white'     => $activeTab === 'import',
                        'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' => $activeTab !== 'import'])>
            <span class="inline-flex items-center gap-1.5">
                Bankgiro-import
                <x-permission-hint permission="payments.import" />
            </span>
        </button>
        @endcan
        <button wire:click="$set('activeTab', 'recent')"
                @class(['flex-1 rounded-lg py-2 text-sm font-medium transition-colors',
                        'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white'     => $activeTab === 'recent',
                        'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' => $activeTab !== 'recent'])>
            Senaste 30 dagarna
        </button>
    </div>

    {{-- ═══ TAB: VÄNTANDE ═══ --}}
    @if($activeTab === 'pending')
        <div class="space-y-4">

            @error('payment')
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800/40 dark:bg-red-900/20 dark:text-red-400">
                    {{ $message }}
                </div>
            @enderror

            {{-- Search --}}
            <div class="flex items-center gap-3">
                <div class="relative flex-1">
                    <x-bootstrap-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
                    <input wire:model.live.debounce.300ms="search"
                           type="search" placeholder="Sök namn eller medlemsnummer…"
                           class="inp w-full pl-9" />
                </div>
            </div>

            {{-- Pending list --}}
            @if($this->pendingPeriods->isEmpty())
                <div class="rounded-xl border border-zinc-200 bg-white py-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
                    <x-bootstrap-icon name="check-circle" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $search ? 'Inga träffar.' : 'Inga väntande betalningar.' }}
                    </p>
                </div>
            @else
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <table class="w-full text-sm">
                        <thead class="border-b border-zinc-100 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-800">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-zinc-500 dark:text-zinc-400">Medlem</th>
                                <th class="px-4 py-3 text-left font-semibold text-zinc-500 dark:text-zinc-400">Referens</th>
                                <th class="px-4 py-3 text-left font-semibold text-zinc-500 dark:text-zinc-400">Metod</th>
                                <th class="px-4 py-3 text-right font-semibold text-zinc-500 dark:text-zinc-400">Belopp</th>
                                <th class="px-4 py-3 text-left font-semibold text-zinc-500 dark:text-zinc-400">Inlämnad</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        {{-- One tbody per period so the inline forms share the row's Alpine state --}}
                        @foreach($this->pendingPeriods as $period)
                            <tbody wire:key="pending-{{ $period->id }}"
                                   x-data="{ confirming: false, rejecting: false }"
                                   x-on:payment-handled.window="if ($event.detail.id === {{ $period->id }}) { confirming = false; rejecting = false }"
                                   class="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                                <tr class="group">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.members.show', $period->member) }}"
                                           class="font-medium text-zinc-900 hover:text-complement dark:text-white dark:hover:text-complement-light">
                                            {{ $period->member->full_name }}
                                        </a>
                                        <span class="ml-1.5 text-xs text-zinc-400">
                                            @switch($period->membership_type)
                                                @case('single') Vuxen @break
                                                @case('family') Familj @break
                                                @case('youth')  Ungdom @break
                                                @case('child')  Barn @break
                                                @case('student') Student @break
                                                @case('senior') Senior @break
                                                @case('honorary') Hedersmedlem @break
                                                @default {{ $period->membership_type }}
                                            @endswitch
                                            {{ $period->year }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                                        {{ $period->computed_reference }}
                                    </td>
                                    <td class="px-4 py-3">
                                        @php $pendingMethod = $period->order?->payment_method @endphp
                                        @if($pendingMethod === 'bankgiro')
                                            <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                                                <x-bootstrap-icon name="bank" class="size-3" /> Bankgiro
                                            </span>
                                        @elseif(in_array($pendingMethod, ['swish', 'swish_handel']))
                                            <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/40 dark:text-green-300">
                                                <x-bootstrap-icon name="phone" class="size-3" /> Swish
                                            </span>
                                        @elseif($pendingMethod === 'stripe')
                                            <span class="inline-flex items-center gap-1 rounded-full bg-violet-100 px-2 py-0.5 text-xs font-medium text-violet-700 dark:bg-violet-900/40 dark:text-violet-300">
                                                <x-bootstrap-icon name="credit-card" class="size-3" /> Stripe
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                                <x-bootstrap-icon name="dash" class="size-3" /> Ej valt
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold text-zinc-900 dark:text-white">
                                        {{ $period->computed_amount > 0 ? number_format($period->computed_amount / 100, 2, ',', ' ') . ' kr' : '–' }}
                                    </td>
                                    <td class="px-4 py-3 text-xs text-zinc-400">
                                        {{ $period->created_at->format('d/m Y') }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        @can('payments.confirm')
                                        <div class="flex justify-end gap-2">
                                            <button type="button" @click="confirming = true" x-show="!confirming && !rejecting"
                                                    class="btn btn-primary btn-sm">
                                                Bekräfta
                                            </button>
                                            <button type="button" @click="rejecting = true" x-show="!confirming && !rejecting"
                                                    class="btn btn-danger btn-sm">
                                                Avslå
                                            </button>
                                        </div>
                                        @endcan
                                    </td>
                                </tr>
                                @can('payments.confirm')
                                {{-- Inline confirm form --}}
                                <tr x-show="confirming" x-cloak
                                    class="bg-zinc-50 dark:bg-zinc-800/50">
                                    <td colspan="6" class="px-4 py-4">
                                        <div class="flex items-end gap-3">
                                            <div class="flex-1">
                                                <label class="lbl">Notering <span class="font-normal text-zinc-400">(valfritt)</span></label>
                                                <input wire:model="confirmNote" type="text"
                                                       placeholder="t.ex. Swish mottagen 2025-03-15"
                                                       class="inp mt-1 w-full" />
                                            </div>
                                            <button type="button"
                                                    wire:click="confirmPayment({{ $period->id }})"
                                                    wire:loading.attr="disabled"
                                                    class="btn btn-primary">
                                                <span wire:loading.remove wire:target="confirmPayment({{ $period->id }})">Bekräfta betalning</span>
                                                <span wire:loading wire:target="confirmPayment({{ $period->id }})">Sparar…</span>
                                            </button>
                                            <button type="button" @click="confirming = false"
                                                    class="btn btn-ghost">
                                                Avbryt
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                {{-- Inline reject form --}}
                                <tr x-show="rejecting" x-cloak
                                    class="bg-zinc-50 dark:bg-zinc-800/50">
                                    <td colspan="6" class="px-4 py-4">
                                        <div class="flex items-end gap-3">
                                            <div class="flex-1">
                                                <label class="lbl">Anledning <span class="font-normal text-zinc-400">(valfritt)</span></label>
                                                <input wire:model="rejectNote" type="text"
                                                       placeholder="t.ex. Kortet nekades, kontaktade medlemmen"
                                                       class="inp mt-1 w-full" />
                                            </div>
                                            <button type="button"
                                                    wire:click="rejectPayment({{ $period->id }})"
                                                    wire:loading.attr="disabled"
                                                    class="btn btn-danger">
                                                <span wire:loading.remove wire:target="rejectPayment({{ $period->id }})">Avslå betalning</span>
                                                <span wire:loading wire:target="rejectPayment({{ $period->id }})">Sparar…</span>
                                            </button>
                                            <button type="button" @click="rejecting = false"
                                                    class="btn btn-ghost">
                                                Avbryt
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                @endcan
                            </tbody>
                        @endforeach
                    </table>
                </div>
            @endif
        </div>
    @endif

    {{-- ═══ TAB: BANKGIRO-IMPORT ═══ --}}
    @if($activeTab === 'import' && auth()->user()->can('payments.import'))
        <div class="space-y-6">

            {{-- Import done --}}
            @if($importDone)
                <div class="rounded-xl border border-green-200 bg-green-50 p-5 dark:border-green-800/40 dark:bg-green-900/20">
                    <div class="flex items-center gap-3">
                        <x-bootstrap-icon name="check-circle-fill" class="size-5 shrink-0 text-green-600 dark:text-green-400" />
                        <p class="text-sm font-medium text-green-800 dark:text-green-300">
                            {{ $importConfirmed }} betalning{{ $importConfirmed !== 1 ? 'ar' : '' }} bekräftad{{ $importConfirmed !== 1 ? 'e' : '' }}.
                        </p>
                    </div>
                </div>
                <button wire:click="$set('importDone', false)" class="btn btn-ghost btn-sm">
                    Importera fler
                </button>
            @else

                {{-- Upload form --}}
                @if(! $importParsed)
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                        <h2 class="mb-1 text-sm font-semibold text-zinc-700 dark:text-zinc-300">Ladda upp betalfil</h2>
                        <p class="mb-4 text-xs text-zinc-400 dark:text-zinc-500">
                            Stöder <strong class="text-zinc-600 dark:text-zinc-300">BGMax</strong> (Bankgirot standard, TK01-poster)
                            och <strong class="text-zinc-600 dark:text-zinc-300">CSV</strong> med kolumnerna
                            <span class="font-mono">datum;referens;belopp[;avsändare]</span>.
                        </p>

                        <div class="flex items-end gap-4">
                            <div class="flex-1">
                                <label class="lbl">Fil <span class="font-normal text-zinc-400">(.txt, .csv, .dat)</span></label>
                                <input wire:model="importFile" type="file" accept=".txt,.csv,.dat"
                                       class="mt-1 block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:file:text-zinc-200" />
                                @error('importFile') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <button wire:click="parseImport" wire:loading.attr="disabled"
                                    class="btn btn-primary">
                                <span wire:loading.remove wire:target="parseImport">Analysera fil</span>
                                <span wire:loading wire:target="parseImport">Analyserar…</span>
                            </button>
                        </div>

                        @if($importError)
                            <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800/40 dark:bg-red-900/20 dark:text-red-400">
                                {{ $importError }}
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Parse results --}}
                @if($importParsed && ! empty($importRows))
                    @php
                        $matchedCount   = collect($importRows)->where('periodId', '!=', null)->count();
                        $selectedCount  = collect($importRows)->where('selected', true)->count();
                    @endphp

                    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="flex items-center justify-between border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                            <div>
                                <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                                    {{ count($importRows) }} poster hittades
                                </span>
                                <span class="ml-2 text-xs text-zinc-400">
                                    {{ $matchedCount }} matchade mot väntande betalningar
                                </span>
                            </div>
                            <button wire:click="$set('importParsed', false)" class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                                ← Ny fil
                            </button>
                        </div>

                        <table class="w-full text-sm">
                            <thead class="border-b border-zinc-100 dark:border-zinc-800">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold text-zinc-500 dark:text-zinc-400">Datum</th>
                                    <th class="px-4 py-3 text-left font-semibold text-zinc-500 dark:text-zinc-400">Referens</th>
                                    <th class="px-4 py-3 text-right font-semibold text-zinc-500 dark:text-zinc-400">Belopp</th>
                                    <th class="px-4 py-3 text-left font-semibold text-zinc-500 dark:text-zinc-400">Matchning</th>
                                    <th class="px-4 py-3 text-center font-semibold text-zinc-500 dark:text-zinc-400">Bekräfta</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach($importRows as $i => $row)
                                    <tr @class(['opacity-50' => ! $row['periodId']])>
                                        <td class="px-4 py-3 text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ \Carbon\Carbon::parse($row['date'])->format('d/m Y') }}
                                        </td>
                                        <td class="px-4 py-3 font-mono text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                                            {{ $row['reference'] }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold text-zinc-900 dark:text-white">
                                            {{ number_format($row['amountOre'] / 100, 2, ',', ' ') }} kr
                                            @if($row['expectedAmountOre'] !== null && ! $row['amountMatches'])
                                                <span class="ml-1 text-xs font-normal text-amber-600 dark:text-amber-400">
                                                    (förv. {{ number_format($row['expectedAmountOre'] / 100, 2, ',', ' ') }} kr)
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            @if($row['memberName'])
                                                <span class="text-zinc-900 dark:text-white">{{ $row['memberName'] }}</span>
                                                @if(! $row['amountMatches'])
                                                    <span class="ml-1.5 inline-flex items-center gap-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-xs text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                                                        <x-bootstrap-icon name="exclamation-triangle" class="size-3" /> Belopp avviker
                                                    </span>
                                                @endif
                                            @else
                                                <span class="inline-flex items-center gap-1 text-xs text-zinc-400 dark:text-zinc-500">
                                                    <x-bootstrap-icon name="question-circle" class="size-3.5" /> Ingen matchning
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            @if($row['periodId'])
                                                <input type="checkbox"
                                                       wire:click="toggleImportRow({{ $i }})"
                                                       @checked($row['selected'])
                                                       class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600" />
                                            @else
                                                <span class="text-xs text-zinc-300 dark:text-zinc-600">–</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        <div class="flex items-center justify-between border-t border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $selectedCount }} valda att bekräfta
                            </span>
                            <button wire:click="confirmImport" wire:loading.attr="disabled"
                                    @disabled($selectedCount === 0)
                                    class="btn btn-primary">
                                <span wire:loading.remove wire:target="confirmImport">Bekräfta {{ $selectedCount }} betalning{{ $selectedCount !== 1 ? 'ar' : '' }}</span>
                                <span wire:loading wire:target="confirmImport">Bekräftar…</span>
                            </button>
                        </div>
                    </div>
                @endif

            @endif
        </div>
    @endif

    {{-- ═══ TAB: SENASTE ═══ --}}
    @if($activeTab === 'recent')
        @if($this->recentPeriods->isEmpty())
            <div class="rounded-xl border border-zinc-200 bg-white py-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <x-bootstrap-icon name="clock-history" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Inga bekräftade betalningar de senaste 30 dagarna.</p>
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <table class="w-full text-sm">
                    <thead class="border-b border-zinc-100 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-500 dark:text-zinc-400">Aktiverad</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-500 dark:text-zinc-400">Medlem</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-500 dark:text-zinc-400">Typ / År</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-500 dark:text-zinc-400">Metod</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach($this->recentPeriods as $period)
                            <tr>
                                <td class="px-4 py-3 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $period->activated_at?->format('d/m Y H:i') }}
                                </td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('admin.members.show', $period->member) }}"
                                       class="font-medium text-zinc-900 hover:text-complement dark:text-white dark:hover:text-complement-light">
                                        {{ $period->member->full_name }}
                                    </a>
                                </td>
                                <td class="px-4 py-3 text-xs text-zinc-500 dark:text-zinc-400">
                                    @switch($period->membership_type)
                                        @case('single') Vuxen @break
                                        @case('family') Familj @break
                                        @case('youth')  Ungdom @break
                                        @case('child')  Barn @break
                                        @case('student') Student @break
                                        @case('senior') Senior @break
                                        @case('honorary') Hedersmedlem @break
                                        @default {{ $period->membership_type }}
                                    @endswitch
                                    {{ $period->year }}
                                </td>
                                <td class="px-4 py-3">
                                    @php $paidMethod = $period->order?->payment_method @endphp
                                    @if($paidMethod === 'stripe')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-violet-100 px-2 py-0.5 text-xs font-medium text-violet-700 dark:bg-violet-900/40 dark:text-violet-300">
                                            <x-bootstrap-icon name="credit-card" class="size-3" /> Stripe
                                        </span>
                                    @elseif(in_array($paidMethod, ['swish', 'swish_handel']))
                                        <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/40 dark:text-green-300">
                                            <x-bootstrap-icon name="phone" class="size-3" /> Swish
                                        </span>
                                    @elseif($paidMethod === 'bankgiro')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                                            <x-bootstrap-icon name="bank" class="size-3" /> Bankgiro
                                        </span>
                                    @elseif($paidMethod === 'free')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                            <x-bootstrap-icon name="gift" class="size-3" /> Gratis
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                            <x-bootstrap-icon name="person-check" class="size-3" /> Manuell
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif

</div>
