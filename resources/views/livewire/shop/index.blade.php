<?php

use App\Models\Shop\Product;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Butik');

new class extends Component {

    public string $search = '';

    #[Computed]
    public function products()
    {
        $user     = Auth::user();
        $isMember = $user?->member?->isActive() ?? false;

        return Product::with(['dimension', 'variants'])
            ->where('status', Product::STATUS_PUBLISHED)
            ->when(! $isMember, fn ($q) => $q->where('requires_membership', false))
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

}; ?>

<x-slot:header>
    <x-page-header title="Butik" subtitle="Prylar och produkter från Armagedon." />
</x-slot:header>

<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6">

    <div class="mb-6">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Sök produkt…" class="w-64" />
    </div>

    @if ($this->products->isEmpty())
        <p class="text-center text-zinc-400 py-16">Inga produkter tillgängliga just nu.</p>
    @else
    <div class="grid grid-cols-2 gap-6 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ($this->products as $product)
        <a href="{{ route('shop.show', $product) }}" wire:navigate
           class="group flex flex-col overflow-hidden rounded-lg border border-zinc-200 bg-white transition hover:border-zinc-300 hover:shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600">
            {{-- Image --}}
            <div class="aspect-square bg-zinc-100 dark:bg-zinc-800">
                @if ($product->image_path)
                <img src="{{ asset('storage/' . $product->image_path) }}"
                     alt="{{ $product->name }}"
                     class="h-full w-full object-cover transition group-hover:scale-105" />
                @else
                <div class="flex h-full items-center justify-center">
                    <x-bootstrap-icon name="box-seam" class="size-12 text-zinc-300 dark:text-zinc-600" />
                </div>
                @endif
            </div>

            {{-- Info --}}
            <div class="flex flex-1 flex-col p-3">
                <p class="font-medium text-zinc-900 dark:text-white group-hover:text-brand-dark">{{ $product->name }}</p>

                @if ($product->hasVariants())
                <p class="mt-0.5 text-xs text-zinc-500">
                    {{ $product->dimension->label }}: {{ $product->variants->pluck('option_value')->join(', ') }}
                </p>
                @endif

                <p class="mt-auto pt-2 font-semibold text-zinc-900 dark:text-white">
                    {{ number_format($product->price_ore / 100, 2, ',', ' ') }} kr
                </p>

                @if ($product->track_stock && ! $product->isAvailable())
                <p class="text-xs font-medium text-red-600 dark:text-red-400">Tillfälligt slut</p>
                @endif
            </div>
        </a>
        @endforeach
    </div>
    @endif
</div>
