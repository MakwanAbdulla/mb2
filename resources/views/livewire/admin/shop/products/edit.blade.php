<?php

use App\Models\AuditLog;
use App\Models\Shop\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title(fn () => 'Redigera: ' . $this->product->name);

new class extends Component {

    public Product $product;

    public string $name                = '';
    public string $description         = '';
    public string $type                = '';
    public string $status              = '';
    public string $priceOre            = '';
    public string $memberPriceOre      = '';
    public bool   $requiresMembership  = false;
    public bool   $showInEventCheckout = false;
    public bool   $pickupOnly          = false;
    public bool   $trackStock          = false;

    public bool   $hasVariants     = false;
    public string $dimensionLabel  = '';
    public array  $variants        = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('shop.manage'), 403);

        $this->name                = $this->product->name;
        $this->description         = $this->product->description ?? '';
        $this->type                = $this->product->type;
        $this->status              = $this->product->status;
        $this->priceOre            = number_format($this->product->price_ore / 100, 2, '.', '');
        $this->memberPriceOre      = $this->product->member_price_ore !== null
            ? number_format($this->product->member_price_ore / 100, 2, '.', '')
            : '';
        $this->requiresMembership  = $this->product->requires_membership;
        $this->showInEventCheckout = $this->product->show_in_event_checkout;
        $this->pickupOnly          = $this->product->pickup_only;
        $this->trackStock          = $this->product->track_stock;
        $this->hasVariants         = $this->product->hasVariants();
        $this->dimensionLabel      = $this->product->dimension?->label ?? '';

        $this->variants = $this->product->variants->map(fn ($v) => [
            'id'           => $v->id,
            'option_value' => $v->option_value,
            'stock'        => $v->stock !== null ? (string) $v->stock : '',
        ])->toArray();

        if (empty($this->variants)) {
            $this->variants = [['id' => null, 'option_value' => '', 'stock' => '']];
        }
    }

    public function addVariant(): void
    {
        $this->variants[] = ['id' => null, 'option_value' => '', 'stock' => ''];
    }

    public function removeVariant(int $index): void
    {
        if (count($this->variants) > 1) {
            array_splice($this->variants, $index, 1);
        }
    }

    public function save(): void
    {
        $priceOre       = (int) round((float) str_replace(',', '.', $this->priceOre) * 100);
        $memberPriceOre = $this->memberPriceOre !== ''
            ? (int) round((float) str_replace(',', '.', $this->memberPriceOre) * 100)
            : null;

        $rules = [
            'name'               => 'required|string|max:255',
            'description'        => 'nullable|string',
            'type'               => ['required', Rule::in([Product::TYPE_PHYSICAL, Product::TYPE_DIGITAL])],
            'status'             => ['required', Rule::in([Product::STATUS_DRAFT, Product::STATUS_PUBLISHED, Product::STATUS_ARCHIVED])],
            'priceOre'           => 'required|numeric|min:0',
            'memberPriceOre'     => 'nullable|numeric|min:0',
            'requiresMembership' => 'boolean',
            'showInEventCheckout'=> 'boolean',
            'pickupOnly'         => 'boolean',
            'trackStock'         => 'boolean',
        ];

        if ($this->hasVariants) {
            $rules['dimensionLabel']          = 'required|string|max:50';
            $rules['variants']                = 'required|array|min:1';
            $rules['variants.*.option_value'] = 'required|string|max:100';
        }

        $this->validate($rules, [
            'name.required'                    => 'Namn krävs.',
            'priceOre.required'                => 'Pris krävs.',
            'dimensionLabel.required'          => 'Ange namn på variantdimensionen.',
            'variants.*.option_value.required' => 'Variantvärdet får inte vara tomt.',
        ]);

        $this->product->update([
            'name'                   => $this->name,
            'description'            => $this->description,
            'type'                   => $this->type,
            'status'                 => $this->status,
            'price_ore'              => $priceOre,
            'member_price_ore'       => $memberPriceOre,
            'requires_membership'    => $this->requiresMembership,
            'show_in_event_checkout' => $this->showInEventCheckout,
            'pickup_only'            => $this->pickupOnly,
            'track_stock'            => $this->trackStock,
        ]);

        // Sync dimension
        if ($this->hasVariants) {
            $this->product->dimension()->updateOrCreate(
                ['product_id' => $this->product->id],
                ['label' => $this->dimensionLabel],
            );

            $existingIds = collect($this->variants)->pluck('id')->filter()->toArray();
            $this->product->variants()->whereNotIn('id', $existingIds)->delete();

            foreach (array_values($this->variants) as $i => $v) {
                $this->product->variants()->updateOrCreate(
                    ['id' => $v['id'] ?? null],
                    [
                        'option_value' => $v['option_value'],
                        'stock'        => $this->trackStock && $v['stock'] !== '' ? (int) $v['stock'] : null,
                        'sort_order'           => $i,
                    ],
                );
            }
        } else {
            $this->product->dimension()->delete();
            // Ensure single default variant exists
            $this->product->variants()->where('option_value', '!=', '')->delete();
            $this->product->variants()->firstOrCreate(
                ['option_value' => ''],
                ['sort_order' => 0],
            );
        }

        AuditLog::record(action: 'product_updated', area: 'shop', subject: $this->product->name, auditable: $this->product);

        $this->redirect(route('admin.shop.products.index'), navigate: true);
    }

}; ?>

<div>
    <div class="mb-6 flex items-center gap-3">
        <a href="{{ route('admin.shop.products.index') }}" wire:navigate class="text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
            <x-bootstrap-icon name="arrow-left" class="size-5" />
        </a>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $product->name }}</h1>
    </div>

    <form wire:submit="save" class="space-y-6">

        {{-- Grundinfo --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="mb-4 font-semibold text-zinc-900 dark:text-white">Grundinformation</h2>
            <div class="space-y-4">
                <div>
                    <label class="lbl">Namn</label>
                    <input wire:model="name" type="text" class="inp w-full" required />
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lbl">Beskrivning</label>
                    <textarea wire:model="description" rows="4" class="inp w-full"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="lbl">Typ</label>
                        <select wire:model="type" class="inp w-full">
                            <option value="{{ Product::TYPE_PHYSICAL }}">Fysisk vara</option>
                            <option value="{{ Product::TYPE_DIGITAL }}">Digital vara</option>
                        </select>
                    </div>
                    <div>
                        <label class="lbl">Status</label>
                        <select wire:model="status" class="inp w-full">
                            <option value="{{ Product::STATUS_DRAFT }}">Utkast</option>
                            <option value="{{ Product::STATUS_PUBLISHED }}">Publicerad</option>
                            <option value="{{ Product::STATUS_ARCHIVED }}">Arkiverad</option>
                        </select>
                    </div>
                </div>
                <div class="flex gap-4 items-end">
                    <div>
                        <label class="lbl">Pris (kr)</label>
                        <input wire:model="priceOre" type="number" step="0.01" min="0" class="inp w-40" required />
                        @error('priceOre') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lbl">Medlemspris (kr) <span class="text-zinc-400 font-normal">valfritt</span></label>
                        <input wire:model="memberPriceOre" type="number" step="0.01" min="0" class="inp w-40" placeholder="–" />
                        @error('memberPriceOre') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- Inställningar --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="mb-4 font-semibold text-zinc-900 dark:text-white">Inställningar</h2>
            <div class="space-y-3">
                <label class="flex items-center gap-3">
                    <input type="checkbox" wire:model="requiresMembership" class="rounded border-zinc-300" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">Kräver aktivt medlemskap</span>
                </label>
                <label class="flex items-center gap-3">
                    <input type="checkbox" wire:model="showInEventCheckout" class="rounded border-zinc-300" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">Visa som upsell i event-checkout</span>
                </label>
                <label class="flex items-center gap-3">
                    <input type="checkbox" wire:model="pickupOnly" class="rounded border-zinc-300" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">Endast upphämtning (kan inte skickas)</span>
                </label>
                <label class="flex items-center gap-3">
                    <input type="checkbox" wire:model.live="trackStock" class="rounded border-zinc-300" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">Spåra lagersaldo</span>
                </label>
            </div>
        </div>

        {{-- Varianter --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="font-semibold text-zinc-900 dark:text-white">Varianter</h2>
                <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                    <input type="checkbox" wire:model.live="hasVariants" class="rounded border-zinc-300" />
                    Flera varianter
                </label>
            </div>

            @if ($hasVariants)
            <div class="space-y-4">
                <div>
                    <label class="lbl">Varianttyp</label>
                    <input wire:model="dimensionLabel" type="text" class="inp w-48" placeholder="t.ex. Storlek, Färg" />
                    @error('dimensionLabel') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="space-y-2">
                    @foreach ($variants as $i => $variant)
                    <div class="flex items-center gap-2">
                        <input wire:model="variants.{{ $i }}.option_value" type="text" class="inp w-32" placeholder="S, M, L…" />
                        @if ($trackStock)
                        <span class="text-sm text-zinc-500">Lager</span>
                        <input wire:model="variants.{{ $i }}.stock" type="number" min="0" class="inp w-20" />
                        @endif
                        @if (count($variants) > 1)
                        <button type="button" wire:click="removeVariant({{ $i }})" class="text-red-500 hover:text-red-700">
                            <x-bootstrap-icon name="x-lg" class="size-4" />
                        </button>
                        @endif
                    </div>
                    @endforeach
                </div>
                <button type="button" wire:click="addVariant" class="btn btn-ghost text-sm">
                    <x-bootstrap-icon name="plus" class="size-4" /> Lägg till variant
                </button>
            </div>
            @elseif ($trackStock)
            <p class="text-sm text-zinc-500">Ingen variantdimension — justera lager på <a href="{{ route('admin.shop.products.stock', $product) }}" wire:navigate class="underline">lagersidan</a>.</p>
            @else
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Produkten är enhetlig — inga varianter.</p>
            @endif
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.shop.products.index') }}" wire:navigate class="btn btn-ghost">Avbryt</a>
            <button type="submit" class="btn btn-primary">Spara ändringar</button>
        </div>
    </form>
</div>
