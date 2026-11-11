<?php

use App\Models\AuditLog;
use App\Models\Shop\DiscountCode;
use App\Models\Events\EventRegistration;
use App\Models\Events\EventRegistrationOccurrence;
use App\Services\ScannerService;
use App\Services\ScanResult;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Skanningsstation');

new class extends Component {

    public string  $scanInput    = '';
    public ?string $resultType   = null;   // ScanResult::TYPE_*
    public ?string $resultStatus = null;   // ScanResult::STATUS_*
    public ?int    $modelId      = null;
    public ?string $message      = null;
    public bool    $justActioned = false;  // true right after redeem / check-in

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('payments.view'), 403);
    }

    // -------------------------------------------------------------------------
    // Computed model — loaded fresh from DB on each render
    // -------------------------------------------------------------------------

    #[Computed]
    public function discountCode(): ?DiscountCode
    {
        return $this->resultType === ScanResult::TYPE_DISCOUNT && $this->modelId
            ? DiscountCode::with(['discount', 'redeemedBy'])->find($this->modelId)
            : null;
    }

    #[Computed]
    public function registration(): ?EventRegistration
    {
        return $this->resultType === ScanResult::TYPE_EVENT_REGISTRATION && $this->modelId
            ? EventRegistration::with(['event.occurrences', 'user', 'priceTier'])->find($this->modelId)
            : null;
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    public function lookup(): void
    {
        $this->resetResult();

        $raw = trim($this->scanInput);
        $this->scanInput = '';

        if ($raw === '') {
            return;
        }

        $result = app(ScannerService::class)->resolve($raw);

        if ($result->type === ScanResult::TYPE_EMPTY) {
            return;
        }

        $this->resultType   = $result->type;
        $this->resultStatus = $result->status;
        $this->modelId      = $result->model?->getKey();
        $this->message      = $result->message;

        unset($this->discountCode);
        unset($this->registration);
    }

    /**
     * Redeem a discount code (single-use) or acknowledge a shared code.
     */
    public function redeemDiscount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('payments.view'), 403);

        $code = $this->discountCode;

        if (! $code || ! $code->isAvailable()) {
            $this->message      = 'Koden är inte längre tillgänglig.';
            $this->resultStatus = ScanResult::STATUS_INVALID;
            return;
        }

        $code->redeem();

        AuditLog::record(
            'discount_code_redeemed',
            'discount',
            "Rabattkod \"{$code->code}\" inlöst via skanningsstation (rabatt: {$code->discount->name})"
        );

        $this->justActioned = true;
        $this->message      = null;
        unset($this->discountCode);
    }

    /**
     * Check in an event registration to the earliest upcoming (or current) occurrence.
     */
    public function checkInRegistration(): void
    {
        abort_unless(
            Auth::user()->hasPermissionTo('events.manage-all') || Auth::user()->hasPermissionTo('events.checkin'),
            403
        );

        $reg = $this->registration;

        if (! $reg || $reg->status !== EventRegistration::STATUS_CONFIRMED) {
            $this->message      = 'Anmälan kan inte checkas in.';
            $this->resultStatus = ScanResult::STATUS_INVALID;
            return;
        }

        // Pick the nearest occurrence (upcoming or most recent past)
        $occurrence = $reg->event->occurrences
            ->sortBy(fn ($o) => abs(now()->diffInSeconds($o->starts_at, false)))
            ->first();

        if (! $occurrence) {
            $this->message      = 'Evenemanget har inga tillfällen.';
            $this->resultStatus = ScanResult::STATUS_INVALID;
            return;
        }

        $attendance = EventRegistrationOccurrence::firstOrNew([
            'registration_id' => $reg->id,
            'occurrence_id'   => $occurrence->id,
        ]);
        $attendance->attended_at       = now();
        $attendance->checked_in_by_id  = Auth::id();
        $attendance->save();

        AuditLog::record(
            'event_registration_checked_in',
            'event',
            "Incheckning för {$reg->user?->name} på {$reg->event->title} (tillfälle {$occurrence->starts_at->format('d/m Y H:i')})"
        );

        $this->justActioned = true;
        $this->message      = null;
        unset($this->registration);
    }

    public function resetResult(): void
    {
        $this->resultType   = null;
        $this->resultStatus = null;
        $this->modelId      = null;
        $this->message      = null;
        $this->justActioned = false;
        unset($this->discountCode);
        unset($this->registration);
    }
}; ?>

<x-slot:header>
    <x-page-header permission="payments.view" title="Skanningsstation" subtitle="Skanna eller ange en kod för att lösa in rabatt eller checka in.">
        <a href="{{ route('admin.scanner.kiosk') }}" target="_blank" class="btn">
            <x-bootstrap-icon name="box-arrow-up-right" class="size-4" />
            Kundfönster
        </a>
        <a href="{{ route('admin.discounts.index') }}" class="btn">
            <x-bootstrap-icon name="arrow-left" class="size-4" />
            Tillbaka
        </a>
    </x-page-header>
</x-slot:header>

<div>
    <div class="mx-auto max-w-lg px-4 py-8 sm:px-6"
         x-data
         x-init="$nextTick(() => $refs.scanInput?.focus())">

        {{-- ---------------------------------------------------------------- --}}
        {{-- Scan input                                                         --}}
        {{-- ---------------------------------------------------------------- --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    Skanna kod
                </h2>
            </div>
            <div class="p-5">
                <p class="mb-3 text-xs text-zinc-400 dark:text-zinc-500">
                    Skanna streckkoden eller ange koden manuellt och tryck Enter.
                    Stationen hanterar automatiskt rabattkoder och evenemangsincheckning.
                </p>
                <div class="flex gap-2">
                    <input
                        x-ref="scanInput"
                        wire:model="scanInput"
                        wire:keydown.enter="lookup"
                        type="text"
                        placeholder="Skanna eller skriv kod…"
                        autocomplete="off"
                        autocorrect="off"
                        spellcheck="false"
                        class="inp flex-1 font-mono uppercase text-lg tracking-widest"
                    />
                    <button wire:click="lookup" class="btn btn-primary">
                        <x-bootstrap-icon name="search" class="size-4" />
                        Sök
                    </button>
                </div>
            </div>
        </div>

        {{-- ---------------------------------------------------------------- --}}
        {{-- Error / not found                                                  --}}
        {{-- ---------------------------------------------------------------- --}}
        @if($message && ($resultStatus === 'already_used' || $resultStatus === 'invalid' || $resultType === 'not_found'))
            <div class="mt-4 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-5 py-4 dark:border-red-900/40 dark:bg-red-900/20">
                <x-bootstrap-icon name="x-circle-fill" class="mt-0.5 size-5 shrink-0 text-red-500" />
                <div class="flex-1">
                    <p class="font-medium text-red-700 dark:text-red-400">
                        {{ $resultStatus === 'already_used' ? 'Kod redan använd' : 'Ogiltig kod' }}
                    </p>
                    <p class="mt-0.5 text-sm text-red-600 dark:text-red-300">{{ $message }}</p>
                    @if($this->discountCode)
                        <p class="mt-1 text-xs text-red-400">
                            {{ $this->discountCode->code }} · {{ $this->discountCode->discount->name }}
                        </p>
                    @endif
                </div>
                <button wire:click="resetResult" class="shrink-0 text-xs text-red-400 hover:text-red-600">
                    <x-bootstrap-icon name="x" class="size-4" />
                </button>
            </div>
        @endif

        {{-- ---------------------------------------------------------------- --}}
        {{-- Success: just actioned (redeemed / checked in)                    --}}
        {{-- ---------------------------------------------------------------- --}}
        @if($justActioned)
            <div class="mt-4 flex items-start gap-3 rounded-xl border border-green-200 bg-green-50 px-5 py-4 dark:border-green-900/40 dark:bg-green-900/20"
                 x-init="setTimeout(() => $wire.resetResult(), 5000)">
                <x-bootstrap-icon name="check-circle-fill" class="mt-0.5 size-5 shrink-0 text-green-600" />
                <div class="flex-1">
                    @if($resultType === 'discount_code')
                        @php $c = DiscountCode::with('discount')->find($modelId); @endphp
                        <p class="font-medium text-green-700 dark:text-green-400">Rabattkod inlöst!</p>
                        @if($c)
                            <p class="mt-0.5 text-sm text-green-600 dark:text-green-300">
                                <span class="font-mono font-bold">{{ $c->code }}</span>
                                · {{ $c->discount->name }}
                            </p>
                        @endif
                    @elseif($resultType === 'event_registration')
                        @php $r = EventRegistration::with(['event', 'user'])->find($modelId); @endphp
                        <p class="font-medium text-green-700 dark:text-green-400">Incheckad!</p>
                        @if($r)
                            <p class="mt-0.5 text-sm text-green-600 dark:text-green-300">
                                {{ $r->user?->name ?? $r->guest_name ?? '–' }} · {{ $r->event->title }}
                            </p>
                        @endif
                    @endif
                    <p class="mt-1.5 text-xs text-green-500">Återgår automatiskt om 5 sekunder…</p>
                </div>
                <button wire:click="resetResult" class="shrink-0 text-xs text-green-600 hover:text-green-800">
                    Ny sökning
                </button>
            </div>
        @endif

        {{-- ---------------------------------------------------------------- --}}
        {{-- Result: discount code — available                                  --}}
        {{-- ---------------------------------------------------------------- --}}
        @if($resultType === 'discount_code' && $resultStatus === 'available' && ! $justActioned)
            @php
                $c       = $this->discountCode;
                $scanner = app(ScannerService::class);
                $ctaUrl  = $c ? $scanner->ctaUrl($c->discount, $c->code) : null;
            @endphp
            <div class="mt-4 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <div class="flex items-center gap-2 border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                    <x-bootstrap-icon name="tag-fill" class="size-4 text-brand-orange" />
                    <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Rabattkod</h2>
                </div>
                @if($c)
                    <div class="space-y-3 p-5">
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <dt class="text-zinc-500 dark:text-zinc-400">Kod</dt>
                            <dd class="font-mono font-bold text-zinc-900 dark:text-white">{{ $c->code }}</dd>

                            <dt class="text-zinc-500 dark:text-zinc-400">Rabatt</dt>
                            <dd class="text-zinc-900 dark:text-white">{{ $c->discount->name }}</dd>

                            <dt class="text-zinc-500 dark:text-zinc-400">Gäller för</dt>
                            <dd class="font-medium text-zinc-900 dark:text-white">
                                {{ $scanner->targetLabel($c->discount) }}
                            </dd>

                            <dt class="text-zinc-500 dark:text-zinc-400">Värde</dt>
                            <dd class="text-zinc-900 dark:text-white">
                                @if($c->discount->type === 'percentage')
                                    {{ $c->discount->value }} % rabatt
                                @else
                                    {{ number_format($c->discount->value / 100, 2, ',', ' ') }} kr rabatt
                                @endif
                            </dd>

                            <dt class="text-zinc-500 dark:text-zinc-400">Typ</dt>
                            <dd class="text-zinc-900 dark:text-white">
                                {{ $c->is_single_use ? 'Engångskod' : 'Delad kod' }}
                            </dd>

                            @if($c->batch_label)
                                <dt class="text-zinc-500 dark:text-zinc-400">Batch</dt>
                                <dd class="text-zinc-900 dark:text-white">{{ $c->batch_label }}</dd>
                            @endif

                            @if($c->discount->valid_until)
                                <dt class="text-zinc-500 dark:text-zinc-400">Giltig till</dt>
                                <dd class="text-zinc-900 dark:text-white">{{ $c->discount->valid_until->format('d/m Y') }}</dd>
                            @endif
                        </dl>

                        {{-- CTA: start the purchase / registration --}}
                        @if($ctaUrl)
                            <div class="rounded-lg bg-brand/10 px-4 py-3 dark:bg-brand/10">
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                    Visa den här länken till kunden — koden fylls i automatiskt.
                                </p>
                                <div class="mt-2 flex items-center justify-between gap-3">
                                    <span class="truncate font-mono text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $ctaUrl }}
                                    </span>
                                    <a href="{{ $ctaUrl }}" target="_blank"
                                       class="btn btn-primary shrink-0 text-sm">
                                        {{ $scanner->ctaLabel($c->discount) }}
                                    </a>
                                </div>
                            </div>
                        @endif

                        <div class="flex items-center justify-between border-t border-zinc-100 pt-3 dark:border-zinc-700">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                <x-bootstrap-icon name="circle-fill" class="size-2" />
                                Tillgänglig
                            </span>
                            <div class="flex gap-2">
                                <button wire:click="resetResult" class="btn text-sm">
                                    Avbryt
                                </button>
                                @if($c->is_single_use)
                                    <button wire:click="redeemDiscount" class="btn btn-primary">
                                        <x-bootstrap-icon name="check2" class="size-4" />
                                        Lös in
                                    </button>
                                @else
                                    <button wire:click="redeemDiscount" class="btn btn-primary">
                                        <x-bootstrap-icon name="check2" class="size-4" />
                                        OK – giltig
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        {{-- ---------------------------------------------------------------- --}}
        {{-- Result: event registration — available                             --}}
        {{-- ---------------------------------------------------------------- --}}
        @if($resultType === 'event_registration' && $resultStatus === 'available' && ! $justActioned)
            @php $r = $this->registration; @endphp
            <div class="mt-4 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <div class="flex items-center gap-2 border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                    <x-bootstrap-icon name="ticket-fill" class="size-4 text-green-600 dark:text-green-400" />
                    <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Evenemangsanmälan</h2>
                </div>
                @if($r)
                    <div class="space-y-3 p-5">
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <dt class="text-zinc-500 dark:text-zinc-400">Namn</dt>
                            <dd class="font-semibold text-zinc-900 dark:text-white">
                                {{ $r->user?->name ?? $r->guest_name ?? '–' }}
                            </dd>

                            <dt class="text-zinc-500 dark:text-zinc-400">Evenemang</dt>
                            <dd class="text-zinc-900 dark:text-white">{{ $r->event->title }}</dd>

                            @if($r->priceTier)
                                <dt class="text-zinc-500 dark:text-zinc-400">Biljett</dt>
                                <dd class="text-zinc-900 dark:text-white">{{ $r->priceTier->label }}</dd>
                            @endif

                            @php $firstOcc = $r->event->occurrences->first(); @endphp
                            @if($firstOcc)
                                <dt class="text-zinc-500 dark:text-zinc-400">Datum</dt>
                                <dd class="text-zinc-900 dark:text-white">
                                    {{ $firstOcc->starts_at->isoFormat('D MMM YYYY, HH:mm') }}
                                </dd>
                            @endif
                        </dl>

                        <div class="flex items-center justify-between border-t border-zinc-100 pt-3 dark:border-zinc-700">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                <x-bootstrap-icon name="circle-fill" class="size-2" />
                                Bekräftad
                            </span>
                            <div class="flex gap-2">
                                <button wire:click="resetResult" class="btn text-sm">
                                    Avbryt
                                </button>
                                <button wire:click="checkInRegistration" class="btn btn-primary">
                                    <x-bootstrap-icon name="check2" class="size-4" />
                                    Checka in
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endif

    </div>
</div>
