<?php

use App\Models\AuditLog;
use App\Models\Shop\Cart;
use App\Models\Shop\Discount;
use App\Models\Shop\DiscountCode;
use App\Models\Shop\Order;
use App\Models\SystemSetting;
use App\Services\CartService;
use App\Services\ShopCheckoutService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Kassa');

new class extends Component {

    public string $step       = 'cart';   // cart | payment
    public ?Order $order      = null;
    public string $method     = '';

    // Guest info
    public string $guestName  = '';
    public string $guestEmail = '';

    // Stock errors
    public array $stockErrors = [];

    // Discount
    public string        $discountCodeInput  = '';
    public ?Discount     $appliedDiscount     = null;
    public ?DiscountCode $appliedDiscountCode = null;
    public int           $discountAmountOre   = 0;

    public function mount(): void
    {
        // Restore existing pending order from session
        $orderId = session('shop_order_id');
        if ($orderId) {
            $order = Order::find($orderId);
            if ($order && $order->isPending()) {
                $this->order = $order;
                $this->step  = 'payment';
                $this->selectDefaultMethod();
                return;
            }
            session()->forget('shop_order_id');
        }

        // Pre-fill guest info
        if (Auth::check()) {
            $this->guestName  = Auth::user()->name ?? '';
            $this->guestEmail = Auth::user()->email ?? '';
        }
    }

    #[Computed]
    public function cart(): ?Cart
    {
        return app(CartService::class)->get()?->load('items.cartable');
    }

    public function swishNumber(): string  { return (string) SystemSetting::get('payment.swish_number', ''); }
    public function swishType(): string    { return (string) SystemSetting::get('payment.swish_type', 'private'); }
    public function bankgiro(): string     { return (string) SystemSetting::get('payment.bankgiro', ''); }
    public function stripeEnabled(): bool  { return (bool) SystemSetting::get('payment.stripe_enabled', false); }

    public function updateQuantity(int $itemId, int $quantity): void
    {
        app(CartService::class)->updateQuantity($itemId, $quantity);
        unset($this->cart);
    }

    public function removeItem(int $itemId): void
    {
        app(CartService::class)->remove($itemId);
        unset($this->cart);
    }

    public function applyCode(): void
    {
        $this->resetErrorBag('discountCodeInput');
        $code = strtoupper(trim($this->discountCodeInput));

        if ($code === '') {
            $this->addError('discountCodeInput', 'Ange en rabattkod.');
            return;
        }

        $discountCode = DiscountCode::with('discount')
            ->where('code', $code)
            ->first();

        if (! $discountCode || ! $discountCode->isAvailable()) {
            $this->addError('discountCodeInput', 'Ogiltig eller förbrukad rabattkod.');
            return;
        }

        $discount = $discountCode->discount;

        // Check applies_to = shop or all
        if (! in_array($discount->applies_to, [Discount::APPLIES_SHOP, Discount::APPLIES_ALL])) {
            $this->addError('discountCodeInput', 'Den här koden gäller inte i butiken.');
            return;
        }

        // Check membership requirement
        $member = Auth::user()?->member;
        if (! $discount->eligibleFor($member)) {
            $this->addError('discountCodeInput', 'Du uppfyller inte kraven för den här rabatten.');
            return;
        }

        $subtotal = $this->cart?->totalOre() ?? 0;
        $this->discountAmountOre   = $discount->calculate($subtotal);
        $this->appliedDiscount     = $discount;
        $this->appliedDiscountCode = $discountCode;
        $this->discountCodeInput   = '';
    }

    public function removeDiscount(): void
    {
        $this->appliedDiscount     = null;
        $this->appliedDiscountCode = null;
        $this->discountAmountOre   = 0;
        $this->discountCodeInput   = '';
    }

    public function proceedToPayment(): void
    {
        $cart = $this->cart;

        if (! $cart || $cart->isEmpty()) {
            return;
        }

        // Validate stock
        $this->stockErrors = app(CartService::class)->validateStock();
        if (! empty($this->stockErrors)) {
            return;
        }

        // Validate guest info if not logged in
        if (! Auth::check()) {
            $this->validate([
                'guestName'  => 'required|string|max:255',
                'guestEmail' => 'required|email|max:255',
            ], [
                'guestName.required'  => 'Namn krävs.',
                'guestEmail.required' => 'E-postadress krävs.',
                'guestEmail.email'    => 'Ange en giltig e-postadress.',
            ]);
        }

        // Re-calculate discount in case cart changed since code was applied
        if ($this->appliedDiscount) {
            $this->discountAmountOre = $this->appliedDiscount->calculate($cart->totalOre());
        }

        try {
            $order = DB::transaction(function () use ($cart) {
                return $this->createOrderFromCart($cart);
            });

            AuditLog::record('shop_order_placed', 'shop', '#' . $order->id . ' — ' . number_format($order->total_ore / 100, 2, ',', ' ') . ' kr', $order);
            app(CartService::class)->destroy();

            session(['shop_order_id' => $order->id]);
            $this->order = $order;
            $this->step  = 'payment';
            $this->selectDefaultMethod();

        } catch (\Throwable $e) {
            Log::error('Checkout failed', ['error' => $e->getMessage()]);
            $this->addError('checkout', 'Något gick fel. Försök igen.');
        }
    }

    private function createOrderFromCart(Cart $cart): Order
    {
        $user = Auth::user();

        return app(ShopCheckoutService::class)->createOrderFromCart(
            cart:         $cart,
            userId:       $user?->id,
            guestName:    $user ? null : $this->guestName,
            guestEmail:   $user ? null : $this->guestEmail,
            discountCode: $this->appliedDiscountCode,
            member:       $user?->member,
        );
    }

    private function selectDefaultMethod(): void
    {
        if ($this->swishNumber()) {
            $this->method = 'swish';
        } elseif ($this->stripeEnabled() && Auth::check()) {
            $this->method = 'stripe';
        } elseif ($this->bankgiro()) {
            $this->method = 'bankgiro';
        }
    }

}; ?>

<x-slot:header>
    <x-page-header title="Kassa" />
</x-slot:header>

<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6">

    @if ($step === 'cart')

        {{-- Empty cart --}}
        @if (! $this->cart || $this->cart->isEmpty())
        <div class="py-16 text-center">
            <x-bootstrap-icon name="cart" class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
            <p class="mt-4 text-zinc-500">Din kundvagn är tom.</p>
            <a href="{{ route('shop.index') }}" wire:navigate class="mt-4 inline-block btn btn-primary">Till butiken</a>
        </div>

        @else

        {{-- Cart items --}}
        <div class="mb-6 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Din varukorg</h2>
            </div>
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($this->cart->items as $item)
                <div class="flex items-center gap-4 px-5 py-4">
                    <div class="flex-1">
                        <p class="font-medium text-zinc-900 dark:text-white">{{ $item->displayLabel() }}</p>
                        <p class="text-sm text-zinc-500">{{ number_format($item->unit_price_ore / 100, 2, ',', ' ') }} kr/st</p>
                        @if (isset($stockErrors[$item->id]))
                        <p class="mt-1 text-sm text-red-600">{{ $stockErrors[$item->id] }}</p>
                        @endif
                    </div>

                    @if ($item->isProduct())
                    <div class="flex items-center gap-2">
                        <button wire:click="updateQuantity({{ $item->id }}, {{ max(1, $item->quantity - 1) }})"
                                class="flex size-7 items-center justify-center rounded border border-zinc-300 text-zinc-500 hover:bg-zinc-50 dark:border-zinc-600">
                            <x-bootstrap-icon name="dash" class="size-3.5" />
                        </button>
                        <span class="w-6 text-center text-sm font-medium text-zinc-900 dark:text-white">{{ $item->quantity }}</span>
                        <button wire:click="updateQuantity({{ $item->id }}, {{ $item->quantity + 1 }})"
                                class="flex size-7 items-center justify-center rounded border border-zinc-300 text-zinc-500 hover:bg-zinc-50 dark:border-zinc-600">
                            <x-bootstrap-icon name="plus" class="size-3.5" />
                        </button>
                    </div>
                    @endif

                    <p class="w-24 text-right font-medium text-zinc-900 dark:text-white">
                        {{ number_format($item->lineTotalOre() / 100, 2, ',', ' ') }} kr
                    </p>
                    <button wire:click="removeItem({{ $item->id }})" class="text-zinc-300 hover:text-red-500 dark:text-zinc-600">
                        <x-bootstrap-icon name="x-lg" class="size-4" />
                    </button>
                </div>
                @endforeach

                @if ($appliedDiscount)
                <div class="flex items-center justify-between px-5 py-3 text-sm text-zinc-600 dark:text-zinc-400">
                    <span>Delsumma</span>
                    <span>{{ number_format($this->cart->totalOre() / 100, 2, ',', ' ') }} kr</span>
                </div>
                <div class="flex items-center justify-between px-5 py-3 text-sm text-green-700 dark:text-green-400">
                    <span>Rabatt ({{ $appliedDiscount->name }})</span>
                    <span>−{{ number_format($discountAmountOre / 100, 2, ',', ' ') }} kr</span>
                </div>
                @endif

                <div class="flex items-center justify-between px-5 py-4">
                    <span class="font-semibold text-zinc-900 dark:text-white">Totalt</span>
                    <span class="text-xl font-bold text-zinc-900 dark:text-white">
                        {{ number_format(max(0, $this->cart->totalOre() - $discountAmountOre) / 100, 2, ',', ' ') }} kr
                    </span>
                </div>
            </div>
        </div>

        {{-- Discount code --}}
        <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="mb-3 text-sm font-semibold text-zinc-700 dark:text-zinc-200">Rabattkod</h2>

            @if ($appliedDiscount)
            <div class="flex items-center justify-between rounded-lg bg-green-50 px-4 py-3 dark:bg-green-900/20">
                <div class="flex items-center gap-2 text-sm font-medium text-green-700 dark:text-green-400">
                    <x-bootstrap-icon name="check-circle" class="size-4" />
                    {{ $appliedDiscount->name }} — −{{ number_format($discountAmountOre / 100, 2, ',', ' ') }} kr
                </div>
                <button wire:click="removeDiscount" class="text-xs text-zinc-400 hover:text-red-500">Ta bort</button>
            </div>
            @else
            <div class="flex gap-2">
                <input wire:model="discountCodeInput"
                       wire:keydown.enter="applyCode"
                       type="text"
                       class="inp flex-1 uppercase"
                       placeholder="Ange kod"
                       autocomplete="off" />
                <button wire:click="applyCode" class="btn btn-secondary shrink-0">Lägg till</button>
            </div>
            @error('discountCodeInput')
            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
            @endif
        </div>

        @error('checkout')
        <div class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-400">
            {{ $message }}
        </div>
        @enderror

        {{-- Guest info --}}
        @guest
        <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="mb-4 font-semibold text-zinc-900 dark:text-white">Dina uppgifter</h2>
            <p class="mb-4 text-sm text-zinc-500">
                <a href="{{ route('login') }}" wire:navigate class="font-medium underline hover:text-zinc-800">Logga in</a>
                för ett smidigare köp, eller fortsätt som gäst.
            </p>
            <div class="space-y-3">
                <div>
                    <label class="lbl">Namn</label>
                    <input wire:model="guestName" type="text" class="inp w-full" placeholder="Förnamn Efternamn" />
                    @error('guestName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lbl">E-postadress</label>
                    <input wire:model="guestEmail" type="email" class="inp w-full" placeholder="din@epost.se" />
                    @error('guestEmail') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>
        @endguest

        @php
            $hasPickupOnly = $this->cart?->items->contains(function ($item) {
                return $item->isProduct()
                    && $item->cartable instanceof \App\Models\Shop\ProductVariant
                    && $item->cartable->product?->pickup_only;
            });
        @endphp
        @if ($hasPickupOnly)
            <div class="flex items-start gap-3 rounded-xl border border-violet-200 bg-violet-50 px-4 py-3 dark:border-violet-900/40 dark:bg-violet-900/10">
                <x-bootstrap-icon name="bag-check" class="mt-0.5 size-4 shrink-0 text-violet-600 dark:text-violet-400" />
                <p class="text-sm text-violet-800 dark:text-violet-300">
                    Din varukorg innehåller varor som <strong>endast kan hämtas på plats</strong>. Du meddelas när de är redo.
                </p>
            </div>
        @endif

        <button wire:click="proceedToPayment" class="btn btn-primary w-full text-base">
            Gå vidare till betalning →
        </button>

        @endif

    @elseif ($step === 'payment' && $order)

        {{-- Order summary --}}
        <div class="mb-6 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Att betala</h2>
            </div>
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($order->lines as $line)
                <div class="flex items-center justify-between px-5 py-3 text-sm">
                    <span class="text-zinc-700 dark:text-zinc-300">
                        {{ $line->quantity > 1 ? $line->quantity . ' × ' : '' }}{{ $line->description }}
                    </span>
                    <span class="font-medium text-zinc-900 dark:text-white">
                        {{ number_format($line->line_total_ore / 100, 2, ',', ' ') }} kr
                    </span>
                </div>
                @endforeach
                @if ($order->ocr_number)
                <div class="flex items-center justify-between px-5 py-3 text-sm">
                    <span class="text-zinc-500">Referens / OCR</span>
                    <span class="font-mono font-semibold text-zinc-900 dark:text-white">{{ $order->ocr_number }}</span>
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
            @if ($this->swishNumber())
            <button wire:click="$set('method', 'swish')"
                    @class(['flex-1 rounded-lg border py-2.5 text-sm font-medium transition-colors',
                        'border-brand bg-brand text-brand-dark' => $method === 'swish',
                        'border-zinc-200 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800' => $method !== 'swish'])>
                Swish
            </button>
            @endif
            @if ($this->stripeEnabled())
            <button wire:click="$set('method', 'stripe')"
                    @class(['flex-1 rounded-lg border py-2.5 text-sm font-medium transition-colors',
                        'border-brand bg-brand text-brand-dark' => $method === 'stripe',
                        'border-zinc-200 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800' => $method !== 'stripe'])>
                Kortbetalning
            </button>
            @endif
            @if ($this->bankgiro())
            <button wire:click="$set('method', 'bankgiro')"
                    @class(['flex-1 rounded-lg border py-2.5 text-sm font-medium transition-colors',
                        'border-brand bg-brand text-brand-dark' => $method === 'bankgiro',
                        'border-zinc-200 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800' => $method !== 'bankgiro'])>
                Bankgiro
            </button>
            @endif
        </div>

        {{-- Swish --}}
        @if ($method === 'swish' && $this->swishNumber())
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="p-5">
                @if ($this->swishType() === 'merchant' && Auth::check())
                <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">Klicka nedan för att initiera en Swish-betalning.</p>
                <form action="{{ route('payment.swish.checkout') }}" method="POST">
                    @csrf
                    <input type="hidden" name="order_id" value="{{ $order->id }}" />
                    <input type="hidden" name="success_url" value="{{ route('shop.confirmation', $order) }}" />
                    <button type="submit" class="btn btn-primary w-full">Betala med Swish</button>
                </form>
                @else
                <p class="mb-1 text-sm text-zinc-600 dark:text-zinc-400">Swisha till:</p>
                <p class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $this->swishNumber() }}</p>
                @if ($order->ocr_number)
                <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-400">
                    Ange referens: <span class="font-mono font-semibold text-zinc-900 dark:text-white">{{ $order->ocr_number }}</span>
                </p>
                @endif
                <p class="mt-1 font-semibold text-zinc-900 dark:text-white">{{ number_format($order->total_ore / 100, 2, ',', ' ') }} kr</p>
                <p class="mt-4 text-xs text-zinc-400">Din beställning bekräftas när betalningen registrerats.</p>
                @endif
            </div>
        </div>
        @endif

        {{-- Stripe --}}
        @if ($method === 'stripe' && $this->stripeEnabled())
        @auth
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="p-5">
                <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">Du dirigeras till Stripes säkra betalningssida.</p>
                <form action="{{ route('payment.stripe.checkout') }}" method="POST">
                    @csrf
                    <input type="hidden" name="order_id" value="{{ $order->id }}" />
                    <input type="hidden" name="success_url" value="{{ route('shop.confirmation', $order) }}" />
                    <input type="hidden" name="cancel_url" value="{{ route('shop.checkout') }}" />
                    <button type="submit" class="btn btn-primary w-full">Betala med kort</button>
                </form>
            </div>
        </div>
        @else
        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Kortbetalning kräver ett inloggat konto.
                <a href="{{ route('login') }}" class="font-medium underline">Logga in</a>
                eller välj Swish / Bankgiro.
            </p>
        </div>
        @endauth
        @endif

        {{-- Bankgiro --}}
        @if ($method === 'bankgiro' && $this->bankgiro())
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="space-y-2 p-5">
                <div class="flex justify-between text-sm">
                    <span class="text-zinc-500">Bankgiro</span>
                    <span class="font-semibold text-zinc-900 dark:text-white">{{ $this->bankgiro() }}</span>
                </div>
                @if ($order->ocr_number)
                <div class="flex justify-between text-sm">
                    <span class="text-zinc-500">OCR-nummer</span>
                    <span class="font-mono font-semibold text-zinc-900 dark:text-white">{{ $order->ocr_number }}</span>
                </div>
                @endif
                <div class="flex justify-between text-sm">
                    <span class="text-zinc-500">Belopp</span>
                    <span class="font-semibold text-zinc-900 dark:text-white">{{ number_format($order->total_ore / 100, 2, ',', ' ') }} kr</span>
                </div>
                <p class="pt-2 text-xs text-zinc-400">Ange OCR-numret som meddelande. Din beställning bekräftas när betalningen registrerats.</p>
            </div>
        </div>
        @endif

    @endif
</div>
