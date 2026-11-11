<?php

use App\Models\Events\Event;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Evenemang');

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $status = '';

    public function mount(): void
    {
        abort_unless(
            Auth::check() && Auth::user()->hasAnyPermission([
                'events.view', 'events.manage-own', 'events.manage-all',
            ]),
            403
        );
    }

    #[Computed]
    public function events()
    {
        $user   = Auth::user();
        $member = $user->member;

        return Event::query()
            ->when(
                ! $user->hasAnyPermission(['events.view', 'events.manage-all'])
                    && $member,
                fn ($q) => $q->whereHas('adminOrganizers', fn ($q2) => $q2->where('members.id', $member->id))
            )
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->withCount('registrations')
            ->with('occurrences')
            ->orderByDesc('created_at')
            ->paginate(25);
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatus(): void { $this->resetPage(); }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            Event::STATUS_DRAFT     => 'Utkast',
            Event::STATUS_PUBLISHED => 'Publicerat',
            Event::STATUS_CANCELLED => 'Inställt',
            Event::STATUS_COMPLETED => 'Avslutat',
            default                 => $status,
        };
    }

    public function statusClasses(string $status): string
    {
        return match ($status) {
            Event::STATUS_DRAFT     => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
            Event::STATUS_PUBLISHED => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
            Event::STATUS_CANCELLED => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
            Event::STATUS_COMPLETED => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
            default                 => 'bg-zinc-100 text-zinc-600',
        };
    }

    public function visibilityLabel(string $visibility): string
    {
        return match ($visibility) {
            Event::VISIBILITY_PUBLIC       => 'Öppet',
            Event::VISIBILITY_MEMBERS_ONLY => 'Enbart medlemmar',
            Event::VISIBILITY_SECTION_ONLY => 'Grupp',
            Event::VISIBILITY_INVITE_ONLY  => 'Inbjudan',
            default                        => $visibility,
        };
    }
}; ?>

<div>
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">

        {{-- Search & filters --}}
        <div class="flex flex-col gap-3 sm:flex-row">
            @can('events.create')
            <a href="{{ route('admin.events.create') }}" wire:navigate
               class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron sm:order-last">
                + Skapa evenemang <x-permission-hint permission="events.create" />
            </a>
            @endcan
            <div class="relative flex-1">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                    <x-bootstrap-icon name="search" class="size-4 text-zinc-400" />
                </div>
                <input wire:model.live.debounce.300ms="search"
                       type="search"
                       placeholder="Sök på titel…"
                       class="w-full rounded-lg border border-zinc-300 bg-white py-2 pl-9 pr-3 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
            </div>
            <select wire:model.live="status"
                    class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
                <option value="">Alla statusar</option>
                <option value="{{ Event::STATUS_DRAFT }}">Utkast</option>
                <option value="{{ Event::STATUS_PUBLISHED }}">Publicerat</option>
                <option value="{{ Event::STATUS_CANCELLED }}">Inställt</option>
                <option value="{{ Event::STATUS_COMPLETED }}">Avslutat</option>
            </select>
        </div>

        {{-- Table --}}
        @if($this->events->isEmpty())
            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-8 text-center text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
                @if($search || $status)
                    Inga evenemang matchar sökningen.
                @else
                    Inga evenemang skapade ännu.
                @endif
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Titel</th>
                            <th class="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 md:table-cell">Tillfällen</th>
                            <th class="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 sm:table-cell">Synlighet</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                            <th class="hidden px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 md:table-cell">Anmälda</th>
                            <th class="relative px-4 py-3"><span class="sr-only">Åtgärder</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                        @foreach($this->events as $event)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('admin.events.show', $event) }}" wire:navigate
                                       class="font-medium text-zinc-900 hover:text-complement dark:text-white dark:hover:text-complement-light">
                                        {{ $event->title }}
                                    </a>
                                    @if($event->organizer_external_name)
                                        <div class="text-xs text-zinc-400 dark:text-zinc-500">Extern: {{ $event->organizer_external_name }}</div>
                                    @endif
                                </td>
                                <td class="hidden px-4 py-3 text-zinc-600 dark:text-zinc-400 md:table-cell">
                                    {{ $event->occurrences->count() }} st
                                </td>
                                <td class="hidden px-4 py-3 text-zinc-600 dark:text-zinc-400 sm:table-cell">
                                    {{ $this->visibilityLabel($event->visibility) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $this->statusClasses($event->status) }}">
                                        {{ $this->statusLabel($event->status) }}
                                    </span>
                                </td>
                                <td class="hidden px-4 py-3 text-right text-zinc-600 dark:text-zinc-400 md:table-cell">
                                    {{ $event->registrations_count }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-3">
                                        <a href="{{ route('admin.events.show', $event) }}" wire:navigate
                                           class="text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">Visa</a>
                                        @can('events.manage-all')
                                            <a href="{{ route('admin.events.edit', $event) }}" wire:navigate
                                               class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">Redigera</a>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            <div>{{ $this->events->links() }}</div>
        @endif

    </div>
</div>
