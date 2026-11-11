<?php

use App\Models\Wiki\WikiPage;
use App\Models\Wiki\WikiPageRevision;
use App\Models\Wiki\WikiSpace;
use Illuminate\Support\Facades\Gate;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title(fn () => $this->revision->title.' ('.$this->revision->created_at->format('d/m Y H:i').') · '.$this->space->name);

new class extends Component {
    public WikiSpace $space;
    public WikiPage $page;
    public WikiPageRevision $revision;

    public function mount(WikiSpace $space, WikiPage $page, WikiPageRevision $revision): void
    {
        abort_unless(Gate::allows('view', $page), 404);

        $this->space = $space;
        $this->page = $page;
        $this->revision = $revision->load('user:id,name');
    }

    public function with(): array
    {
        return ['isCurrent' => $this->page->revisions()->value('id') === $this->revision->id];
    }
}; ?>

<x-slot:header>
    <x-page-header title="{{ $revision->title }}">
        <nav aria-label="Brödsmulor" class="flex flex-wrap items-center gap-1.5 text-sm text-zinc-500 dark:text-zinc-400">
            <a href="{{ route('wiki.index') }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-200">Wiki</a>
            <span aria-hidden="true">/</span>
            <a href="{{ route('wiki.space', $space) }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-200">{{ $space->name }}</a>
            <span aria-hidden="true">/</span>
            <a href="{{ route('wiki.page', [$space, $page]) }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-200">{{ $page->title }}</a>
            <span aria-hidden="true">/</span>
            <a href="{{ route('wiki.pages.history', [$space, $page]) }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-200">Historik</a>
        </nav>
    </x-page-header>
</x-slot:header>

<div class="mx-auto max-w-4xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
    <div class="rounded-lg border px-4 py-3 text-sm {{ $isCurrent
        ? 'border-green-200 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300'
        : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300' }}">
        <p>
            {{ $isCurrent ? 'Det här är den nuvarande versionen' : 'En äldre version' }},
            sparad {{ $revision->created_at->format('d/m Y H:i') }} av {{ $revision->user?->name ?? 'okänd' }}.
            @unless($isCurrent)
                <a href="{{ route('wiki.page', [$space, $page]) }}" wire:navigate class="font-medium underline">Visa nuvarande version</a>
            @endunless
        </p>
        @if($revision->summary)
            <p class="mt-1">”{{ $revision->summary }}”</p>
        @endif
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        @if($revision->content)
            {{-- Copied from the page after it was sanitized (WikiHtml). --}}
            <div class="trix-content prose prose-zinc max-w-none dark:prose-invert">{!! $revision->content !!}</div>
        @else
            <p class="text-sm text-zinc-400">Sidan var tom i den här versionen.</p>
        @endif
    </div>
</div>
