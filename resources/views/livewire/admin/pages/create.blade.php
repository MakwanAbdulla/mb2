<?php

use App\Models\Content\NavigationItem;
use App\Models\Content\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component {

    public string $title = '';
    public string $subtitle = '';
    public string $slug = '';
    public ?string $path_prefix = null;
    public string $content = '';
    public bool $is_published = false;
    public bool $show_in_nav = false;
    public string $nav_title = '';
    public int $sort_order = 0;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('cms.edit'), 403);
    }

    public function updatedTitle(): void
    {
        if (empty($this->slug)) {
            $this->slug = Str::slug($this->title);
        }
    }

    public function save(): void
    {
        $this->validate([
            'title'       => 'required|string|max:255',
            'subtitle'    => 'nullable|string|max:500',
            'slug'        => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/',
                              \Illuminate\Validation\Rule::unique('pages')->where(fn ($q) => $q->where('path_prefix', $this->path_prefix))],
            'path_prefix' => 'nullable|string|max:100|regex:/^[a-z0-9-]+$/',
            'content'     => 'required|string',
            'is_published'=> 'boolean',
            'show_in_nav' => 'boolean',
            'nav_title'   => 'nullable|string|max:255',
            'sort_order'  => 'required|integer|min:0',
        ], [
            'title.required'    => 'Titel är obligatorisk.',
            'slug.required'     => 'Slug är obligatorisk.',
            'slug.unique'       => 'Denna slug används redan av en annan sida med samma prefix.',
            'content.required'  => 'Innehåll är obligatoriskt.',
        ]);

        Page::create([
            'title'        => $this->title,
            'subtitle'     => $this->subtitle ?: null,
            'slug'         => $this->slug,
            'path_prefix'  => $this->path_prefix ?: null,
            'content'      => $this->content,
            'is_published' => $this->is_published,
            'show_in_nav'  => $this->show_in_nav,
            'nav_title'    => $this->nav_title ?: null,
            'sort_order'   => $this->sort_order,
        ]);

        session()->flash('success', "Sidan '{$this->title}' skapades.");
        $this->redirect(route('admin.pages.index'), navigate: true);
    }
}; ?>

<x-slot:header>
    <x-page-header permission="cms.edit" title="Skapa ny sida" />
</x-slot:header>

<div>
    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
        <form wire:submit="save" class="space-y-6">

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Titel <span class="text-red-500">*</span></label>
                <input wire:model.blur="title" wire:change="$refresh" type="text" placeholder="T.ex. Om oss"
                       class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
                @error('title') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Underrubrik</label>
                <input wire:model="subtitle" type="text" placeholder="Valfri underrubrik"
                       class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
                @error('subtitle') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-[1fr_auto_2fr] sm:items-end">
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">URL-prefix</label>
                    <select wire:model.live="path_prefix"
                            class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
                        <option value="">Ingen (toppnivå)</option>
                        @foreach(NavigationItem::active()->topnav()->rootItems()->whereNull('url')->whereNull('route_name')->get() as $group)
                            <option value="{{ \Illuminate\Support\Str::slug($group->label) }}">{{ $group->label }}</option>
                        @endforeach
                    </select>
                    @error('path_prefix') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <span class="hidden pb-2 text-zinc-400 sm:block">/</span>
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Slug <span class="text-red-500">*</span></label>
                    <input wire:model.live="slug" type="text" placeholder="om-oss"
                           class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
                    @error('slug') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="mt-1 font-mono text-xs text-zinc-500 dark:text-zinc-400">
                URL: /{{ $path_prefix ? $path_prefix . '/' : '' }}{{ $slug }}
            </p>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Innehåll <span class="text-red-500">*</span></label>
                <div class="mt-1" wire:ignore>
                    <input type="hidden" id="content-input" wire:model="content">
                    <trix-editor input="content-input" class="trix-content rounded-lg border border-zinc-300 dark:border-zinc-600" style="min-height:250px;padding:1rem;"></trix-editor>
                </div>
                <div x-data="{ show: false }" class="mt-3 space-y-2">
                    <button type="button" @click="show = !show"
                            class="flex items-center gap-1 text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                        <x-bootstrap-icon name="images" class="size-3.5" />
                        <span x-text="show ? 'Dölj bilder, ikoner & widgets' : 'Bilder, ikoner & widgets'"></span>
                    </button>
                    <div x-show="show" x-cloak x-data="{ tab: 'images' }" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <div class="mb-3 flex gap-1 border-b border-zinc-200 dark:border-zinc-700">
                            <button type="button" @click="tab = 'images'"
                                    :class="tab === 'images' ? 'border-b-2 border-brand-dark text-zinc-900 dark:border-brand dark:text-zinc-100' : 'text-zinc-500 dark:text-zinc-400'"
                                    class="-mb-px px-3 py-1.5 text-xs font-medium">Bilder</button>
                            <button type="button" @click="tab = 'icons'"
                                    :class="tab === 'icons' ? 'border-b-2 border-brand-dark text-zinc-900 dark:border-brand dark:text-zinc-100' : 'text-zinc-500 dark:text-zinc-400'"
                                    class="-mb-px px-3 py-1.5 text-xs font-medium">Ikoner</button>
                            <button type="button" @click="tab = 'widgets'"
                                    :class="tab === 'widgets' ? 'border-b-2 border-brand-dark text-zinc-900 dark:border-brand dark:text-zinc-100' : 'text-zinc-500 dark:text-zinc-400'"
                                    class="-mb-px px-3 py-1.5 text-xs font-medium">Widgets</button>
                        </div>
                        <div x-show="tab === 'images'">
                            <livewire:admin.pages.image-browser />
                        </div>
                        <div x-show="tab === 'icons'">
                            <livewire:admin.pages.bootstrap-icon-browser />
                        </div>
                        <div x-show="tab === 'widgets'">
                            <x-cms.widget-reference />
                        </div>
                    </div>
                </div>

                @error('content') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <hr class="border-zinc-200 dark:border-zinc-700">

            <div class="grid gap-6 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Sorteringsordning</label>
                    <input wire:model="sort_order" type="number" min="0"
                           class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                </div>
            </div>

            <div class="space-y-3">
                <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                    <input type="checkbox" wire:model="is_published" class="h-4 w-4 rounded border-zinc-300 accent-brand-dark" />
                    Publicera direkt
                </label>
                <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                    <input type="checkbox" wire:model.live="show_in_nav" class="h-4 w-4 rounded border-zinc-300 accent-brand-dark" />
                    Visa i navigation
                </label>
            </div>

            <div x-show="$wire.show_in_nav">
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Meny-titel</label>
                <input wire:model="nav_title" type="text" placeholder="Lämna tomt för att använda titeln"
                       class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
            </div>

            <div class="flex gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                <button type="submit"
                        class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                    Skapa sida
                </button>
                <a href="{{ route('admin.pages.index') }}" wire:navigate
                   class="rounded-lg px-4 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                    Avbryt
                </a>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('trix-change', function(e) {
        const hidden = document.getElementById('content-input');
        if (hidden && e.target.input === hidden) {
            @this.set('content', e.target.value);
        }
    });

    window.insertWidget = function(tag) {
        const editor = document.querySelector('trix-editor');
        if (editor) {
            editor.editor.insertString(tag);
            editor.focus();
        }
    };
</script>
