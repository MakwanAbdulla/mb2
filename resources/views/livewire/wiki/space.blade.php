<?php

use App\Models\Wiki\WikiPage;
use App\Models\Wiki\WikiSpace;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title(fn () => $this->space->name.' · Wiki');

new class extends Component {
    public WikiSpace $space;

    public function mount(WikiSpace $space): void
    {
        // 404, not 403: a closed space should not reveal that it exists.
        abort_unless(Gate::allows('view', $space), 404);

        $this->space = $space->load('group:id,name');
    }

    #[Computed]
    public function byParent()
    {
        return $this->space->pagesByParent();
    }

    #[Computed]
    public function canWrite(): bool
    {
        return Gate::allows('write', $this->space);
    }

    public function movePageUp(int $id): void
    {
        $this->movePage($id, -1);
    }

    public function movePageDown(int $id): void
    {
        $this->movePage($id, 1);
    }

    private function movePage(int $id, int $direction): void
    {
        $page = WikiPage::where('wiki_space_id', $this->space->id)->findOrFail($id);
        Gate::authorize('update', $page);

        $page->move($direction);
        unset($this->byParent);
    }
}; ?>

<x-slot:header>
    <x-page-header title="{{ $space->name }}" subtitle="{{ $space->description }}">
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <a href="{{ route('wiki.index') }}" wire:navigate class="text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">← Wiki</a>
            <span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $space->read_access->label() }}</span>
            @if($space->group)
                <a href="{{ route('groups.show', $space->group) }}" wire:navigate class="text-xs text-complement hover:underline dark:text-complement-light">
                    <x-bootstrap-icon name="diagram-3" class="mr-1 inline size-3.5" />{{ $space->group->name }}
                </a>
            @else
                <span class="text-xs text-zinc-500 dark:text-zinc-400">Hela föreningen</span>
            @endif
            @can('update', $space)
                <a href="{{ route('admin.wiki.index') }}" wire:navigate class="text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                    <x-bootstrap-icon name="gear" class="mr-1 inline size-3.5" />Hantera
                </a>
            @endcan
        </div>
    </x-page-header>
</x-slot:header>

<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 space-y-4">
    @if($space->isArchived())
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
            Utrymmet arkiverades {{ $space->archived_at->format('d/m Y') }}. Sidorna går att läsa men inte ändra.
        </div>
    @endif

    @if($this->canWrite)
        <div class="flex justify-end">
            <a href="{{ route('wiki.pages.create', $space) }}" wire:navigate
               class="inline-flex items-center gap-1.5 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                <x-bootstrap-icon name="plus-lg" class="size-4" />
                Ny sida
            </a>
        </div>
    @endif

    <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        @if($this->byParent->isEmpty())
            <p class="py-6 text-center text-sm text-zinc-400">Inga sidor ännu.</p>
        @else
            @include('livewire.wiki._tree', ['byParent' => $this->byParent, 'parentId' => null, 'reorder' => $this->canWrite])
        @endif
    </div>
</div>
