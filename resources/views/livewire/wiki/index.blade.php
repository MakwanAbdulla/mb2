<?php

use App\Models\Wiki\WikiPage;
use App\Models\Wiki\WikiSpace;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Wiki');

// Public: guests see public spaces. What anyone sees is decided by WikiAccess.
new class extends Component {

    #[Computed]
    public function spaces()
    {
        return WikiSpace::readableBy(Auth::user())
            ->active()
            ->with('group:id,name')
            ->withCount('pages')
            ->orderByRaw('group_id is not null')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function recentPages()
    {
        return WikiPage::readableBy(Auth::user())
            ->whereHas('space', fn ($q) => $q->active())
            ->with(['space:id,name,slug', 'updater:id,name'])
            ->latest('updated_at')
            ->limit(8)
            ->get();
    }
}; ?>

<x-slot:header>
    <x-page-header title="Wiki" subtitle="Föreningens rutiner, instruktioner och överlämningar">
        @can('wiki.manage')
            <a href="{{ route('admin.wiki.index') }}" wire:navigate
               class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800">
                <x-bootstrap-icon name="gear" class="size-4" />
                Hantera utrymmen
            </a>
        @endcan
    </x-page-header>
</x-slot:header>

<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">

        {{-- Utrymmen --}}
        <section class="space-y-3 lg:col-span-2">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Utrymmen</h2>

            @forelse($this->spaces as $space)
                <a href="{{ route('wiki.space', $space) }}" wire:navigate
                   class="block rounded-xl border border-zinc-200 bg-white p-5 transition-colors hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="font-semibold text-zinc-900 dark:text-white">{{ $space->name }}</h3>
                            @if($space->description)
                                <p class="mt-1 line-clamp-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $space->description }}</p>
                            @endif
                        </div>
                        <span class="shrink-0 rounded-full bg-zinc-100 px-2.5 py-1 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                            {{ $space->read_access->label() }}
                        </span>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-4 text-xs text-zinc-400 dark:text-zinc-500">
                        <span><x-bootstrap-icon name="file-text" class="mr-1 inline size-3.5" />{{ $space->pages_count }} {{ $space->pages_count === 1 ? 'sida' : 'sidor' }}</span>
                        <span><x-bootstrap-icon name="{{ $space->group ? 'diagram-3' : 'house' }}" class="mr-1 inline size-3.5" />{{ $space->group?->name ?? 'Hela föreningen' }}</span>
                    </div>
                </a>
            @empty
                <div class="rounded-xl border border-zinc-200 bg-white px-5 py-12 text-center text-sm text-zinc-400 dark:border-zinc-700 dark:bg-zinc-900">
                    @guest
                        Det finns inga publika utrymmen. Logga in för att se fler.
                    @else
                        Det finns inga utrymmen som du kan läsa ännu.
                    @endguest
                </div>
            @endforelse
        </section>

        {{-- Senast ändrat --}}
        <section class="space-y-3">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Senast ändrat</h2>

            <div class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900">
                @forelse($this->recentPages as $page)
                    <a href="{{ route('wiki.page', [$page->space, $page]) }}" wire:navigate
                       class="block px-4 py-3 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $page->title }}</p>
                        <p class="mt-0.5 truncate text-xs text-zinc-400 dark:text-zinc-500">
                            {{ $page->space->name }} · {{ $page->updated_at->format('d/m Y') }}@if($page->updater) · {{ $page->updater->name }}@endif
                        </p>
                    </a>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-zinc-400">Inga sidor ännu.</p>
                @endforelse
            </div>
        </section>
    </div>
</div>
