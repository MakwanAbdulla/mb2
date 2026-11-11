<x-slot:header>
    <x-page-header permission="cms.edit" :title="'Redigera: ' . $page->title" />
</x-slot:header>

<div>
    <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">

        @if(session('success'))
            <div class="mb-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                {{ session('success') }}
            </div>
        @endif

        {{-- Draft banner --}}
        @if($page->has_draft)
            <div class="mb-6 flex flex-col gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm font-medium text-amber-900 dark:text-amber-300">
                    Det finns opublicerade ändringar. Den publicerade versionen påverkas inte förrän du publicerar.
                </p>
                <div class="flex gap-2">
                    <button type="button" wire:click="saveAndPublish"
                            wire:confirm="Publicera ändringarna? Den publicerade versionen ersätts."
                            class="rounded-lg bg-brand px-3 py-1.5 text-xs font-semibold text-brand-dark hover:bg-brand-saffron">
                        Spara &amp; publicera
                    </button>
                    <button type="button" wire:click="discardDraft"
                            wire:confirm="Kassera alla opublicerade ändringar?"
                            class="rounded-lg px-3 py-1.5 text-xs font-semibold text-zinc-600 hover:bg-amber-100 dark:text-amber-300 dark:hover:bg-amber-900/30">
                        Kassera
                    </button>
                </div>
            </div>
        @endif

        <form wire:submit="update" class="space-y-6">

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Titel <span class="text-red-500">*</span></label>
                <input wire:model="title" type="text" placeholder="T.ex. Om oss"
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
                        @foreach($navGroups as $label)
                            <option value="{{ \Illuminate\Support\Str::slug($label) }}">{{ $label }}</option>
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
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400 font-mono">
                URL: /{{ $path_prefix ? $path_prefix . '/' : '' }}{{ $slug }}
            </p>

            {{-- Trix editor --}}
            <div>
                <div class="mb-2 flex items-center justify-between">
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Innehåll <span class="text-red-500">*</span></label>
                    <button type="button" id="toggleHtmlBtn"
                            class="rounded border border-zinc-200 px-2 py-1 text-xs text-zinc-500 hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
                        HTML-läge
                    </button>
                </div>

                <div wire:ignore>
                    <div id="trixWrapper" class="overflow-hidden rounded-lg border border-zinc-300 dark:border-zinc-600">
                        <input type="hidden" id="content-input" wire:model="content">
                        <trix-editor input="content-input" class="trix-content"></trix-editor>
                    </div>
                    <textarea id="contentCode" class="hidden w-full rounded-lg border border-zinc-300 p-3 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" rows="15" spellcheck="false"></textarea>
                </div>

                {{-- Media-väljare --}}
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
                            <button type="button" @click="tab = 'colors'"
                                    :class="tab === 'colors' ? 'border-b-2 border-brand-dark text-zinc-900 dark:border-brand dark:text-zinc-100' : 'text-zinc-500 dark:text-zinc-400'"
                                    class="-mb-px px-3 py-1.5 text-xs font-medium">Färger</button>
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
                        <div x-show="tab === 'colors'">
                            <x-cms.color-reference />
                        </div>
                    </div>
                </div>

                @error('content') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                @if(!empty($unknownWidgetsWarning))
                    <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20">
                        <p class="mb-1.5 text-xs font-semibold text-amber-900 dark:text-amber-300">
                            Okända widget-taggar — kontrollera stavning:
                        </p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($unknownWidgetsWarning as $tag)
                                <span class="inline-block rounded bg-amber-100 px-1.5 py-0.5 font-mono text-xs text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                                    [{{ $tag }}]
                                </span>
                            @endforeach
                        </div>
                        <p class="mt-1.5 text-xs text-amber-700 dark:text-amber-400">Utkastet sparades ändå. Okända taggar visas obearbetade på sidan.</p>
                    </div>
                @endif
            </div>

            <hr class="border-zinc-200 dark:border-zinc-700">

            <div class="grid gap-6 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Publiceras</label>
                    <input wire:model="published_at" type="datetime-local"
                           class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Lämna tomt = direkt</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Avpubliceras</label>
                    <input wire:model="unpublished_at" type="datetime-local"
                           class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Sorteringsordning</label>
                <input wire:model="sort_order" type="number" min="0"
                       class="mt-1 w-32 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
            </div>

            <div class="space-y-3">
                <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                    <input type="checkbox" wire:model="is_published" class="h-4 w-4 rounded border-zinc-300 accent-brand-dark" />
                    Publicerad
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
                    Spara utkast
                </button>
                @if(!$page->has_draft)
                    <button type="button" wire:click="saveAndPublish"
                            class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800">
                        Spara &amp; publicera
                    </button>
                @endif
                <a href="{{ route('admin.pages.index') }}" wire:navigate
                   class="rounded-lg px-4 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                    Tillbaka
                </a>
            </div>

        </form>
    </div>
</div>

<style>
.trix-content trix-editor,
trix-editor.trix-content {
    min-height: 300px;
    padding: 1rem;
    outline: none;
}
</style>

<script>
(function() {
    function initTrix() {
        const trixEditor = document.querySelector('trix-editor');
        if (!trixEditor) return;

        // Load saved content into editor (always — the DOM node may be the
        // same one reused across wire:navigate thanks to wire:ignore, so the
        // content always needs refreshing for whichever page/component is
        // now current).
        setTimeout(() => {
            const val = document.getElementById('content-input')?.value || '';
            if (val) trixEditor.editor.loadHTML(val);
        }, 100);

        // Because wire:ignore keeps this DOM node alive across re-renders and
        // wire:navigate transitions (e.g. the save-then-redirect-to-self in
        // Edit::update()), initTrix() can run many times against the SAME
        // node. Abort any listeners from a previous run before attaching new
        // ones, otherwise they pile up — more duplicate @this.set() calls per
        // keystroke on every save, eventually freezing the tab.
        if (trixEditor._trixAbort) {
            trixEditor._trixAbort.abort();
        }
        const controller = new AbortController();
        trixEditor._trixAbort = controller;
        const { signal } = controller;

        // Sync editor changes to Livewire
        trixEditor.addEventListener('trix-change', function() {
            @this.set('content', trixEditor.value);
        }, { signal });

        // HTML/Visual mode toggle
        const toggleBtn = document.getElementById('toggleHtmlBtn');
        const codeArea = document.getElementById('contentCode');
        const wrapper = document.getElementById('trixWrapper');
        let htmlMode = false;

        toggleBtn.addEventListener('click', () => {
            htmlMode = !htmlMode;
            if (htmlMode) {
                codeArea.value = trixEditor.value || '';
                wrapper.style.display = 'none';
                codeArea.classList.remove('hidden');
                toggleBtn.textContent = 'Visuellt läge';
            } else {
                const html = codeArea.value || '';
                @this.set('content', html);
                trixEditor.editor.loadHTML(html);
                codeArea.classList.add('hidden');
                wrapper.style.display = 'block';
                toggleBtn.textContent = 'HTML-läge';
            }
        }, { signal });

        // Synka HTML-textarea till Livewire medan man skriver
        codeArea.addEventListener('input', function() {
            @this.set('content', codeArea.value);
        }, { signal });

        // Insert shortcode at cursor (or append in HTML mode)
        window.insertWidget = function(tag) {
            if (htmlMode) {
                const textarea = document.getElementById('contentCode');
                const start = textarea.selectionStart;
                const end = textarea.selectionEnd;
                textarea.value = textarea.value.slice(0, start) + tag + textarea.value.slice(end);
                textarea.selectionStart = textarea.selectionEnd = start + tag.length;
                textarea.focus();
            } else {
                trixEditor.editor.insertString(tag);
                trixEditor.focus();
            }
        };

        // Insert image when selected from browser
        window.addEventListener('imageSelected', e => {
            const url = e.detail[0] ?? e.detail?.path ?? e.detail;
            if (!url) return;
            const publicUrl = url.startsWith('http') ? url : '/storage/' + url;
            const tag = `<img src="${publicUrl}" alt="" style="max-width:100%">`;
            trixEditor.editor.insertHTML(tag);
        }, { signal });
    }

    document.addEventListener('livewire:navigated', initTrix);
    document.addEventListener('DOMContentLoaded', initTrix);
})();
</script>
