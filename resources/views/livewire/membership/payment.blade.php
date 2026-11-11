<?php

use App\Models\Shop\DiscountUse;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipFee;
use App\Models\Membership\MembershipPeriod;
use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use App\Models\SystemSetting;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.onboarding');
title('Betala medlemsavgift');

new class extends Component {

    public ?Member $member           = null;
    public ?MembershipPeriod $period = null;
    public ?MembershipFee $fee       = null;
    public ?Order $order             = null;
    public ?DiscountUse $discountUse = null;
    public string $method            = '';

    public function mount(): void
    {
        $this->member = Member::where('user_id', auth()->id())->first();

        if (! $this->member) {
            $this->redirect(route('membership.apply'), navigate: true);
            return;
        }

        // Already active — nothing to pay
        if ($this->member->status === Member::STATUS_ACTIVE) {
            $this->redirect(route('membership.show'), navigate: true);
            return;
        }

        $this->period = MembershipPeriod::where('member_id', $this->member->id)
            ->where('status', MembershipPeriod::STATUS_PENDING_PAYMENT)
            ->orderByDesc('year')
            ->first();

        if (! $this->period) {
            $this->redirect(route('membership.show'), navigate: true);
            return;
        }

        $this->fee = MembershipFee::where('year', $this->period->year)
            ->where('membership_type', $this->period->membership_type)
            ->first();

        $this->order = $this->period->order?->load('lines.discountUse');

        $this->discountUse = $this->order?->lines->first()?->discountUse;

        // Pre-select first available method
        if ($this->swishNumber()) {
            $this->method = 'swish';
        } elseif ($this->bankgiro()) {
            $this->method = 'bankgiro';
        } elseif ($this->stripeEnabled()) {
            $this->method = 'stripe';
        }
    }

    public function swishNumber(): string
    {
        return (string) SystemSetting::get('payment.swish_number', '');
    }

    public function swishType(): string
    {
        return (string) SystemSetting::get('payment.swish_type', 'private');
    }

    public function bankgiro(): string
    {
        return (string) SystemSetting::get('payment.bankgiro', '');
    }

    public function stripeEnabled(): bool
    {
        return (bool) SystemSetting::get('payment.stripe_enabled', false);
    }

    public function reference(): string
    {
        return $this->order?->payment_reference ?? '';
    }

    public function ocr(): string
    {
        return $this->order?->ocr_number ?? '';
    }

    public function skip(): void
    {
        $this->redirect(route('dashboard'), navigate: true);
    }
}; ?>

<div class="container mx-auto max-w-xl px-4 py-12 sm:px-6">

    <div class="mb-8">
        <h1 class="text-3xl font-bold text-zinc-900 dark:text-white">Betala medlemsavgift</h1>
        <p class="mt-2 text-zinc-500 dark:text-zinc-400">
            Välj betalningsmetod för att slutföra din medlemsanmälan.
        </p>
    </div>

    @if($member && $period && $fee)

        {{-- Summary --}}
        <div class="mb-6 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Att betala</h2>
            </div>
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                <div class="flex items-center justify-between px-5 py-3">
                    <span class="text-sm text-zinc-600 dark:text-zinc-400">Namn</span>
                    <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $member->full_name }}</span>
                </div>
                <div class="flex items-center justify-between px-5 py-3">
                    <span class="text-sm text-zinc-600 dark:text-zinc-400">Typ</span>
                    <span class="text-sm text-zinc-900 dark:text-white">
                        @switch($period->membership_type)
                            @case('single') Vuxen @break
                            @case('family') Familj @break
                            @case('youth')  Ungdom @break
                            @case('child')  Barn @break
                            @case('student') Student @break
                            @case('senior') Senior @break
                            @default {{ $period->membership_type }}
                        @endswitch
                        {{ $period->year }}
                    </span>
                </div>
                @if($this->ocr())
                <div class="flex items-center justify-between px-5 py-3">
                    <span class="text-sm text-zinc-600 dark:text-zinc-400">Referens / OCR</span>
                    <span class="font-mono text-sm font-semibold text-zinc-900 dark:text-white">{{ $this->ocr() }}</span>
                </div>
                @endif
                @if($discountUse)
                    <div class="flex items-center justify-between px-5 py-3">
                        <span class="text-sm text-zinc-600 dark:text-zinc-400">Ordinarie pris</span>
                        <span class="text-sm text-zinc-400 line-through dark:text-zinc-500">
                            {{ number_format($discountUse->original_amount / 100, 2, ',', ' ') }} kr
                        </span>
                    </div>
                    <div class="flex items-center justify-between px-5 py-3">
                        <span class="text-sm text-green-700 dark:text-green-400">
                            Rabatt ({{ $discountUse->discount->name }})
                        </span>
                        <span class="text-sm font-medium text-green-700 dark:text-green-400">
                            −{{ number_format($discountUse->discount_amount / 100, 2, ',', ' ') }} kr
                        </span>
                    </div>
                @endif
                <div class="flex items-center justify-between bg-brand/5 px-5 py-3.5">
                    <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Summa</span>
                    @php $summaryPayAmount = $order ? $order->total_ore : ($discountUse
                        ? $discountUse->original_amount - $discountUse->discount_amount
                        : $fee->amount); @endphp
                    <span class="text-lg font-bold text-zinc-900 dark:text-white">
                        {{ number_format($summaryPayAmount / 100, 2, ',', ' ') }} kr
                    </span>
                </div>
            </div>
        </div>

        {{-- Method selection --}}
        @php
            $swish      = $this->swishNumber();
            $swishType  = $this->swishType();
            $bg         = $this->bankgiro();
            $bgRefType  = (string) SystemSetting::get('payment.bankgiro_reference_type', 'message');
            $stripe     = $this->stripeEnabled();
            $hasMethods = $swish || $bg || $stripe;
            $payAmount  = $order ? $order->total_ore : ($discountUse
                ? $discountUse->original_amount - $discountUse->discount_amount
                : $fee->amount);
        @endphp

        @if($hasMethods)
            <div x-data="{ method: @entangle('method') }" class="space-y-3">

                {{-- Swish --}}
                @if($swish)
                    <label class="block cursor-pointer">
                        <div :class="method === 'swish' ? 'border-brand ring-1 ring-brand' : 'border-zinc-200 dark:border-zinc-700'"
                             class="overflow-hidden rounded-xl border transition-all">
                            <div class="flex items-center gap-3 px-5 py-4">
                                <input type="radio" value="swish" x-model="method" class="size-4 accent-brand-dark" />
                                <div class="flex-1">
                                    <span class="font-medium text-zinc-900 dark:text-white">Swish</span>
                                    <span class="ml-2 text-xs text-zinc-400">Direktbetalning via Swish-appen</span>
                                </div>
                                <span class="text-xs font-mono font-semibold text-zinc-600 dark:text-zinc-300">{{ $swish }}</span>
                            </div>
                            <div x-show="method === 'swish'" x-transition
                                 class="border-t border-zinc-100 bg-zinc-50 px-5 py-4 dark:border-zinc-800 dark:bg-zinc-800/50">
                                @if($swishType === 'merchant')
                                    {{-- Swish Handel: API flow --}}
                                    <p class="mb-3 text-sm text-zinc-600 dark:text-zinc-400">
                                        Du skickas till Swish-appen för att godkänna betalningen. Aktivering sker omedelbart.
                                    </p>
                                    <form method="POST" action="{{ route('payment.swish.checkout') }}">
                                        @csrf
                                        <input type="hidden" name="order_id" value="{{ $order->id }}" />
                                        <input type="hidden" name="success_url" value="{{ route('membership.show') }}" />
                                        <button type="submit"
                                                class="inline-flex items-center gap-2 rounded-lg bg-[#4db848] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#3da038]">
                                            <x-bootstrap-icon name="phone" class="size-4" />
                                            Betala {{ number_format($payAmount / 100, 2, ',', ' ') }} kr med Swish
                                        </button>
                                    </form>
                                @else
                                    {{-- Swish Privat / Företag: pre-filled QR --}}
                                    @php
                                        $deepLink = 'swish://payment?data=' . rawurlencode(json_encode([
                                            'version' => 1,
                                            'payee'   => ['value' => preg_replace('/\D/', '', $swish)],
                                            'amount'  => ['value' => $payAmount / 100],
                                            'message' => ['value' => $this->ocr()],
                                        ]));
                                    @endphp
                                    <div x-data="{
                                        showQr: false,
                                        qrFailed: false,
                                        isMobile: navigator.maxTouchPoints > 0 || /Android|iPhone|iPad|iPod/i.test(navigator.userAgent)
                                    }">

                                        {{-- Fallback (QR kunde inte laddas) --}}
                                        <template x-if="qrFailed">
                                            <dl class="space-y-1.5 text-sm">
                                                <div class="flex justify-between gap-4">
                                                    <dt class="text-zinc-500 dark:text-zinc-400">Swish-nummer</dt>
                                                    <dd class="font-mono font-semibold text-zinc-900 dark:text-white">{{ $swish }}</dd>
                                                </div>
                                                <div class="flex justify-between gap-4">
                                                    <dt class="text-zinc-500 dark:text-zinc-400">Belopp</dt>
                                                    <dd class="font-semibold text-zinc-900 dark:text-white">{{ number_format($payAmount / 100, 2, ',', ' ') }} kr</dd>
                                                </div>
                                                <div class="flex justify-between gap-4">
                                                    <dt class="text-zinc-500 dark:text-zinc-400">Referens / OCR</dt>
                                                    <dd class="font-mono font-semibold text-zinc-900 dark:text-white">{{ $this->ocr() }}</dd>
                                                </div>
                                                <a href="{{ $deepLink }}"
                                                   class="mt-3 inline-flex items-center gap-2 rounded-lg bg-[#4db848] px-4 py-2 text-sm font-semibold text-white hover:bg-[#3da038]">
                                                    <x-bootstrap-icon name="phone" class="size-4" />
                                                    Öppna Swish-appen
                                                </a>
                                            </dl>
                                        </template>

                                        {{-- Desktop: QR primärt --}}
                                        <div x-show="!isMobile && !qrFailed">
                                            <p class="mb-3 text-sm text-zinc-600 dark:text-zinc-400">
                                                Scanna QR-koden med Swish-appen för att betala.
                                            </p>
                                            <div class="flex justify-center">
                                                <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
                                                    <img src="{{ route('payment.swish.qr') }}" alt="Swish QR-kod"
                                                         class="size-[200px]"
                                                         @@error="qrFailed = true"
                                                         @load="qrFailed = false" />
                                                </div>
                                            </div>
                                            <p class="mt-3 text-center text-xs text-zinc-400 dark:text-zinc-500">
                                                {{ $swish }} &middot; {{ number_format($payAmount / 100, 2, ',', ' ') }} kr &middot;
                                                <span class="font-mono">{{ $this->ocr() }}</span>
                                            </p>
                                        </div>

                                        {{-- Mobil: deep link primärt --}}
                                        <div x-show="isMobile && !qrFailed" class="space-y-3">
                                            <a href="{{ $deepLink }}"
                                               class="inline-flex items-center gap-2 rounded-lg bg-[#4db848] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#3da038]">
                                                <x-bootstrap-icon name="phone" class="size-4" />
                                                Öppna Swish-appen
                                            </a>
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                                Swish-nummer <span class="font-mono font-semibold">{{ $swish }}</span>,
                                                belopp <strong>{{ number_format($payAmount / 100, 2, ',', ' ') }} kr</strong>,
                                                referens <span class="font-mono font-semibold">{{ $this->ocr() }}</span>
                                            </p>
                                            <div>
                                                <button type="button" @click="showQr = !showQr"
                                                        class="text-sm text-zinc-400 underline hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300">
                                                    <span x-show="!showQr">Betala från en annan enhet? Visa QR-kod</span>
                                                    <span x-show="showQr" x-cloak>Dölj QR-kod</span>
                                                </button>
                                                <div x-show="showQr" x-transition class="mt-3 flex justify-center">
                                                    <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
                                                        <img src="{{ route('payment.swish.qr') }}" alt="Swish QR-kod"
                                                             class="size-[200px]"
                                                             @@error="qrFailed = true" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                @endif
                            </div>
                        </div>
                    </label>
                @endif

                {{-- Bankgiro --}}
                @if($bg)
                    <label class="block cursor-pointer">
                        <div :class="method === 'bankgiro' ? 'border-brand ring-1 ring-brand' : 'border-zinc-200 dark:border-zinc-700'"
                             class="overflow-hidden rounded-xl border transition-all">
                            <div class="flex items-center gap-3 px-5 py-4">
                                <input type="radio" value="bankgiro" x-model="method" class="size-4 accent-brand-dark" />
                                <div class="flex-1">
                                    <span class="font-medium text-zinc-900 dark:text-white">Bankgiro</span>
                                    <span class="ml-2 text-xs text-zinc-400">Betalning via din bank</span>
                                </div>
                                <span class="text-xs font-mono font-semibold text-zinc-600 dark:text-zinc-300">BG {{ $bg }}</span>
                            </div>
                            <div x-show="method === 'bankgiro'" x-transition
                                 class="border-t border-zinc-100 bg-zinc-50 px-5 py-4 dark:border-zinc-800 dark:bg-zinc-800/50">
                                <dl class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <dt class="text-zinc-500 dark:text-zinc-400">Mottagare</dt>
                                        <dd class="font-medium text-zinc-900 dark:text-white">Armagedon</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-zinc-500 dark:text-zinc-400">Bankgiro</dt>
                                        <dd class="font-mono font-semibold text-zinc-900 dark:text-white">{{ $bg }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-zinc-500 dark:text-zinc-400">Belopp</dt>
                                        <dd class="font-semibold text-zinc-900 dark:text-white">{{ number_format($payAmount / 100, 2, ',', ' ') }} kr</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-zinc-500 dark:text-zinc-400">
                                            {{ $bgRefType === 'ocr' ? 'OCR-nummer' : 'Meddelande / OCR' }}
                                        </dt>
                                        <dd class="font-mono text-lg font-bold tracking-widest text-zinc-900 dark:text-white">{{ $this->ocr() }}</dd>
                                    </div>
                                </dl>
                                <p class="mt-3 text-xs text-zinc-400 dark:text-zinc-500">
                                    Ange referensnumret exakt i betalningen. Aktivering sker normalt inom 1–3 bankdagar.
                                </p>
                            </div>
                        </div>
                    </label>
                @endif

                {{-- Stripe --}}
                @if($stripe)
                    <label class="block cursor-pointer">
                        <div :class="method === 'stripe' ? 'border-brand ring-1 ring-brand' : 'border-zinc-200 dark:border-zinc-700'"
                             class="overflow-hidden rounded-xl border transition-all">
                            <div class="flex items-center gap-3 px-5 py-4">
                                <input type="radio" value="stripe" x-model="method" class="size-4 accent-brand-dark" />
                                <div class="flex-1">
                                    <span class="font-medium text-zinc-900 dark:text-white">Kortbetalning</span>
                                    <span class="ml-2 text-xs text-zinc-400">Visa, Mastercard via Stripe</span>
                                </div>
                                <x-bootstrap-icon name="credit-card" class="size-5 text-zinc-400" />
                            </div>
                            <div x-show="method === 'stripe'" x-transition
                                 class="border-t border-zinc-100 bg-zinc-50 px-5 py-4 dark:border-zinc-800 dark:bg-zinc-800/50">
                                <p class="mb-3 text-sm text-zinc-600 dark:text-zinc-400">
                                    Du skickas till Stripes säkra betalningssida. Aktivering sker omedelbart.
                                </p>
                                <form method="POST" action="{{ route('payment.stripe.checkout') }}">
                                    @csrf
                                    <input type="hidden" name="order_id" value="{{ $order->id }}" />
                                    <input type="hidden" name="success_url" value="{{ route('payment.stripe.success') }}" />
                                    <input type="hidden" name="cancel_url" value="{{ route('membership.payment') }}" />
                                    <button type="submit"
                                            class="inline-flex items-center gap-2 rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                                        <x-bootstrap-icon name="credit-card" class="size-4" />
                                        Betala {{ number_format($payAmount / 100, 2, ',', ' ') }} kr med kort
                                    </button>
                                </form>
                            </div>
                        </div>
                    </label>
                @endif

            </div>

            <div class="mt-6 flex items-center justify-between">
                <button wire:click="skip"
                        class="text-sm text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300">
                    Betala senare
                </button>
                <p class="text-xs text-zinc-400 dark:text-zinc-500">
                    Din anmälan är sparad. Kontot aktiveras när betalning mottagits.
                </p>
            </div>

        @else
            {{-- No payment methods configured --}}
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-6 dark:border-amber-800/40 dark:bg-amber-900/20">
                <div class="flex gap-3">
                    <x-bootstrap-icon name="info-circle" class="size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                    <div>
                        <p class="text-sm font-medium text-amber-800 dark:text-amber-300">Betalning konfigureras av administratören</p>
                        <p class="mt-1 text-sm text-amber-700 dark:text-amber-400">
                            Du kommer att få instruktioner om betalning via e-post.
                        </p>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex justify-between">
                <button wire:click="skip"
                        class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                    Till min sida
                </button>
            </div>
        @endif

    @endif
</div>
