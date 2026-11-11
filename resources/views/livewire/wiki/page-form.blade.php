<?php

use App\Models\AuditLog;
use App\Models\Wiki\WikiPage;
use App\Models\Wiki\WikiSpace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title(fn () => ($this->page ? 'Redigera '.$this->page->title : 'Ny sida').' · '.$this->space->name);

// Create (/wiki/{space}/ny-sida?parent=) and edit (/wiki/{space}/{page}/redigera).
new class extends Component {
    public WikiSpace $space;
    public ?WikiPage $page = null;

    public string $title = '';
    public ?string $content = '';
    public ?int $parent_id = null;
    public string $summary = '';

    public function mount(WikiSpace $space, ?WikiPage $page = null): void
    {
        $this->space = $space;

        if ($page?->exists) {
            Gate::authorize('update', $page);
            $this->page = $page;
            $this->title = $page->title;
            $this->content = $page->content ?? '';
            $this->parent_id = $page->parent_id;
        } else {
            Gate::authorize('write', $space);
            // ?parent= from the "Undersida" button on a page
            $this->parent_id = WikiPage::where('wiki_space_id', $space->id)->find(request()->integer('parent') ?: null)?->id;
        }
    }

    /** Pages that may be the parent, indented by depth: never this page or one below it. */
    #[Computed]
    public function parentOptions(): array
    {
        $exclude = $this->page ? [$this->page->id, ...$this->page->descendantIds()] : [];
        $byParent = $this->space->pagesByParent();
        $options = [];

        $walk = function (?int $parentId, int $depth) use (&$walk, &$options, $byParent, $exclude) {
            foreach ($byParent->get($parentId ?? '', collect()) as $node) {
                if (in_array($node->id, $exclude, true)) {
                    continue;
                }
                $options[$node->id] = str_repeat('— ', $depth).$node->title;
                $walk($node->id, $depth + 1);
            }
        };
        $walk(null, 0);

        return $options;
    }

    public function save(): void
    {
        $this->page ? Gate::authorize('update', $this->page) : Gate::authorize('write', $this->space);

        $data = $this->validate(
            WikiPage::rules($this->space, $this->page),
            attributes: ['title' => 'titel', 'content' => 'innehåll', 'parent_id' => 'föräldersida', 'summary' => 'beskrivning av ändringen'],
        );

        if ($this->page) {
            $page = $this->page->updateFrom($data, Auth::user());
            AuditLog::record('wiki_page_updated', 'wiki', "{$this->space->name}: {$page->title}", $page);
        } else {
            $page = WikiPage::createIn($this->space, $data, Auth::user());
            AuditLog::record('wiki_page_created', 'wiki', "{$this->space->name}: {$page->title}", $page);
        }

        $this->redirectRoute('wiki.page', [$this->space, $page], navigate: true);
    }
}; ?>

<x-slot:header>
    <x-page-header title="{{ $page ? 'Redigera sida' : 'Ny sida' }}" subtitle="{{ $space->name }}" />
</x-slot:header>

<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
    <form wire:submit="save" class="space-y-6 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">

        <div>
            <label for="wiki-title" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Titel <span class="text-red-500">*</span></label>
            <input id="wiki-title" wire:model="title" type="text" placeholder="Stänga lokalen"
                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
            @if($page)
                <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Adressen /wiki/{{ $space->slug }}/{{ $page->slug }} ändras inte när titeln ändras, så länkar fortsätter att fungera.</p>
            @endif
            @error('title') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="wiki-parent" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Ligger under</label>
            <select id="wiki-parent" wire:model="parent_id"
                    class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron dark:border-zinc-600 dark:bg-zinc-800 dark:text-white">
                <option value="">— Överst i {{ $space->name }} —</option>
                @foreach($this->parentOptions as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </select>
            @error('parent_id') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Innehåll</label>
            <div class="mt-1 overflow-hidden rounded-lg border border-zinc-300 dark:border-zinc-600" wire:ignore
                 x-data
                 x-init="window.addEventListener('imageSelected', e => {
                     const url = e.detail[0] ?? e.detail?.path ?? e.detail;
                     if (url) $refs.editor.editor.insertHTML(`<img src=&quot;${url}&quot; alt=&quot;&quot;>`);
                 })">
                <input id="wiki-content" type="hidden" value="{{ $content }}">
                <trix-editor x-ref="editor" input="wiki-content" class="trix-content min-h-72 p-4 text-zinc-900 dark:text-zinc-100"
                             x-on:trix-change="$wire.set('content', $event.target.value, false)"></trix-editor>
            </div>
            <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Skriv hur något görs. Filer som kalkylark och avtal hör hemma i Drive, länka dit.</p>
            @error('content') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

            @can('cms.edit')
                {{-- The image browser manages the site's image folder, so it stays with web editors. --}}
                <div x-data="{ show: false }" class="mt-3">
                    <button type="button" @click="show = !show"
                            class="flex items-center gap-1 text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                        <x-bootstrap-icon name="images" class="size-3.5" />
                        <span x-text="show ? 'Dölj bilder' : 'Bilder'"></span>
                    </button>
                    <div x-show="show" x-cloak class="mt-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <livewire:admin.pages.image-browser />
                    </div>
                </div>
            @endcan
        </div>

        <div>
            <label for="wiki-summary" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Beskriv ändringen</label>
            <input id="wiki-summary" wire:model="summary" type="text" maxlength="255"
                   placeholder="{{ $page ? 'Lade till larmkoden' : 'Första versionen' }}"
                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
            <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Valfritt. Syns i sidans historik.</p>
            @error('summary') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <div class="flex gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                {{ $page ? 'Spara' : 'Skapa sida' }}
            </button>
            <a href="{{ $page ? route('wiki.page', [$space, $page]) : route('wiki.space', $space) }}" wire:navigate
               class="rounded-lg px-4 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                Avbryt
            </a>
        </div>
    </form>
</div>
