<?php

use App\Models\AuditLog;
use App\Models\Membership\TagYearPrice;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('RFID-taggpriser');

new class extends Component {

    public int $year;

    public array $editing = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('tags.manage'), 403);
        $this->year = now()->year;
    }

    #[Computed]
    public function yearPrice(): ?TagYearPrice
    {
        return TagYearPrice::where('year', $this->year)->first();
    }

    #[Computed]
    public function years(): array
    {
        $dbYears = TagYearPrice::selectRaw('DISTINCT year')
            ->orderByDesc('year')
            ->pluck('year')
            ->toArray();

        $fromMonth = (int) \App\Models\SystemSetting::get('membership.new_member_next_year_from_month', 10);
        $relevant  = now()->month >= $fromMonth
            ? [now()->year, now()->year + 1]
            : [now()->year];

        return array_unique(array_merge([$this->year], $relevant, $dbYears));
    }

    public function setYear(int $year): void
    {
        $this->year    = $year;
        $this->editing = [];
        unset($this->yearPrice, $this->years);
    }

    public function startEdit(): void
    {
        $p = $this->yearPrice;
        $default = (int) SystemSetting::get('tags.price_ore', 7500);

        $this->editing = [
            'price'      => number_format($p?->price_ore ?? $default, 2, '.', '') / 100,
            'annual_fee' => number_format($p?->annual_fee_ore ?? 0, 2, '.', '') / 100,
        ];
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('tags.manage'), 403);

        $priceOre  = (int) round((float) str_replace(',', '.', $this->editing['price'] ?? '0') * 100);
        $annualOre = (int) round((float) str_replace(',', '.', $this->editing['annual_fee'] ?? '0') * 100);

        TagYearPrice::updateOrCreate(
            ['year' => $this->year],
            ['price_ore' => $priceOre, 'annual_fee_ore' => $annualOre],
        );

        AuditLog::record('rfid_tag_price_saved', 'system', "År {$this->year}: inköp {$priceOre} öre, årsavgift {$annualOre} öre", null);
        $this->editing = [];
        unset($this->yearPrice);
    }

    public function cancelEdit(): void
    {
        $this->editing = [];
    }
}; ?>

<x-slot:header>
    <x-page-header permission="tags.manage" title="RFID-taggpriser" subtitle="Inköpspris och årsavgift per kalenderår." />
</x-slot:header>

<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6">

    {{-- Year selector --}}
    <div class="mb-6 flex flex-wrap gap-1.5">
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

    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        @if($this->editing)
            <div class="p-5 space-y-4">
                <div class="flex flex-wrap gap-6">
                    <div>
                        <label class="lbl">Inköpspris (kr)</label>
                        <input wire:model="editing.price"
                               type="number" step="0.01" min="0"
                               class="inp mt-1 w-32"
                               x-init="$el.focus(); $el.select()" />
                        <p class="mt-1 text-xs text-zinc-400">Engångskostnad vid utfärdande</p>
                    </div>
                    <div>
                        <label class="lbl">Årsavgift (kr)</label>
                        <input wire:model="editing.annual_fee"
                               type="number" step="0.01" min="0"
                               class="inp mt-1 w-32" />
                        <p class="mt-1 text-xs text-zinc-400">0 = ingen årsavgift</p>
                    </div>
                </div>
                <div class="flex gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                    <button wire:click="save" class="btn btn-sm btn-primary">Spara</button>
                    <button wire:click="cancelEdit" class="btn btn-sm">Avbryt</button>
                </div>
            </div>
        @else
            @php $p = $this->yearPrice; $default = (int) \App\Models\SystemSetting::get('tags.price_ore', 7500); @endphp
            <div class="flex items-center justify-between px-5 py-4">
                <div class="space-y-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Inköpspris {{ $this->year }}</p>
                        <p class="mt-0.5 text-lg font-semibold text-zinc-900 dark:text-white">
                            {{ number_format(($p?->price_ore ?? $default) / 100, 2, ',', ' ') }} kr
                            @if(! $p)
                                <span class="ml-1 text-sm font-normal text-zinc-400">(standardpris)</span>
                            @endif
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Årsavgift {{ $this->year }}</p>
                        <p class="mt-0.5 text-zinc-700 dark:text-zinc-300">
                            @if($p && $p->annual_fee_ore > 0)
                                {{ number_format($p->annual_fee_ore / 100, 2, ',', ' ') }} kr/år
                            @else
                                <span class="text-zinc-400">Ingen årsavgift</span>
                            @endif
                        </p>
                    </div>
                </div>
                <button wire:click="startEdit" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                    <x-bootstrap-icon name="pencil" class="size-4" />
                </button>
            </div>
        @endif
    </div>

    <p class="mt-3 text-xs text-zinc-400 dark:text-zinc-500">
        Standardpriset (om inget år-pris är satt) hämtas från Systeminställningar → Betalning.
    </p>

</div>
