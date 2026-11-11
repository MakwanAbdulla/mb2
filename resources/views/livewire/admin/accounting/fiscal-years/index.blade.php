<?php

use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\PeriodLock;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Räkenskapsår');

new class extends Component {

    public bool   $showCreateModal    = false;
    public string $formName           = '';
    public string $formStartsAt       = '';
    public string $formEndsAt         = '';

    // Period lock modal
    public ?int   $lockingFiscalYearId = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.admin'), 403);
    }

    #[Computed]
    public function fiscalYears()
    {
        return FiscalYear::orderByDesc('starts_at')->get();
    }

    #[Computed]
    public function lockingFiscalYear(): ?FiscalYear
    {
        return $this->lockingFiscalYearId
            ? FiscalYear::find($this->lockingFiscalYearId)
            : null;
    }

    #[Computed]
    public function lockingPeriods(): array
    {
        if (! $this->lockingFiscalYearId) {
            return [];
        }

        $fy     = $this->lockingFiscalYear;
        $locks  = PeriodLock::where('fiscal_year_id', $this->lockingFiscalYearId)
                    ->get()
                    ->keyBy(fn ($l) => $l->year . '-' . $l->month);

        $months = [];
        $cursor = $fy->starts_at->startOfMonth();
        $end    = $fy->ends_at->startOfMonth();

        while ($cursor->lte($end)) {
            $key      = $cursor->year . '-' . $cursor->month;
            $lock     = $locks->get($key);

            $months[] = [
                'year'      => $cursor->year,
                'month'     => $cursor->month,
                'label'     => $cursor->translatedFormat('F Y'),
                'lock_id'   => $lock?->id,
                'locked_at' => $lock?->locked_at,
                'locked_by' => $lock?->lockedBy?->name,
            ];

            $cursor->addMonth();
        }

        return $months;
    }

    // ── Create fiscal year ─────────────────────────────────────────────────────

    public function openCreate(): void
    {
        $lastYear = FiscalYear::latest('ends_at')->first();
        $start    = $lastYear ? $lastYear->ends_at->addDay() : now()->startOfYear();
        $this->formName     = "{$start->year}";
        $this->formStartsAt = $start->format('Y-m-d');
        $this->formEndsAt   = $start->copy()->endOfYear()->format('Y-m-d');
        $this->showCreateModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'formName'     => 'required|string|max:50',
            'formStartsAt' => 'required|date',
            'formEndsAt'   => 'required|date|after:formStartsAt',
        ]);

        $fy = FiscalYear::create([
            'name'      => $this->formName,
            'starts_at' => $this->formStartsAt,
            'ends_at'   => $this->formEndsAt,
        ]);

        AuditLog::record('fiscal_year_created', 'accounting', $fy->name . ' (' . $fy->starts_at->format('Y-m-d') . ' – ' . $fy->ends_at->format('Y-m-d') . ')', $fy);
        $this->showCreateModal = false;
        $this->formName = $this->formStartsAt = $this->formEndsAt = '';
        $this->resetValidation();
        unset($this->fiscalYears);
    }

    // ── Period locks ───────────────────────────────────────────────────────────

    public function openLocks(int $fyId): void
    {
        $this->lockingFiscalYearId = $fyId;
        unset($this->lockingFiscalYear, $this->lockingPeriods);
    }

    public function lockPeriod(int $year, int $month): void
    {
        abort_unless(Auth::user()->can('accounting.admin'), 403);

        PeriodLock::firstOrCreate(
            [
                'fiscal_year_id' => $this->lockingFiscalYearId,
                'year'           => $year,
                'month'          => $month,
            ],
            [
                'locked_at' => now(),
                'locked_by' => Auth::id(),
            ]
        );

        AuditLog::record('period_locked', 'accounting', sprintf('%04d-%02d', $year, $month), null);
        unset($this->lockingPeriods);
    }

    public function unlockPeriod(int $lockId): void
    {
        abort_unless(Auth::user()->can('accounting.admin'), 403);

        $lock = PeriodLock::where('id', $lockId)->where('fiscal_year_id', $this->lockingFiscalYearId)->first();
        PeriodLock::where('id', $lockId)
            ->where('fiscal_year_id', $this->lockingFiscalYearId)
            ->delete();

        AuditLog::record('period_unlocked', 'accounting', $lock ? sprintf('%04d-%02d', $lock->year, $lock->month) : "lock #{$lockId}", null);
        unset($this->lockingPeriods);
    }
};
?>

<div>

    <div class="mb-4 flex justify-end">
        @can('accounting.admin')
                <flux:button wire:click="openCreate" variant="primary" size="sm">
                    Nytt räkenskapsår
                </flux:button>@endcan
    </div>

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3 w-32">Namn</th>
                    <th class="px-4 py-3 w-36">Fr.o.m.</th>
                    <th class="px-4 py-3 w-36">T.o.m.</th>
                    <th class="px-4 py-3 w-24 text-center">Status</th>
                    <th class="px-4 py-3 w-32 text-center">Stängt</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->fiscalYears as $fy)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-4 py-3 font-medium">{{ $fy->name }}</td>
                        <td class="px-4 py-3">{{ $fy->starts_at->format('d/m Y') }}</td>
                        <td class="px-4 py-3">{{ $fy->ends_at->format('d/m Y') }}</td>
                        <td class="px-4 py-3 text-center">
                            @if ($fy->is_locked)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                    <x-bootstrap-icon name="lock-fill" class="size-3" /> Låst
                                </span>
                            @elseif ($fy->containsDate(today()))
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300">
                                    <x-bootstrap-icon name="circle-fill" class="size-2" /> Aktivt
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400">
                                    Öppet
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center text-xs text-zinc-400">
                            {{ $fy->closed_at?->format('d/m Y') ?? '–' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-4">
                                @can('accounting.admin')
                                    <button wire:click="openLocks({{ $fy->id }})"
                                            class="inline-flex items-center gap-1 text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300"
                                            title="Periodlåsning">
                                        <x-bootstrap-icon name="calendar-lock" class="size-3" /> Perioder
                                    </button>
                                    <a href="{{ route('admin.accounting.fiscal-years.opening-balances', $fy) }}"
                                       wire:navigate
                                       class="inline-flex items-center gap-1 text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300"
                                       title="Ingående balanser">
                                        <x-bootstrap-icon name="list-columns-reverse" class="size-3" /> IB
                                    </a>
                                    <a href="{{ route('admin.accounting.fiscal-years.sie4', $fy) }}"
                                       class="inline-flex items-center gap-1 text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300"
                                       title="Ladda ner SIE4-fil">
                                        <x-bootstrap-icon name="download" class="size-3" /> SIE4
                                    </a>
                                @endcan
                                <a href="{{ route('admin.accounting.journal-entries.index', ['fiscal_year' => $fy->id]) }}"
                                   wire:navigate
                                   class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                    Verifikationer →
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-zinc-400">
                            Inga räkenskapsår. Skapa ett för att börja bokföra.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ── Create modal ──────────────────────────────────────────────────── --}}
    <flux:modal wire:model="showCreateModal" class="max-w-md">
        <div class="p-6">
            <h2 class="text-lg font-semibold mb-4">Nytt räkenskapsår</h2>
            <div class="space-y-4">
                <div>
                    <flux:label for="fyName">Namn</flux:label>
                    <flux:input id="fyName" wire:model="formName" placeholder="t.ex. 2027" />
                    @error('formName') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <flux:label for="fyStartsAt">Fr.o.m.</flux:label>
                        <flux:input id="fyStartsAt" type="date" wire:model="formStartsAt" />
                        @error('formStartsAt') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:label for="fyEndsAt">T.o.m.</flux:label>
                        <flux:input id="fyEndsAt" type="date" wire:model="formEndsAt" />
                        @error('formEndsAt') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-3 mt-6">
                <flux:button wire:click="$set('showCreateModal', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="save" variant="primary">Skapa</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- ── Period lock modal ─────────────────────────────────────────────── --}}
    <flux:modal wire:model.boolean="lockingFiscalYearId" class="max-w-lg">
        @if ($this->lockingFiscalYear)
            <div class="p-6">
                <div class="flex items-center justify-between mb-1">
                    <h2 class="text-lg font-semibold">Periodlåsning</h2>
                    <span class="text-sm text-zinc-400">{{ $this->lockingFiscalYear->name }}</span>
                </div>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-5">
                    Låsta perioder tillåter inte nya verifikationer med datum inom månaden.
                </p>

                <div class="space-y-1">
                    @foreach ($this->lockingPeriods as $period)
                        <div class="flex items-center justify-between rounded-lg px-3 py-2
                                    {{ $period['lock_id'] ? 'bg-zinc-50 dark:bg-zinc-800/60' : 'hover:bg-zinc-50 dark:hover:bg-zinc-800/40' }}">
                            <div class="flex items-center gap-3">
                                @if ($period['lock_id'])
                                    <x-bootstrap-icon name="lock-fill" class="size-4 text-zinc-400 shrink-0" />
                                @else
                                    <x-bootstrap-icon name="unlock" class="size-4 text-zinc-300 dark:text-zinc-600 shrink-0" />
                                @endif
                                <div>
                                    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200 capitalize">
                                        {{ $period['label'] }}
                                    </p>
                                    @if ($period['lock_id'])
                                        <p class="text-xs text-zinc-400">
                                            Låst av {{ $period['locked_by'] ?? '–' }}
                                            · {{ $period['locked_at']?->format('d/m Y H:i') }}
                                        </p>
                                    @endif
                                </div>
                            </div>
                            <div>
                                @if ($period['lock_id'])
                                    <button wire:click="unlockPeriod({{ $period['lock_id'] }})"
                                            class="text-xs text-zinc-400 hover:text-amber-600 dark:hover:text-amber-400 transition-colors">
                                        Lås upp
                                    </button>
                                @else
                                    <button wire:click="lockPeriod({{ $period['year'] }}, {{ $period['month'] }})"
                                            class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 transition-colors">
                                        Lås period
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex justify-end mt-6">
                    <flux:button wire:click="$set('lockingFiscalYearId', null)" variant="ghost">Stäng</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
