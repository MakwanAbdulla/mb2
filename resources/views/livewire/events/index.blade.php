<?php

use App\Models\Events\Event;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Evenemang');

new class extends Component {

    #[Computed]
    public function events()
    {
        $user   = Auth::user();
        $member = $user?->member;

        $memberSectionIds = $member
            ? $member->sections()->pluck('sections.id')->toArray()
            : [];

        return Event::query()
            ->where('status', Event::STATUS_PUBLISHED)
            ->where(function ($q) use ($user, $memberSectionIds) {
                // Always show public events
                $q->where('visibility', Event::VISIBILITY_PUBLIC);

                if ($user) {
                    // Logged-in users also see members_only
                    $q->orWhere('visibility', Event::VISIBILITY_MEMBERS_ONLY);

                    // Section-only: only if member belongs to that section
                    if (! empty($memberSectionIds)) {
                        $q->orWhere(function ($q2) use ($memberSectionIds) {
                            $q2->where('visibility', Event::VISIBILITY_SECTION_ONLY)
                               ->whereIn('visibility_section_id', $memberSectionIds);
                        });
                    }
                }
                // invite_only never appears in the list
            })
            ->with('occurrences')
            ->orderBy(
                \App\Models\Events\EventOccurrence::select('starts_at')
                    ->whereColumn('event_id', 'events.id')
                    ->orderBy('starts_at')
                    ->limit(1)
            )
            ->get();
    }
}; ?>

<x-slot:header>
    <x-page-header title="Evenemang" subtitle="Kommande evenemang anordnade av och för föreningens medlemmar." />
</x-slot:header>

<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6">

    @if($this->events->isEmpty())
        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <x-bootstrap-icon name="calendar3" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
            <p class="text-zinc-500 dark:text-zinc-400">Inga kommande evenemang just nu.</p>
        </div>
    @else
        <div class="space-y-4">
            @foreach($this->events as $event)
                @php $firstOcc = $event->occurrences->first(); @endphp
                <a href="{{ route('events.show', $event->slug) }}" wire:navigate
                   class="group flex gap-5 overflow-hidden rounded-xl border border-zinc-200 bg-white transition-shadow hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600">

                    {{-- Date block --}}
                    @if($firstOcc)
                        <div class="flex w-20 shrink-0 flex-col items-center justify-center bg-brand py-4 text-brand-dark">
                            <span class="text-xs font-semibold uppercase tracking-wider">
                                {{ $firstOcc->starts_at->isoFormat('MMM') }}
                            </span>
                            <span class="text-3xl font-bold leading-none">
                                {{ $firstOcc->starts_at->isoFormat('D') }}
                            </span>
                            @if($event->occurrences->count() > 1)
                                <span class="mt-1 text-xs opacity-70">
                                    +{{ $event->occurrences->count() - 1 }} till
                                </span>
                            @endif
                        </div>
                    @else
                        <div class="flex w-20 shrink-0 items-center justify-center bg-zinc-100 dark:bg-zinc-800">
                            <x-bootstrap-icon name="calendar3" class="size-6 text-zinc-400" />
                        </div>
                    @endif

                    {{-- Content --}}
                    <div class="flex flex-1 flex-col justify-center gap-1 py-4 pr-5">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-semibold text-zinc-900 group-hover:text-complement dark:text-white dark:group-hover:text-complement-light">
                                {{ $event->title }}
                            </h2>
                            @if($event->visibility === Event::VISIBILITY_MEMBERS_ONLY)
                                <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                                    <x-bootstrap-icon name="lock" class="size-3" /> Medlemmar
                                </span>
                            @endif
                        </div>

                        @if($firstOcc)
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $firstOcc->starts_at->isoFormat('dddd D MMMM, HH:mm') }}
                                @if($firstOcc->location_override)
                                    · {{ $firstOcc->location_override }}
                                @elseif($event->location_name)
                                    · {{ $event->location_name }}
                                @endif
                            </p>
                        @endif

                        @if($event->description)
                            <p class="line-clamp-2 text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $event->description }}
                            </p>
                        @endif

                        {{-- Price summary --}}
                        @if($event->priceTiers->isNotEmpty())
                            @php
                                $minPrice = $event->priceTiers->min('price_ore');
                                $maxPrice = $event->priceTiers->max('price_ore');
                            @endphp
                            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                @if($minPrice === 0 && $maxPrice === 0)
                                    Gratis
                                @elseif($minPrice === 0)
                                    Gratis – {{ number_format($maxPrice / 100, 0, ',', ' ') }} kr
                                @elseif($minPrice === $maxPrice)
                                    {{ number_format($minPrice / 100, 0, ',', ' ') }} kr
                                @else
                                    {{ number_format($minPrice / 100, 0, ',', ' ') }}–{{ number_format($maxPrice / 100, 0, ',', ' ') }} kr
                                @endif
                            </p>
                        @endif
                    </div>

                    <div class="flex items-center pr-4">
                        <x-bootstrap-icon name="chevron-right" class="size-5 text-zinc-300 group-hover:text-complement dark:group-hover:text-complement-light" />
                    </div>
                </a>
            @endforeach
        </div>
    @endif

</div>
