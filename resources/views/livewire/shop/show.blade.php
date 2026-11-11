<?php

use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Services\CartService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title(fn () => $this->product->name);

new class extends Component {

    public Product $product;

    public ?int $selectedVariantId = null;
    public int  $quantity          = 1;
    public bool $added             = false;
    public bool $isMember          = false;

    public function mount(): void
    {
        abort_if($this->product->status !== Product::STATUS_PUBLISHED, 404);

        $user = Auth::user();

        // If requires_membership, check
        if ($this->product->requires_membership) {
            abort_unless($user && $user->member?->isActive(), 403);
        }

        $this->isMember = (bool) ($user && $user->member?->isActive());

        // Pre-select default/only variant
        if (! $this->product->hasVariants()) {
            $this->selectedVariantId = $this->product->defaultVariant()?->id;
        } elseif ($this->product->variants->count() === 1) {
            $this->selectedVariantId = $this->product->variants->first()->id;
        }
    }

    #[Computed]
    public function selectedVariant(): ?ProductVariant
    {
        return $this->selectedVariantId
            ? $this->product->variants->find($this->selectedVariantId)
            : null;
    }

    public function addToCart(): void
    {
        if (! $this->selectedVariantId) {
            $this->addError('variant', 'Välj ett alternativ.');
            return;
        }

        $variant = ProductVariant::findOrFail($this->selectedVariantId);

        if (! $variant->isInStockFor($this->quantity)) {
            $this->addError('variant', 'Tyvärr, det valda antalet finns inte i lager.');
            return;
        }

        app(CartService::class)->addProduct($variant, $this->quantity);

        $this->added = true;
    }

}; ?>

<x-slot:header>
    <x-page-header :title="$product->name" />
</x-slot:header>

<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6">

    <div class="mb-4">
        <a href="{{ route('shop.index') }}" wire:navigate class="text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
            ← Tillbaka till butiken
        </a>
    </div>

    <div class="grid gap-8 lg:grid-cols-2">
        {{-- Image --}}
        <div class="aspect-square overflow-hidden rounded-xl bg-zinc-100 dark:bg-zinc-800">
            @if ($product->image_path)
            <img src="{{ asset('storage/' . $product->image_path) }}"
                 alt="{{ $product->name }}"
                 class="h-full w-full object-cover" />
            @else
            <div class="flex h-full items-center justify-center">
                <x-bootstrap-icon name="box-seam" class="size-20 text-zinc-300 dark:text-zinc-600" />
            </div>
            @endif
        </div>

        {{-- Info + form --}}
        <div class="flex flex-col">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $product->name }}</h1>

            <div class="mt-2">
                @if ($isMember && $product->hasMemberPrice())
                    <p class="text-2xl font-semibold text-zinc-900 dark:text-white">
                        {{ number_format($product->priceFor(true) / 100, 2, ',', ' ') }} kr
                        <span class="ml-2 text-base font-normal text-green-600 dark:text-green-400">Medlemspris</span>
                    </p>
                    <p class="text-sm text-zinc-400 line-through">
                        {{ number_format($product->price_ore / 100, 2, ',', ' ') }} kr
                    </p>
                @else
                    <p class="text-2xl font-semibold text-zinc-900 dark:text-white">
                        {{ number_format($product->priceFor(false) / 100, 2, ',', ' ') }} kr
                    </p>
                    @if (! $isMember && $product->hasMemberPrice())
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            Medlemspris: {{ number_format($product->priceFor(true) / 100, 2, ',', ' ') }} kr
                        </p>
                    @endif
                @endif
            </div>

            @if ($product->description)
            <div class="mt-4 prose prose-sm dark:prose-invert max-w-none text-zinc-600 dark:text-zinc-400">
                {!! nl2br(e($product->description)) !!}
            </div>
            @endif

            {{-- Variant selector --}}
            @if ($product->hasVariants())
            <div class="mt-6">
                <p class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                    {{ $product->dimension->label }}
                </p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($product->variants as $variant)
                    @php
                        $inStock   = $variant->isInStockFor($quantity);
                        $isSelected = $selectedVariantId === $variant->id;
                    @endphp
                    <button wire:click="$set('selectedVariantId', {{ $variant->id }})"
                            @class([
                                'rounded-lg border px-4 py-2 text-sm font-medium transition',
                                'border-brand bg-brand text-brand-dark' => $isSelected,
                                'border-zinc-300 text-zinc-700 hover:border-zinc-400 dark:border-zinc-600 dark:text-zinc-300' => !$isSelected && $inStock,
                                'border-zinc-200 text-zinc-300 line-through cursor-not-allowed dark:border-zinc-700 dark:text-zinc-600' => !$inStock,
                            ])
                            @if (!$inStock) disabled @endif>
                        {{ $variant->option_value }}
                    </button>
                    @endforeach
                </div>
                @error('variant') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            @endif

            {{-- Quantity --}}
            <div class="mt-6">
                <p class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">Antal</p>
                <div class="flex items-center gap-3">
                    <button wire:click="$set('quantity', {{ max(1, $quantity - 1) }})"
                            class="flex size-8 items-center justify-center rounded-lg border border-zinc-300 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-400">
                        <x-bootstrap-icon name="dash" class="size-4" />
                    </button>
                    <span class="w-8 text-center font-medium text-zinc-900 dark:text-white">{{ $quantity }}</span>
                    <button wire:click="$set('quantity', {{ $quantity + 1 }})"
                            class="flex size-8 items-center justify-center rounded-lg border border-zinc-300 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-400">
                        <x-bootstrap-icon name="plus" class="size-4" />
                    </button>
                </div>
            </div>

            {{-- Stock status --}}
            @if ($product->track_stock)
            @if ($selectedVariant && $selectedVariant->stock === 0)
                <p class="mt-3 text-sm font-medium text-red-600 dark:text-red-400">Tillfälligt slut</p>
            @elseif ($selectedVariant && $selectedVariant->stock !== null && $selectedVariant->stock <= 5)
                <p class="mt-3 text-sm text-amber-600 dark:text-amber-400">Endast {{ $selectedVariant->stock }} kvar i lager</p>
            @endif
            @endif

            {{-- Add to cart --}}
            <div class="mt-6">
                @if ($added)
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2 rounded-lg bg-green-50 px-4 py-3 text-green-700 dark:bg-green-900/30 dark:text-green-400">
                        <x-bootstrap-icon name="check-circle" class="size-5" />
                        Lagd i kundvagnen
                    </div>
                    <a href="{{ route('shop.checkout') }}" wire:navigate class="btn btn-primary">
                        Till kassan →
                    </a>
                </div>
                @else
                <button wire:click="addToCart"
                        @class([
                            'btn btn-primary w-full',
                            'opacity-50 cursor-not-allowed' => $product->track_stock && $selectedVariant?->stock === 0,
                        ])
                        @if ($product->track_stock && $selectedVariant?->stock === 0) disabled @endif>
                    <x-bootstrap-icon name="cart-plus" class="size-5" />
                    Lägg i kundvagnen
                </button>
                @endif
            </div>
        </div>
    </div>
</div>
