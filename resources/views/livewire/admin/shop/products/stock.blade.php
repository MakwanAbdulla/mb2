<?php

use App\Models\AuditLog;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\StockAdjustment;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title(fn () => 'Lager: ' . $this->product->name);

new class extends Component {

    public Product $product;

    // Adjust form
    public ?int    $adjustVariantId = null;
    public string  $adjustQty      = '';
    public string  $adjustReason   = StockAdjustment::REASON_ADJUSTMENT;
    public string  $adjustNotes    = '';

    // Inventory count form
    public array   $countValues = [];
    public bool    $showCount   = false;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('shop.manage'), 403);
        abort_unless($this->product->track_stock, 404);
        $this->initCountValues();
    }

    private function initCountValues(): void
    {
        $this->countValues = $this->product->variants
            ->mapWithKeys(fn ($v) => [$v->id => (string) ($v->stock ?? 0)])
            ->toArray();
    }

    #[Computed]
    public function adjustments()
    {
        $variantIds = $this->product->variants->pluck('id');

        return StockAdjustment::whereIn('product_variant_id', $variantIds)
            ->with(['productVariant', 'creator'])
            ->latest()
            ->limit(50)
            ->get();
    }

    public function startAdjust(int $variantId): void
    {
        $this->adjustVariantId = $variantId;
        $this->adjustQty       = '';
        $this->adjustReason    = StockAdjustment::REASON_ADJUSTMENT;
        $this->adjustNotes     = '';
    }

    public function saveAdjust(): void
    {
        $this->validate([
            'adjustQty'    => 'required|integer|not_in:0',
            'adjustReason' => 'required|string',
        ], [
            'adjustQty.required' => 'Ange antal.',
            'adjustQty.not_in'   => 'Antal kan inte vara 0.',
        ]);

        $variant = ProductVariant::findOrFail($this->adjustVariantId);
        $qty     = (int) $this->adjustQty;
        $newStock = max(0, ($variant->stock ?? 0) + $qty);

        $variant->update(['stock' => $newStock]);
        $variant->stockAdjustments()->create([
            'quantity_change' => $qty,
            'reason'          => $this->adjustReason,
            'notes'           => $this->adjustNotes ?: null,
            'created_by'      => Auth::id(),
        ]);

        AuditLog::record(
            action: 'stock_adjusted',
            area: 'shop',
            subject: "{$this->product->name}" . ($variant->option_value ? " ({$variant->option_value})" : ''),
            auditable: $this->product,
        );

        $this->adjustVariantId = null;
        $this->product->refresh();
        $this->product->load('variants');
        unset($this->adjustments);
    }

    public function saveInventoryCount(): void
    {
        $this->validate([
            'countValues.*' => 'required|integer|min:0',
        ], [
            'countValues.*.required' => 'Ange lagerantal för alla varianter.',
            'countValues.*.min'      => 'Lagerantal kan inte vara negativt.',
        ]);

        foreach ($this->product->variants as $variant) {
            $newStock = (int) ($this->countValues[$variant->id] ?? 0);
            $diff     = $newStock - ($variant->stock ?? 0);

            $variant->update(['stock' => $newStock]);

            if ($diff !== 0) {
                $variant->stockAdjustments()->create([
                    'quantity_change' => $diff,
                    'reason'          => StockAdjustment::REASON_INVENTORY_COUNT,
                    'created_by'      => Auth::id(),
                ]);
            }
        }

        AuditLog::record(action: 'inventory_count', area: 'shop', subject: $this->product->name, auditable: $this->product);

        $this->showCount = false;
        $this->product->refresh();
        $this->product->load('variants');
        $this->initCountValues();
        unset($this->adjustments);
    }

}; ?>

<div>
    <div class="mb-6 flex items-center gap-3">
        <a href="{{ route('admin.shop.products.index') }}" wire:navigate class="text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
            <x-bootstrap-icon name="arrow-left" class="size-5" />
        </a>
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Lager — {{ $product->name }}</h1>
            <p class="text-sm text-zinc-500">
                <a href="{{ route('admin.shop.products.edit', $product) }}" wire:navigate class="hover:underline">Redigera produkt</a>
            </p>
        </div>
    </div>

    {{-- Current stock --}}
    <div class="mb-6 rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="font-semibold text-zinc-900 dark:text-white">Nuvarande lager</h2>
            <button wire:click="$set('showCount', true)" class="btn btn-ghost text-sm">
                <x-bootstrap-icon name="clipboard-check" class="size-4" /> Inventering
            </button>
        </div>

        <table class="w-full">
            <thead>
                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                    @if ($product->hasVariants())
                    <th class="pb-2 text-left text-sm font-medium text-zinc-500">{{ $product->dimension->label }}</th>
                    @endif
                    <th class="pb-2 text-right text-sm font-medium text-zinc-500">Saldo</th>
                    <th class="pb-2 text-right text-sm font-medium text-zinc-500">SKU</th>
                    <th class="pb-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($product->variants as $variant)
                <tr>
                    @if ($product->hasVariants())
                    <td class="py-2 text-sm text-zinc-900 dark:text-white">{{ $variant->option_value }}</td>
                    @endif
                    <td class="py-2 text-right">
                        @if ($variant->stock === null)
                            <span class="text-zinc-400">∞</span>
                        @elseif ($variant->stock === 0)
                            <span class="font-semibold text-red-600 dark:text-red-400">0 st</span>
                        @else
                            <span class="font-semibold text-zinc-900 dark:text-white">{{ $variant->stock }} st</span>
                        @endif
                    </td>
                    <td class="py-2 text-right text-sm text-zinc-400">{{ $variant->sku ?? '–' }}</td>
                    <td class="py-2 text-right">
                        @if ($adjustVariantId === $variant->id)
                            {{-- Inline adjust form --}}
                            <form wire:submit="saveAdjust" class="flex items-center gap-2">
                                <input wire:model="adjustQty" type="number" class="inp w-20 text-sm" placeholder="±antal" />
                                <select wire:model="adjustReason" class="inp text-sm">
                                    <option value="{{ StockAdjustment::REASON_ADJUSTMENT }}">Justering</option>
                                    <option value="{{ StockAdjustment::REASON_RETURN }}">Retur</option>
                                </select>
                                <input wire:model="adjustNotes" type="text" class="inp w-32 text-sm" placeholder="Notering (valfritt)" />
                                <button type="submit" class="btn btn-primary text-xs">Spara</button>
                                <button type="button" wire:click="$set('adjustVariantId', null)" class="btn btn-ghost text-xs">Avbryt</button>
                            </form>
                        @else
                            <button wire:click="startAdjust({{ $variant->id }})" class="btn btn-ghost text-xs">
                                <x-bootstrap-icon name="plus-slash-minus" class="size-3.5" /> Justera
                            </button>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Inventory count --}}
    @if ($showCount)
    <div class="mb-6 rounded-lg border border-brand/40 bg-white p-6 dark:border-brand/30 dark:bg-zinc-900">
        <h2 class="mb-4 font-semibold text-zinc-900 dark:text-white">Inventering — ange faktiskt lagerantal</h2>
        <form wire:submit="saveInventoryCount" class="space-y-3">
            @foreach ($product->variants as $variant)
            <div class="flex items-center gap-4">
                @if ($product->hasVariants())
                <span class="w-24 text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $variant->option_value }}</span>
                @endif
                <span class="text-sm text-zinc-500">Nuvarande: {{ $variant->stock ?? '∞' }}</span>
                <input wire:model="countValues.{{ $variant->id }}" type="number" min="0" class="inp w-24" />
                @error("countValues.{$variant->id}") <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </div>
            @endforeach
            <div class="flex gap-3 pt-2">
                <button type="submit" class="btn btn-primary">Spara inventering</button>
                <button type="button" wire:click="$set('showCount', false)" class="btn btn-ghost">Avbryt</button>
            </div>
        </form>
    </div>
    @endif

    {{-- History --}}
    <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <h2 class="mb-4 font-semibold text-zinc-900 dark:text-white">Historik (senaste 50)</h2>
        @if ($this->adjustments->isEmpty())
            <p class="text-sm text-zinc-400">Inga lagerrörelser registrerade.</p>
        @else
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                    <th class="pb-2 text-left font-medium text-zinc-500">Datum</th>
                    @if ($product->hasVariants())
                    <th class="pb-2 text-left font-medium text-zinc-500">Variant</th>
                    @endif
                    <th class="pb-2 text-right font-medium text-zinc-500">Ändring</th>
                    <th class="pb-2 text-left font-medium text-zinc-500">Orsak</th>
                    <th class="pb-2 text-left font-medium text-zinc-500">Notering</th>
                    <th class="pb-2 text-left font-medium text-zinc-500">Av</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($this->adjustments as $adj)
                <tr>
                    <td class="py-1.5 text-zinc-500">{{ $adj->created_at->format('d/m Y H:i') }}</td>
                    @if ($product->hasVariants())
                    <td class="py-1.5">{{ $adj->productVariant->option_value }}</td>
                    @endif
                    <td class="py-1.5 text-right font-mono {{ $adj->quantity_change > 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                        {{ $adj->quantity_change > 0 ? '+' : '' }}{{ $adj->quantity_change }}
                    </td>
                    <td class="py-1.5 text-zinc-600 dark:text-zinc-400">
                        @match($adj->reason)
                            'sale'            => 'Försäljning',
                            'return'          => 'Retur',
                            'adjustment'      => 'Justering',
                            'inventory_count' => 'Inventering',
                            default           => $adj->reason,
                        @endmatch
                    </td>
                    <td class="py-1.5 text-zinc-500">{{ $adj->notes ?? '–' }}</td>
                    <td class="py-1.5 text-zinc-500">{{ $adj->creator?->name ?? '–' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
</div>
