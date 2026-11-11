<?php

use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalSeries;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Verifikationer');

new class extends Component {
    use WithPagination;

    #[Url]
    public ?int $fiscal_year = null;

    #[Url]
    public string $filterSeries = '';

    #[Url]
    public string $search = '';

    #[Url]
    public bool $filterMissingReceipt = false;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.view'), 403);

        // Default to the current/latest fiscal year
        if (! $this->fiscal_year) {
            $fy = FiscalYear::latest('starts_at')->first();
            $this->fiscal_year = $fy?->id;
        }
    }

    #[Computed]
    public function fiscalYears()
    {
        return FiscalYear::orderByDesc('starts_at')->get();
    }

    #[Computed]
    public function currentFiscalYear(): ?FiscalYear
    {
        return $this->fiscal_year ? FiscalYear::find($this->fiscal_year) : null;
    }

    #[Computed]
    public function series()
    {
        return JournalSeries::orderBy('sort_order')->get();
    }

    #[Computed]
    public function entries()
    {
        return JournalEntry::with(['createdBy', 'editedBy'])
            ->withCount('documents')
            ->when($this->fiscal_year, fn ($q) => $q->where('fiscal_year_id', $this->fiscal_year))
            ->when($this->filterSeries, fn ($q) => $q->where('series', $this->filterSeries))
            ->when($this->search, fn ($q) =>
                $q->where('description', 'like', "%{$this->search}%")
                  ->orWhere('number', 'like', "%{$this->search}%")
            )
            ->when($this->filterMissingReceipt, fn ($q) =>
                $q->where('requires_receipt', true)->whereDoesntHave('documents')
            )
            ->orderByDesc('date')
            ->orderByDesc('series')
            ->orderByDesc('number')
            ->paginate(50);
    }

    public function updatedFilterMissingReceipt(): void
    {
        $this->resetPage();
    }

    public function updatedFiscalYear(): void
    {
        $this->resetPage();
    }

    public function updatedFilterSeries(): void
    {
        $this->resetPage();
    }
};
?>

<div>

    {{-- Filters + actions --}}
    <div class="flex items-center gap-3 mb-6">
        <flux:select wire:model.live="fiscal_year" class="w-32 shrink-0">
            @foreach ($this->fiscalYears as $fy)
                <option value="{{ $fy->id }}">{{ $fy->name }}</option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="filterSeries" class="w-44 shrink-0">
            <option value="">Alla serier</option>
            @foreach ($this->series as $s)
                <option value="{{ $s->code }}">{{ $s->code }} – {{ $s->name }}</option>
            @endforeach
        </flux:select>

        <div class="flex-1 min-w-0">
            <flux:input
                wire:model.live.debounce.300ms="search"
                placeholder="Sök beskrivning…"
            />
        </div>

        <label class="flex items-center gap-2 text-sm cursor-pointer select-none shrink-0">
            <input type="checkbox" wire:model.live="filterMissingReceipt"
                   class="size-4 rounded text-amber-500 focus:ring-amber-400" />
            <span class="text-zinc-700 dark:text-zinc-300 flex items-center gap-1">
                <x-bootstrap-icon name="receipt" class="size-3.5 text-amber-500" />
                Kvitto saknas
            </span>
        </label>

        @can('accounting.manage')
        <a href="{{ route('admin.accounting.journal-entries.create') }}" wire:navigate class="shrink-0">
            <flux:button variant="primary" size="sm">
                <x-bootstrap-icon name="plus-lg" class="size-3.5" />
                Ny verifikation
            </flux:button>
        </a>
        @endcan
    </div>

    {{-- Entries table --}}
    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3 w-28">Verif.nr</th>
                    <th class="px-4 py-3 w-28">Datum</th>
                    <th class="px-4 py-3">Beskrivning</th>
                    <th class="px-4 py-3 w-16 text-center" title="Kvittostatus">
                        <x-bootstrap-icon name="receipt" class="size-3.5 mx-auto" />
                    </th>
                    <th class="px-4 py-3 w-20 text-center">Låst</th>
                    <th class="px-4 py-3 w-24 text-center">Ändrad</th>
                    <th class="px-4 py-3 w-16"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->entries as $entry)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-4 py-3 font-mono font-medium">
                            {{ $entry->series }}{{ $entry->number }}
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">
                            {{ $entry->date->format('d/m') }}
                        </td>
                        <td class="px-4 py-3">
                            {{ $entry->description }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($entry->requires_receipt)
                                @if ($entry->documents_count === 0)
                                    <span title="Kvitto saknas">
                                        <x-bootstrap-icon name="exclamation-triangle-fill" class="size-4 text-amber-500 mx-auto" />
                                    </span>
                                @else
                                    <span title="Kvitto uppladdat">
                                        <x-bootstrap-icon name="check-circle-fill" class="size-4 text-green-500 mx-auto" />
                                    </span>
                                @endif
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($entry->is_locked)
                                <x-bootstrap-icon name="lock-fill" class="size-4 text-zinc-400 mx-auto" title="Låst" />
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($entry->edited_at)
                                <span class="inline-flex items-center gap-1 text-xs text-amber-600 dark:text-amber-400" title="{{ $entry->edit_note }}">
                                    <x-bootstrap-icon name="pencil-fill" class="size-3" />
                                    {{ $entry->edited_at->format('d/m') }}
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.accounting.journal-entries.show', $entry) }}"
                               wire:navigate
                               class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                Visa
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-zinc-400">
                            @if ($filterMissingReceipt)
                                Inga verifikationer med saknat kvitto.
                            @else
                                Inga verifikationer för valt räkenskapsår.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $this->entries->links() }}
    </div>
</div>
