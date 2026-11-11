<?php

use App\Models\AuditLog;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipFee;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Medlemsavgifter');

new class extends Component {

    public int   $year;
    public array $rows = [];

    // Fixed display order, matching CogWorks category order
    private const TYPE_ORDER = [
        Member::TYPE_SINGLE,
        Member::TYPE_CHILD,
        Member::TYPE_YOUTH,
        Member::TYPE_SENIOR,
        Member::TYPE_STUDENT,
        Member::TYPE_FAMILY,
        Member::TYPE_HONORARY,
    ];

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('membership-periods.manage'), 403);

        $this->year = now()->year;
        $this->loadRows();
    }

    private function loadRows(): void
    {
        $fees = MembershipFee::where('year', $this->year)
            ->get()
            ->keyBy('membership_type');

        $this->rows = [];
        foreach (self::TYPE_ORDER as $type) {
            $fee = $fees->get($type);
            $this->rows[$type] = [
                'is_active' => $fee ? $fee->is_active : false,
                'amount'    => $fee ? (string) ($fee->amount / 100) : '',
                'min_age'   => $fee && $fee->min_age !== null ? (string) $fee->min_age : '',
                'max_age'   => $fee && $fee->max_age !== null ? (string) $fee->max_age : '',
            ];
        }
    }

    #[\Livewire\Attributes\Computed]
    public function years(): array
    {
        $dbYears = MembershipFee::selectRaw('DISTINCT year')
            ->orderByDesc('year')
            ->pluck('year')
            ->toArray();

        $fromMonth = (int) \App\Models\SystemSetting::get('membership.new_member_next_year_from_month', 10);
        $relevant  = now()->month >= $fromMonth
            ? [now()->year, now()->year + 1]
            : [now()->year];

        return array_unique(array_merge([$this->year], $relevant, $dbYears));
    }

    #[\Livewire\Attributes\Computed]
    public function previousYear(): ?int
    {
        $prev = $this->year - 1;
        return MembershipFee::where('year', $prev)->exists() ? $prev : null;
    }

    #[\Livewire\Attributes\Computed]
    public function hasAnyActive(): bool
    {
        return collect($this->rows)->contains('is_active', true);
    }

    public function updatedYear(): void
    {
        $this->loadRows();
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $rules = [];
        foreach (self::TYPE_ORDER as $type) {
            if ($this->rows[$type]['is_active'] ?? false) {
                $rules["rows.{$type}.amount"]  = 'required|numeric|min:0';
                $rules["rows.{$type}.min_age"] = 'nullable|integer|min:0|max:130';
                $rules["rows.{$type}.max_age"] = 'nullable|integer|min:0|max:130';
            }
        }
        $this->validate($rules, [
            'rows.*.amount.required' => 'Ange ett belopp för aktiva typer.',
            'rows.*.amount.numeric'  => 'Beloppet måste vara ett tal.',
        ]);

        foreach (self::TYPE_ORDER as $i => $type) {
            $row = $this->rows[$type];

            if (! $row['is_active'] && $row['amount'] === '') {
                // Never configured — skip entirely
                continue;
            }

            MembershipFee::updateOrCreate(
                ['year' => $this->year, 'membership_type' => $type],
                [
                    'is_active'  => $row['is_active'],
                    'amount'     => $row['is_active'] ? (int) round((float) $row['amount'] * 100) : 0,
                    'min_age'    => $row['min_age'] !== '' ? (int) $row['min_age'] : null,
                    'max_age'    => $row['max_age'] !== '' ? (int) $row['max_age'] : null,
                    'sort_order' => $i,
                ]
            );
        }

        AuditLog::record('membership_fees_saved', 'system', "År {$this->year}", null);
        $this->loadRows();
        session()->flash('saved', 'Avgifter för ' . $this->year . ' sparades.');
    }

    public function copyFromYear(int $fromYear): void
    {
        $source = MembershipFee::where('year', $fromYear)
            ->get()
            ->keyBy('membership_type');

        foreach (self::TYPE_ORDER as $type) {
            $fee = $source->get($type);
            if (! $fee) {
                continue;
            }
            // Only copy if this type has no amount set yet for the current year
            if ($this->rows[$type]['amount'] === '') {
                $this->rows[$type] = [
                    'is_active' => $fee->is_active,
                    'amount'    => (string) ($fee->amount / 100),
                    'min_age'   => $fee->min_age !== null ? (string) $fee->min_age : '',
                    'max_age'   => $fee->max_age !== null ? (string) $fee->max_age : '',
                ];
            }
        }
    }

    public function typeLabel(string $type): string
    {
        return match($type) {
            Member::TYPE_SINGLE   => 'Vuxen',
            Member::TYPE_CHILD    => 'Barn',
            Member::TYPE_YOUTH    => 'Ungdom',
            Member::TYPE_SENIOR   => 'Senior',
            Member::TYPE_STUDENT  => 'Student',
            Member::TYPE_FAMILY   => 'Familj',
            Member::TYPE_HONORARY => 'Hedersmedlem',
            default               => $type,
        };
    }
}; ?>

<div>
    <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6">

        {{-- Year selector --}}
        <div class="flex flex-wrap gap-1.5">
            @foreach(collect($this->years)->sort()->reverse()->unique()->values() as $y)
                <button wire:click="$set('year', {{ $y }})"
                        @class([
                            'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                            'bg-brand text-brand-dark' => $year === $y,
                            'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' => $year !== $y,
                        ])>
                    {{ $y }}
                </button>
            @endforeach
        </div>

        @if(session('saved'))
            <div x-data x-init="setTimeout(() => $el.remove(), 4000)"
                 class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                {{ session('saved') }}
            </div>
        @endif

        {{-- Copy from previous year --}}
        @if($this->previousYear)
            <div class="flex justify-end">
                <button wire:click="copyFromYear({{ $this->previousYear }})"
                        wire:confirm="Fyller i saknade typer från {{ $this->previousYear }}. Befintliga värden för {{ $year }} berörs inte. Fortsätta?"
                        class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200 underline underline-offset-2">
                    Kopiera saknade typer från {{ $this->previousYear }}
                </button>
            </div>
        @endif

        {{-- Fee grid --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                        <th class="w-10 px-4 py-3"></th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Medlemskategori</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Max ålder</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Pris (kr)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach([
                        Member::TYPE_SINGLE,
                        Member::TYPE_CHILD,
                        Member::TYPE_YOUTH,
                        Member::TYPE_SENIOR,
                        Member::TYPE_STUDENT,
                        Member::TYPE_FAMILY,
                        Member::TYPE_HONORARY,
                    ] as $type)
                        @php $active = $rows[$type]['is_active'] ?? false; @endphp
                        <tr @class([
                                'transition-colors',
                                'bg-white dark:bg-zinc-900' => $active,
                                'bg-zinc-50/50 dark:bg-zinc-900/50' => ! $active,
                            ])>
                            <td class="px-4 py-3 text-center">
                                <input type="checkbox"
                                       wire:model.live="rows.{{ $type }}.is_active"
                                       class="size-4 rounded accent-complement cursor-pointer" />
                            </td>
                            <td class="px-4 py-3 font-medium {{ $active ? 'text-zinc-900 dark:text-white' : 'text-zinc-400 dark:text-zinc-500' }}">
                                {{ $this->typeLabel($type) }}
                            </td>
                            <td class="px-4 py-3">
                                <input wire:model="rows.{{ $type }}.max_age"
                                       type="number" min="0" max="130" placeholder="—"
                                       @disabled(! $active)
                                       class="w-20 rounded border px-2 py-1 text-sm text-center
                                           {{ $active
                                               ? 'border-zinc-300 bg-white text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100'
                                               : 'border-zinc-200 bg-transparent text-zinc-300 dark:border-zinc-700 dark:text-zinc-600 cursor-not-allowed' }}" />
                                @error("rows.{$type}.max_age") <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-1.5">
                                    <input wire:model="rows.{{ $type }}.amount"
                                           type="number" step="0.01" min="0" placeholder="0"
                                           @disabled(! $active)
                                           class="w-24 rounded border px-2 py-1 text-sm
                                               {{ $active
                                                   ? 'border-zinc-300 bg-white text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100'
                                                   : 'border-zinc-200 bg-transparent text-zinc-300 dark:border-zinc-700 dark:text-zinc-600 cursor-not-allowed' }}" />
                                    @if($active)
                                        <span class="text-zinc-400 dark:text-zinc-500">kr</span>
                                    @endif
                                </div>
                                @error("rows.{$type}.amount") <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @php
            $_yr       = $year;
            $grantMin  = (int) (\App\Models\SystemSetting::get("statistics.municipality_grant_age_min.{$_yr}") ?? \App\Models\SystemSetting::get('statistics.municipality_grant_age_min', 7));
            $grantMax  = (int) (\App\Models\SystemSetting::get("statistics.municipality_grant_age_max.{$_yr}") ?? \App\Models\SystemSetting::get('statistics.municipality_grant_age_max', 25));
            $muniName  = \App\Models\SystemSetting::get("statistics.municipality_name.{$_yr}") ?? \App\Models\SystemSetting::get('statistics.municipality_name', 'Kommunen');
        @endphp
        <div class="flex items-start gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-400">
            <x-bootstrap-icon name="info-circle" class="size-4 mt-0.5 shrink-0 text-zinc-400" />
            <span>
                Bidragsberättigade för <strong class="font-medium text-zinc-800 dark:text-zinc-200">{{ $muniName }}</strong>:
                ålder {{ $grantMin }}–{{ $grantMax }} år.
                <a href="{{ route('admin.settings') }}?tab=medlemmar" wire:navigate
                   class="ml-1 underline underline-offset-2 hover:text-zinc-900 dark:hover:text-zinc-200">Ändra i inställningar</a>
            </span>
        </div>

        <div class="flex justify-end">
            <button wire:click="save"
                    class="rounded-lg bg-brand px-6 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                Spara
            </button>
        </div>

    </div>
</div>
