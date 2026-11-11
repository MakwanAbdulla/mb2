<?php

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Avaktiveringsrapport');

new class extends Component {
    use WithPagination;

    public string $filterReason = '';
    public string $filterDate   = '';  // 'today', 'week', 'month', 'year', or ''
    public string $search       = '';

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('members.view'), 403);
    }

    public function updatedFilterReason(): void { $this->resetPage(); }
    public function updatedFilterDate(): void   { $this->resetPage(); }
    public function updatedSearch(): void       { $this->resetPage(); }

    #[Computed]
    public function entries()
    {
        return AuditLog::with('auditable')
            ->where('action', 'membership_deactivated')
            ->when($this->filterReason, fn ($q) =>
                $q->whereJsonContains('changes->reason', $this->filterReason)
            )
            ->when($this->search, fn ($q) =>
                $q->where('subject', 'like', '%' . $this->search . '%')
            )
            ->when($this->filterDate === 'today', fn ($q) => $q->whereDate('created_at', today()))
            ->when($this->filterDate === 'week',  fn ($q) => $q->where('created_at', '>=', now()->subWeek()))
            ->when($this->filterDate === 'month', fn ($q) => $q->where('created_at', '>=', now()->subMonth()))
            ->when($this->filterDate === 'year',  fn ($q) => $q->whereYear('created_at', now()->year))
            ->latest()
            ->paginate(50);
    }

    #[Computed]
    public function totals(): array
    {
        $base = AuditLog::where('action', 'membership_deactivated');
        return [
            'total'          => (clone $base)->count(),
            'this_year'      => (clone $base)->whereYear('created_at', now()->year)->count(),
            'grace_expired'  => (clone $base)->whereJsonContains('changes->reason', 'grace_period_expired')->whereYear('created_at', now()->year)->count(),
            'no_period'      => (clone $base)->whereJsonContains('changes->reason', 'no_membership_period')->whereYear('created_at', now()->year)->count(),
        ];
    }
};
?>

<x-slot:header>
    <x-page-header permission="members.view" title="Avaktiveringsrapport" />
</x-slot:header>

<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">

    {{-- Summary cards --}}
    <div class="mb-8 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs text-zinc-500 dark:text-zinc-400">Totalt avaktiverade</p>
            <p class="mt-1 text-2xl font-bold text-zinc-800 dark:text-zinc-100">{{ $this->totals['total'] }}</p>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs text-zinc-500 dark:text-zinc-400">I år ({{ now()->year }})</p>
            <p class="mt-1 text-2xl font-bold text-zinc-800 dark:text-zinc-100">{{ $this->totals['this_year'] }}</p>
        </div>
        <div class="rounded-lg border border-amber-100 bg-amber-50 p-4 dark:border-amber-900/40 dark:bg-amber-900/10">
            <p class="text-xs text-amber-700 dark:text-amber-400">Nådeperiod utgången i år</p>
            <p class="mt-1 text-2xl font-bold text-amber-800 dark:text-amber-300">{{ $this->totals['grace_expired'] }}</p>
        </div>
        <div class="rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-900/40 dark:bg-red-900/10">
            <p class="text-xs text-red-700 dark:text-red-400">Saknar period i år</p>
            <p class="mt-1 text-2xl font-bold text-red-800 dark:text-red-300">{{ $this->totals['no_period'] }}</p>
        </div>
    </div>

    {{-- Filters --}}
    <div class="mb-6 flex flex-wrap gap-3">
        <div class="flex-1 min-w-48">
            <input wire:model.live.debounce.300ms="search"
                   type="search"
                   placeholder="Sök namn eller medlemsnummer…"
                   class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
        </div>
        <select wire:model.live="filterReason"
                class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
            <option value="">Alla anledningar</option>
            @foreach(AuditLog::DEACTIVATION_REASON_LABELS as $code => $label)
                <option value="{{ $code }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="filterDate"
                class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
            <option value="">Alla datum</option>
            <option value="today">Idag</option>
            <option value="week">Senaste 7 dagarna</option>
            <option value="month">Senaste 30 dagarna</option>
            <option value="year">I år ({{ now()->year }})</option>
        </select>
    </div>

    {{-- Table --}}
    @if($this->entries->isEmpty())
        <div class="rounded-lg border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <x-bootstrap-icon name="person-x" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Inga avaktiveringar matchar sökningen.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead>
                    <tr class="bg-zinc-50 dark:bg-zinc-800/80">
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Datum</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Medlem</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Anledning</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">År</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                    @foreach($this->entries as $entry)
                        @php
                            $reason   = $entry->changes['reason'] ?? null;
                            $year     = $entry->changes['year'] ?? '–';
                            $hadLast  = $entry->changes['had_last_year'] ?? false;
                        @endphp
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-700/30">
                            <td class="whitespace-nowrap px-4 py-3 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $entry->created_at->format('d/m Y H:i') }}
                            </td>
                            <td class="px-4 py-3">
                                @if($entry->auditable)
                                    <a href="{{ route('admin.members.show', $entry->auditable) }}" wire:navigate
                                       class="font-medium text-complement-light hover:underline dark:text-complement-light">
                                        {{ $entry->auditable->full_name }}
                                    </a>
                                    <div class="text-xs text-zinc-400 dark:text-zinc-500">
                                        #{{ $entry->auditable->membership_number }}
                                        · {{ $entry->auditable->email }}
                                    </div>
                                @else
                                    <span class="text-zinc-400 dark:text-zinc-500 italic">{{ $entry->subject }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($reason === 'grace_period_expired')
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                                        <x-bootstrap-icon name="clock-history" class="size-3" />
                                        Nådeperiod utgången
                                    </span>
                                @elseif($reason === 'no_membership_period')
                                    <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900/30 dark:text-red-300">
                                        <x-bootstrap-icon name="calendar-x" class="size-3" />
                                        Saknar period
                                    </span>
                                @else
                                    <span class="text-xs text-zinc-400">{{ $reason ?? '–' }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $year }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $this->entries->links() }}
        </div>
    @endif
</div>
