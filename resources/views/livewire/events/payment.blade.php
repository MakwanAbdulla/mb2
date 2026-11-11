<?php

use App\Models\Events\Event;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\SystemSetting;
use App\Services\CartService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Betala');

new class extends Component {

    public Event  $event;
    public ?Order $order = null;
    public string $method = '';

    public function mount(Event $event): void
    {
        $this->event = $event;

        // Resolve order from session (set by events.show when redirecting here)
        $orderId = session('event_order_id');

        if (! $orderId) {
            $this->redirect(route('events.show', $event->slug), navigate: true);
            return;
        }

        $order = Order::with('lines.discountUse')->find($orderId);

        // Verify the order belongs to this session/user
        if (! $order || $order->status === 'paid') {
            session()->forget('event_order_id');
            $this->redirect(route('events.show', $event->slug), navigate: true);
            return;
        }

        $user = Auth::user();
        if ($user && $order->user_id && $order->user_id !== $user->id) {
            abort(403);
        }

        $this->order = $order;

        // Pre-select payment method
        if ($this->swishNumber()) {
            $this->method = 'swish';
        } elseif ($this->stripeEnabled() && Auth::check()) {
            $this->method = 'stripe';
        } elseif ($this->bankgiro()) {
            $this->method = 'bankgiro';
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

    // Upsell state
    public array $addedProductIds = [];

    #[Computed]
    public function discountUse()
    {
        return $this->order?->lines->first()?->discountUse;
    }

    #[Computed]
    public function displayName(): string
    {
        return Auth::user()?->name ?? $this->order?->guest_name ?? '';
    }

    #[Computed]
    public function displayEmail(): string
    {
        return Auth::user()?->email ?? $this->order?->guest_email ?? '';
    }

    #[Computed]
    public function upsellProducts()
    {
        return Product::with(['variants'])
            ->where('status', Product::STATUS_PUBLISHED)
            ->where('show_in_event_checkout', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->filter(fn ($p) => $p->isAvailable());
    }

    public function addUpsell(int $productId): void
    {
        $product = Product::with('variants')->find($productId);

        if (! $product || $product->status !== Product::STATUS_PUBLISHED) {
            return;
        }

        $variant = $product->defaultVariant();

        if (! $variant || ($product->track_stock && ! $variant->isInStockFor(1))) {
            return;
        }

        app(CartService::class)->addProduct($variant, 1);
        $this->addedProductIds[] = $productId;
    }

    #[Computed]
    public function cartItemCount(): int
    {
        return app(CartService::class)->itemCount();
    }
}; ?>

<x-slot:header>
    <x-page-header title="Betala — {{ $event->title }}" subtitle="" />
</x-slot:header>

<div class="mx-auto max-w-xl px-4 py-12 sm:px-6">

    @if(! $order)
        <p class="text-zinc-500 dark:text-zinc-400">Ingen aktiv betalning hittades.</p>
    @else

        {{-- Summary --}}
        <div class="mb-6 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Att betala</h2>
            </div>
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                <div class="flex items-center justify-between px-5 py-3 text-sm">
                    <span class="text-zinc-500 dark:text-zinc-400">Namn</span>
                    <span class="font-medium text-zinc-900 dark:text-white">{{ $this->displayName }}</span>
                </div>
                <div class="flex items-center justify-between px-5 py-3 text-sm">
                    <span class="text-zinc-500 dark:text-zinc-400">E-post</span>
                    <span class="text-zinc-900 dark:text-white">{{ $this->displayEmail }}</span>
                </div>
                <div class="flex items-center justify-between px-5 py-3 text-sm">
                    <span class="text-zinc-500 dark:text-zinc-400">Evenemang</span>
                    <span class="text-zinc-900 dark:text-white">{{ $event->title }}</span>
                </div>
                @if($order->ocr_number)
                    <div class="flex items-center justify-between px-5 py-3 text-sm">
                        <span class="text-zinc-500 dark:text-zinc-400">Referens / OCR</span>
                        <span class="font-mono font-semibold text-zinc-900 dark:text-white">{{ $order->ocr_number }}</span>
                    </div>
                @endif
                @if($this->discountUse)
                    <div class="flex items-center justify-between px-5 py-3 text-sm">
                        <span class="text-zinc-500 dark:text-zinc-400">Ordinarie pris</span>
                        <span class="text-zinc-400 line-through dark:text-zinc-500">
                            {{ number_format($this->discountUse->original_amount / 100, 2, ',', ' ') }} kr
                        </span>
                    </div>
                    <div class="flex items-center justify-between px-5 py-3 text-sm">
                        <span class="text-green-700 dark:text-green-400">Rabatt</span>
                        <span class="text-green-700 dark:text-green-400">
                            −{{ number_format($this->discountUse->discount_amount / 100, 2, ',', ' ') }} kr
                        </span>
                    </div>
                @endif
                <div class="flex items-center justify-between px-5 py-4">
                    <span class="font-semibold text-zinc-900 dark:text-white">Totalt</span>
                    <span class="text-xl font-bold text-zinc-900 dark:text-white">
                        {{ number_format($order->total_ore / 100, 2, ',', ' ') }} kr
                    </span>
                </div>
            </div>
        </div>

        {{-- Payment method tabs --}}
        <div class="mb-6 flex gap-2">
            @if($this->swishNumber())
                <button wire:click="$set('method', 'swish')"
                        @class(['flex-1 rounded-lg border py-2.5 text-sm font-medium transition-colors',
                            'border-brand bg-brand text-brand-dark'            => $method === 'swish',
                            'border-zinc-200 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800' => $method !== 'swish'])>
                    Swish
                </button>
            @endif
            @if($this->stripeEnabled())
                <button wire:click="$set('method', 'stripe')"
                        @class(['flex-1 rounded-lg border py-2.5 text-sm font-medium transition-colors',
                            'border-brand bg-brand text-brand-dark'            => $method === 'stripe',
                            'border-zinc-200 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800' => $method !== 'stripe'])>
                    Kortbetalning
                </button>
            @endif
            @if($this->bankgiro())
                <button wire:click="$set('method', 'bankgiro')"
                        @class(['flex-1 rounded-lg border py-2.5 text-sm font-medium transition-colors',
                            'border-brand bg-brand text-brand-dark'            => $method === 'bankgiro',
                            'border-zinc-200 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800' => $method !== 'bankgiro'])>
                    Bankgiro
                </button>
            @endif
        </div>

        {{-- Swish --}}
        @if($method === 'swish' && $this->swishNumber())
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="p-5">
                    @if($this->swishType() === 'merchant' && Auth::check())
                        {{-- Swish Handel — initiate from server --}}
                        <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
                            Klicka nedan för att initiera en Swish-betalning till ditt mobilnummer.
                        </p>
                        <form action="{{ route('payment.swish.checkout') }}" method="POST">
                            @csrf
                            <input type="hidden" name="order_id" value="{{ $order->id }}" />
                            <input type="hidden" name="success_url" value="{{ route('events.show', $event->slug) }}" />
                            <button type="submit" class="btn btn-primary w-full">
                                Betala med Swish
                            </button>
                        </form>
                    @else
                        {{-- Swish private — manual --}}
                        <p class="mb-1 text-sm text-zinc-600 dark:text-zinc-400">Swisha till:</p>
                        <p class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $this->swishNumber() }}</p>
                        @if($order->ocr_number)
                            <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-400">
                                Ange referens i meddelandet:
                                <span class="font-mono font-semibold text-zinc-900 dark:text-white">{{ $order->ocr_number }}</span>
                            </p>
                        @endif
                        <p class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">
                            Belopp: {{ number_format($order->total_ore / 100, 2, ',', ' ') }} kr
                        </p>
                        <p class="mt-4 text-xs text-zinc-400 dark:text-zinc-500">
                            Din anmälan bekräftas när betalningen registrerats, normalt inom 1–2 bankdagar.
                            En bekräftelse skickas till {{ $this->displayEmail }}.
                        </p>
                    @endif
                </div>
            </div>
        @endif

        {{-- Stripe --}}
        @if($method === 'stripe' && $this->stripeEnabled())
            @if(Auth::check())
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="p-5">
                        <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
                            Du dirigeras till Stripes säkra betalningssida.
                        </p>
                        <form action="{{ route('payment.stripe.checkout') }}" method="POST">
                            @csrf
                            <input type="hidden" name="order_id" value="{{ $order->id }}" />
                            <input type="hidden" name="success_url" value="{{ route('events.show', $event->slug) }}" />
                            <input type="hidden" name="cancel_url" value="{{ route('events.payment', $event->slug) }}" />
                            <button type="submit" class="btn btn-primary w-full">
                                Betala med kort
                            </button>
                        </form>
                    </div>
                </div>
            @else
                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">
                        Kortbetalning kräver ett inloggat konto.
                        <a href="{{ route('login') }}" class="font-medium underline hover:text-zinc-900 dark:hover:text-white">
                            Logga in
                        </a>
                        eller välj Swish / Bankgiro istället.
                    </p>
                </div>
            @endif
        @endif

        {{-- Bankgiro --}}
        @if($method === 'bankgiro' && $this->bankgiro())
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="p-5 space-y-2">
                    <div class="flex justify-between text-sm">
                        <span class="text-zinc-500 dark:text-zinc-400">Bankgiro</span>
                        <span class="font-semibold text-zinc-900 dark:text-white">{{ $this->bankgiro() }}</span>
                    </div>
                    @if($order->ocr_number)
                        <div class="flex justify-between text-sm">
                            <span class="text-zinc-500 dark:text-zinc-400">OCR-nummer</span>
                            <span class="font-mono font-semibold text-zinc-900 dark:text-white">{{ $order->ocr_number }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between text-sm">
                        <span class="text-zinc-500 dark:text-zinc-400">Belopp</span>
                        <span class="font-semibold text-zinc-900 dark:text-white">
                            {{ number_format($order->total_ore / 100, 2, ',', ' ') }} kr
                        </span>
                    </div>
                    <p class="pt-2 text-xs text-zinc-400 dark:text-zinc-500">
                        Ange OCR-numret som meddelande vid betalning. Din anmälan bekräftas när betalningen registrerats, normalt inom 1–2 bankdagar.
                    </p>
                </div>
            </div>
        @endif

        {{-- Upsell products --}}
        @if ($this->upsellProducts->isNotEmpty())
        <div class="mt-8">
            <div class="mb-3 flex items-center gap-2">
                <x-bootstrap-icon name="bag-heart" class="size-4 text-zinc-400" />
                <p class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Passa på att beställa från butiken</p>
            </div>
            <div class="space-y-3">
                @foreach ($this->upsellProducts as $product)
                @php $alreadyAdded = in_array($product->id, $addedProductIds, true); @endphp
                <div class="flex items-center gap-4 rounded-lg border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900">
                    @if ($product->image_path)
                    <img src="{{ asset('storage/' . $product->image_path) }}"
                         alt="{{ $product->name }}"
                         class="size-12 rounded object-cover" />
                    @else
                    <div class="flex size-12 items-center justify-center rounded bg-zinc-100 dark:bg-zinc-800">
                        <x-bootstrap-icon name="box-seam" class="size-5 text-zinc-300 dark:text-zinc-600" />
                    </div>
                    @endif

                    <div class="flex-1 min-w-0">
                        <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $product->name }}</p>
                        <p class="text-sm text-zinc-500">{{ number_format($product->price_ore / 100, 2, ',', ' ') }} kr</p>
                    </div>

                    @if ($alreadyAdded)
                    <span class="inline-flex items-center gap-1.5 text-sm text-green-600 dark:text-green-400">
                        <x-bootstrap-icon name="check-circle" class="size-4" />
                        Lagd
                    </span>
                    @elseif ($product->hasVariants())
                    <a href="{{ route('shop.show', $product) }}" wire:navigate
                       class="btn btn-secondary btn-sm shrink-0">
                        Välj variant
                    </a>
                    @else
                    <button wire:click="addUpsell({{ $product->id }})"
                            class="btn btn-secondary btn-sm shrink-0">
                        Lägg till
                    </button>
                    @endif
                </div>
                @endforeach
            </div>

            {{-- Cart badge shown after adding --}}
            @if ($this->cartItemCount > 0)
            <div class="mt-4 flex items-center justify-between rounded-lg border border-brand/30 bg-brand/10 px-4 py-3">
                <p class="text-sm font-medium text-brand-dark dark:text-brand">
                    <x-bootstrap-icon name="cart-check" class="mr-1.5 inline size-4" />
                    {{ $this->cartItemCount }} {{ $this->cartItemCount === 1 ? 'vara' : 'varor' }} i kundvagnen
                </p>
                <a href="{{ route('shop.checkout') }}" wire:navigate class="btn btn-primary btn-sm">
                    Till kassan →
                </a>
            </div>
            @endif
        </div>
        @endif

        <div class="mt-4 text-center">
            <a href="{{ route('events.show', $event->slug) }}" wire:navigate
               class="text-sm text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                ← Tillbaka till evenemanget
            </a>
        </div>

    @endif
</div>
