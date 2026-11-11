<?php

use App\Models\AuditLog;
use App\Models\Shop\Product;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Produkter');

new class extends Component {

    public string $search = '';
    public string $filterStatus = '';
    public string $filterType   = '';
    public ?int   $confirmDelete = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('shop.manage'), 403);
    }

    #[Computed]
    public function products()
    {
        return Product::withTrashed()
            ->with(['dimension', 'variants'])
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterType, fn ($q) => $q->where('type', $this->filterType))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function publish(int $id): void
    {
        $product = Product::findOrFail($id);
        $product->update(['status' => Product::STATUS_PUBLISHED]);
        AuditLog::record(action: 'product_published', area: 'shop', subject: $product->name, auditable: $product);
        unset($this->products);
    }

    public function archive(int $id): void
    {
        $product = Product::findOrFail($id);
        $product->update(['status' => Product::STATUS_ARCHIVED]);
        AuditLog::record(action: 'product_archived', area: 'shop', subject: $product->name, auditable: $product);
        unset($this->products);
    }

    public function restore(int $id): void
    {
        $product = Product::withTrashed()->findOrFail($id);
        $product->restore();
        $product->update(['status' => Product::STATUS_DRAFT]);
        AuditLog::record(action: 'product_restored', area: 'shop', subject: $product->name, auditable: $product);
        unset($this->products);
    }

    public function delete(int $id): void
    {
        $product = Product::findOrFail($id);
        $name    = $product->name;
        $product->delete();
        $this->confirmDelete = null;
        AuditLog::record(action: 'product_deleted', area: 'shop', subject: $name);
        unset($this->products);
    }

}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Produkter</h1>
        <a href="{{ route('admin.shop.products.create') }}" wire:navigate class="btn btn-primary">
            <x-bootstrap-icon name="plus-lg" class="size-4" />
            Ny produkt
        </a>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap gap-3">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Sök produkt..." class="w-64" />
        <flux:select wire:model.live="filterStatus" class="w-44">
            <option value="">Alla statusar</option>
            <option value="{{ Product::STATUS_DRAFT }}">Utkast</option>
            <option value="{{ Product::STATUS_PUBLISHED }}">Publicerad</option>
            <option value="{{ Product::STATUS_ARCHIVED }}">Arkiverad</option>
        </flux:select>
        <flux:select wire:model.live="filterType" class="w-40">
            <option value="">Alla typer</option>
            <option value="{{ Product::TYPE_PHYSICAL }}">Fysisk</option>
            <option value="{{ Product::TYPE_DIGITAL }}">Digital</option>
        </flux:select>
    </div>

    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
            <thead class="bg-zinc-50 dark:bg-zinc-800">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Produkt</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Typ</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Pris</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Lager</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                @forelse ($this->products as $product)
                <tr class="{{ $product->trashed() ? 'opacity-50' : '' }}">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            @if ($product->image_path)
                            <img src="{{ asset('storage/' . $product->image_path) }}" class="size-10 rounded object-cover" alt="">
                            @else
                            <div class="flex size-10 items-center justify-center rounded bg-zinc-100 dark:bg-zinc-800">
                                <x-bootstrap-icon name="box-seam" class="size-5 text-zinc-400" />
                            </div>
                            @endif
                            <div>
                                <p class="font-medium text-zinc-900 dark:text-white">{{ $product->name }}</p>
                                @if ($product->hasVariants())
                                <p class="text-xs text-zinc-500">{{ $product->dimension->label }}: {{ $product->variants->pluck('option_value')->join(', ') }}</p>
                                @endif
                                @if ($product->show_in_event_checkout)
                                <span class="inline-flex items-center gap-1 rounded bg-complement/10 px-1.5 py-0.5 text-xs text-complement-light">
                                    <x-bootstrap-icon name="ticket" class="size-3" /> Upsell
                                </span>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ $product->type === Product::TYPE_PHYSICAL ? 'Fysisk' : 'Digital' }}
                    </td>
                    <td class="px-4 py-3 text-sm text-zinc-900 dark:text-white">
                        {{ number_format($product->price_ore / 100, 2, ',', ' ') }} kr
                    </td>
                    <td class="px-4 py-3 text-sm">
                        @if (!$product->track_stock)
                            <span class="text-zinc-400">–</span>
                        @elseif ($product->isAvailable())
                            <span class="text-green-600 dark:text-green-400">
                                {{ $product->variants->sum('stock') }} st
                            </span>
                        @else
                            <span class="font-medium text-red-600 dark:text-red-400">Slut</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if ($product->trashed())
                            <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                <x-bootstrap-icon name="trash" class="size-3" /> Raderad
                            </span>
                        @elseif ($product->isPublished())
                            <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                <x-bootstrap-icon name="check-circle" class="size-3" /> Publicerad
                            </span>
                        @elseif ($product->isArchived())
                            <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                <x-bootstrap-icon name="archive" class="size-3" /> Arkiverad
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                <x-bootstrap-icon name="pencil" class="size-3" /> Utkast
                            </span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-2">
                            @if ($product->trashed())
                                <button wire:click="restore({{ $product->id }})" class="btn btn-ghost text-xs">Återställ</button>
                            @else
                                @if ($product->track_stock)
                                <a href="{{ route('admin.shop.products.stock', $product) }}" wire:navigate class="btn btn-ghost text-xs">
                                    <x-bootstrap-icon name="boxes" class="size-3.5" /> Lager
                                </a>
                                @endif
                                <a href="{{ route('admin.shop.products.edit', $product) }}" wire:navigate class="btn btn-ghost text-xs">
                                    <x-bootstrap-icon name="pencil" class="size-3.5" /> Redigera
                                </a>
                                @if ($product->isDraft())
                                    <button wire:click="publish({{ $product->id }})" class="btn btn-ghost text-xs text-green-600">Publicera</button>
                                @elseif ($product->isPublished())
                                    <button wire:click="archive({{ $product->id }})" class="btn btn-ghost text-xs">Arkivera</button>
                                @endif
                                @if (!$product->isPublished())
                                    @if ($confirmDelete === $product->id)
                                        <button wire:click="delete({{ $product->id }})" class="btn btn-danger text-xs">Bekräfta radering</button>
                                        <button wire:click="$set('confirmDelete', null)" class="btn btn-ghost text-xs">Avbryt</button>
                                    @else
                                        <button wire:click="$set('confirmDelete', {{ $product->id }})" class="btn btn-ghost text-xs text-red-600">Radera</button>
                                    @endif
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-10 text-center text-sm text-zinc-400">
                        Inga produkter hittades.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
