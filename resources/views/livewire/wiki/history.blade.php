<?php

use App\Models\Wiki\WikiPage;
use App\Models\Wiki\WikiSpace;
use Illuminate\Support\Facades\Gate;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title(fn () => 'Historik: '.$this->page->title.' · '.$this->space->name);

new class extends Component {
    use WithPagination;

    public WikiSpace $space;
    public WikiPage $page;

    public function mount(WikiSpace $space, WikiPage $page): void
    {
        // Same rule as the page itself: 404 when it may not be read.
        abort_unless(Gate::allows('view', $page), 404);

        $this->space = $space;
        $this->page = $page;
    }

    public function with(): array
    {
        return [
            // The list shows no content: leave the longText out.
            'revisions' => $this->page->revisions()
                ->select(['id', 'wiki_page_id', 'title', 'summary', 'user_id', 'created_at'])
                ->with('user:id,name')
                ->paginate(50),
            'currentId' => $this->page->revisions()->value('id'),
        ];
    }
}; ?>

<x-slot:header>
    <x-page-header title="Historik: {{ $page->title }}">
        <nav aria-label="Brödsmulor" class="flex flex-wrap items-center gap-1.5 text-sm text-zinc-500 dark:text-zinc-400">
            <a href="{{ route('wiki.index') }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-200">Wiki</a>
            <span aria-hidden="true">/</span>
            <a href="{{ route('wiki.space', $space) }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-200">{{ $space->name }}</a>
            <span aria-hidden="true">/</span>
            <a href="{{ route('wiki.page', [$space, $page]) }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-200">{{ $page->title }}</a>
        </nav>
    </x-page-header>
</x-slot:header>

<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
    @if($revisions->isEmpty())
        <p class="text-sm text-zinc-400 dark:text-zinc-500">Sidan har ingen historik ännu.</p>
    @else
        <ol class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900">
            @foreach($revisions as $revision)
                <li>
                    <a href="{{ route('wiki.pages.revision', [$space, $page, $revision]) }}" wire:navigate
                       class="flex flex-col gap-1 px-4 py-3 hover:bg-zinc-50 sm:flex-row sm:items-center sm:gap-4 dark:hover:bg-zinc-800">
                        <span class="shrink-0 text-sm tabular-nums text-zinc-700 dark:text-zinc-300">{{ $revision->created_at->format('d/m Y H:i') }}</span>
                        <span class="shrink-0 text-sm text-zinc-500 dark:text-zinc-400">{{ $revision->user?->name ?? 'Okänd' }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm {{ $revision->summary ? 'text-zinc-900 dark:text-zinc-100' : 'italic text-zinc-400 dark:text-zinc-500' }}">
                            {{ $revision->summary ?? 'Ingen beskrivning' }}
                        </span>
                        @if($revision->id === $currentId)
                            <span class="shrink-0 self-start rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 sm:self-auto dark:bg-green-900/30 dark:text-green-400">Nuvarande</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ol>

        <div class="mt-4">{{ $revisions->links() }}</div>
    @endif
</div>
