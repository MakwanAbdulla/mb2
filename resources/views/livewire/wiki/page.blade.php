<?php

use App\Models\AuditLog;
use App\Models\Wiki\WikiPage;
use App\Models\Wiki\WikiSpace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title(fn () => $this->page->title.' · '.$this->space->name);

new class extends Component {
    public WikiSpace $space;
    public WikiPage $page;

    public function mount(WikiSpace $space, WikiPage $page): void
    {
        // 404, not 403: a closed space should not reveal that its pages exist.
        abort_unless(Gate::allows('view', $page), 404);

        $this->space = $space;
        $this->page = $page->load('updater:id,name');
    }

    #[Computed]
    public function byParent()
    {
        return $this->space->pagesByParent();
    }

    /** The breadcrumb, built from the pages the tree already loaded: no query per level. */
    #[Computed]
    public function ancestors(): Collection
    {
        $byId = $this->byParent->flatten(1)->keyBy('id');
        $chain = collect();

        for ($id = $this->page->parent_id; $id && $byId->has($id) && ! $chain->has($id); $id = $byId[$id]->parent_id) {
            $chain->put($id, $byId[$id]);
        }

        return $chain->reverse()->values();
    }

    public function delete(): void
    {
        Gate::authorize('delete', $this->page);

        $title = $this->page->title;
        $this->page->delete();
        AuditLog::record('wiki_page_deleted', 'wiki', "{$this->space->name}: {$title}", $this->page);

        $this->redirectRoute('wiki.space', $this->space, navigate: true);
    }
}; ?>

<x-slot:header>
    <x-page-header title="{{ $page->title }}">
        <nav aria-label="Brödsmulor" class="flex flex-wrap items-center gap-1.5 text-sm text-zinc-500 dark:text-zinc-400">
            <a href="{{ route('wiki.index') }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-200">Wiki</a>
            <span aria-hidden="true">/</span>
            <a href="{{ route('wiki.space', $space) }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-200">{{ $space->name }}</a>
            @foreach($this->ancestors as $ancestor)
                <span aria-hidden="true">/</span>
                <a href="{{ route('wiki.page', [$space, $ancestor]) }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-200">{{ $ancestor->title }}</a>
            @endforeach
        </nav>
    </x-page-header>
</x-slot:header>

<div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
    <div class="grid grid-cols-1 gap-8 lg:grid-cols-4">

        {{-- Sidträd --}}
        <aside class="order-2 lg:order-1">
            <details class="group rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900 lg:sticky lg:top-4" open>
                <summary class="cursor-pointer list-none px-1.5 pb-2 text-xs font-semibold uppercase tracking-widest text-zinc-400 dark:text-zinc-500">
                    {{ $space->name }}
                </summary>
                @include('livewire.wiki._tree', ['byParent' => $this->byParent, 'parentId' => null, 'currentId' => $page->id])
            </details>
        </aside>

        {{-- Sidan --}}
        <article class="order-1 space-y-4 lg:order-2 lg:col-span-3">
            @if($space->isArchived())
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                    Utrymmet är arkiverat. Sidan går att läsa men inte ändra.
                </div>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-zinc-400 dark:text-zinc-500">
                    Senast ändrad {{ $page->updated_at->format('d/m Y H:i') }}@if($page->updater) av {{ $page->updater->name }}@endif
                    · <a href="{{ route('wiki.pages.history', [$space, $page]) }}" wire:navigate class="underline hover:text-zinc-600 dark:hover:text-zinc-300">Historik</a>
                </p>
                @can('update', $page)
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('wiki.pages.edit', [$space, $page]) }}" wire:navigate
                           class="inline-flex items-center gap-1.5 rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                            <x-bootstrap-icon name="pencil" class="size-4" />
                            Redigera
                        </a>
                        <a href="{{ route('wiki.pages.create', ['space' => $space, 'parent' => $page->id]) }}" wire:navigate
                           class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800">
                            <x-bootstrap-icon name="plus-lg" class="size-4" />
                            Undersida
                        </a>
                        <button wire:click="delete"
                                wire:confirm="Radera sidan {{ $page->title }}? Eventuella undersidor flyttas upp en nivå."
                                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">
                            <x-bootstrap-icon name="trash" class="size-4" />
                            Radera
                        </button>
                    </div>
                @endcan
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                @if($page->content)
                    {{-- Sanitized when saved (WikiHtml), so it is safe to print as HTML. --}}
                    <div class="trix-content prose prose-zinc max-w-none dark:prose-invert">{!! $page->content !!}</div>
                @else
                    <p class="text-sm text-zinc-400">Sidan är tom.</p>
                @endif
            </div>
        </article>
    </div>
</div>
