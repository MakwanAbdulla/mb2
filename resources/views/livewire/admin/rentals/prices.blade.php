<?php

use App\Models\AuditLog;
use App\Models\Rental\RentalItemType;
use App\Models\Rental\RentalTypePrice;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Hyresnivåer');

new class extends Component {

    public int $year;

    // Inline edit: keyed by rental_item_type_id
    public array $editing = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('rentals.manage'), 403);
        $this->year = now()->year;
    }

    #[Computed]
    public function types(): \Illuminate\Support\Collection
    {
        return RentalItemType::with(['yearPrices' => fn ($q) => $q->where('year', $this->year)])
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function years(): array
    {
        $dbYears = RentalTypePrice::selectRaw('DISTINCT year')
            ->orderByDesc('year')
            ->pluck('year')
            ->toArray();

        $fromMonth = (int) \App\Models\SystemSetting::get('membership.new_member_next_year_from_month', 10);
        $relevant  = now()->month >= $fromMonth
            ? [now()->year, now()->year + 1]
            : [now()->year];

        return array_unique(array_merge([$this->year], $relevant, $dbYears));
    }

    #[Computed]
    public function previousYear(): ?int
    {
        $prev = $this->year - 1;
        return RentalTypePrice::where('year', $prev)->exists() ? $prev : null;
    }

    public function startEdit(int $typeId): void
    {
        $type  = RentalItemType::findOrFail($typeId);
        $price = RentalTypePrice::where('rental_item_type_id', $typeId)
            ->where('year', $this->year)
            ->first();

        $this->editing[$typeId] = [
            'price' => $price ? number_format($price->price_ore / 100, 2, '.', '') : number_format($type->price_per_year / 100, 2, '.', ''),
        ];
    }

    public function saveEdit(int $typeId): void
    {
        abort_unless(Auth::user()->hasPermissionTo('rentals.manage'), 403);

        $priceOre = (int) round((float) str_replace(',', '.', $this->editing[$typeId]['price'] ?? '0') * 100);

        RentalTypePrice::updateOrCreate(
            ['rental_item_type_id' => $typeId, 'year' => $this->year],
            ['price_ore' => $priceOre],
        );

        $typeName = RentalItemType::find($typeId)?->name ?? (string) $typeId;
        AuditLog::record('rental_price_saved', 'rentals', "{$typeName} {$this->year}: {$priceOre} öre", null);
        unset($this->editing[$typeId]);
        unset($this->types);
    }

    public function cancelEdit(int $typeId): void
    {
        unset($this->editing[$typeId]);
    }

    public function copyFromPreviousYear(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('rentals.manage'), 403);

        $prev = $this->year - 1;

        RentalTypePrice::where('year', $prev)->each(function (RentalTypePrice $p) {
            RentalTypePrice::updateOrCreate(
                ['rental_item_type_id' => $p->rental_item_type_id, 'year' => $this->year],
                ['price_ore' => $p->price_ore],
            );
        });

        AuditLog::record('rental_prices_copied', 'rentals', "År {$this->year} kopierat från {$prev}", null);
        unset($this->types);
    }

    public function setYear(int $year): void
    {
        $this->year    = $year;
        $this->editing = [];
        unset($this->types, $this->years, $this->previousYear);
    }
}; ?>

<x-slot:header>
    <x-page-header permission="rentals.manage" title="Hyresnivåer" subtitle="Pris per hyrestyp och kalenderår." />
</x-slot:header>

<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">

    {{-- Year selector --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-1.5">
            @foreach(array_unique(array_merge([$this->year], $this->years)) as $y)
                <button wire:click="setYear({{ $y }})"
                        @class([
                            'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                            'bg-brand text-brand-dark' => $this->year === $y,
                            'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' => $this->year !== $y,
                        ])>
                    {{ $y }}
                </button>
            @endforeach
        </div>

        @if($this->previousYear && ! $this->types->contains(fn ($t) => $t->yearPrices->isNotEmpty()))
            <button wire:click="copyFromPreviousYear"
                    wire:confirm="Kopiera alla priser från {{ $this->previousYear }} till {{ $this->year }}?"
                    class="btn btn-sm">
                <x-bootstrap-icon name="copy" class="size-3.5" />
                Kopiera från {{ $this->previousYear }}
            </button>
        @endif
    </div>

    {{-- Price table --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
            <thead>
                <tr class="bg-zinc-50 dark:bg-zinc-800/80">
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Hyrestyp</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Pris {{ $this->year }}</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse($this->types as $type)
                    @php $yearPrice = $type->yearPrices->first(); @endphp
                    <tr>
                        <td class="px-5 py-3">
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $type->name }}</span>
                            @if(! $type->is_active)
                                <span class="ml-2 text-xs text-zinc-400">Inaktiv</span>
                            @endif
                        </td>
                        <td class="px-5 py-3">
                            @if(isset($editing[$type->id]))
                                <input wire:model="editing.{{ $type->id }}.price"
                                       wire:keydown.enter="saveEdit({{ $type->id }})"
                                       wire:keydown.escape="cancelEdit({{ $type->id }})"
                                       type="number" step="0.01" min="0"
                                       class="inp w-28"
                                       x-init="$el.focus(); $el.select()" />
                            @elseif($yearPrice)
                                <span class="text-zinc-900 dark:text-white">
                                    {{ number_format($yearPrice->price_ore / 100, 2, ',', ' ') }} kr/år
                                </span>
                            @else
                                <span class="text-zinc-400 dark:text-zinc-500">
                                    {{ $type->formattedPrice() }}
                                    <span class="text-xs">(standardpris)</span>
                                </span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right">
                            @if(isset($editing[$type->id]))
                                <div class="flex justify-end gap-2">
                                    <button wire:click="saveEdit({{ $type->id }})" class="btn btn-sm btn-primary">Spara</button>
                                    <button wire:click="cancelEdit({{ $type->id }})" class="btn btn-sm">Avbryt</button>
                                </div>
                            @else
                                <button wire:click="startEdit({{ $type->id }})"
                                        class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                    <x-bootstrap-icon name="pencil" class="size-3.5" />
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-5 py-8 text-center text-sm text-zinc-400">
                            Inga hyrestyper konfigurerade.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-xs text-zinc-400 dark:text-zinc-500">
        Standardpriset används om inget specifikt år-pris är satt. Ändra standardpris under hyresöversikten (pennikon).
    </p>

</div>
