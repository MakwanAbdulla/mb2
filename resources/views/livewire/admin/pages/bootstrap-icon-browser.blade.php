<div class="space-y-2">
    <input
        type="text"
        wire:model.live.debounce.500ms="search"
        placeholder="Sök på svenska eller engelska (t.ex. 'kalender', 'hjärta fill')..."
        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
    />

    @if(count($icons) > 0)
        <div class="mb-1 text-xs text-zinc-600 dark:text-zinc-400">
            Visar {{ count($icons) }} ikon{{ count($icons) !== 1 ? 'er' : '' }}{{ empty($search) ? ' (sök för fler)' : '' }}. Klicka för att infoga.
        </div>
        <div class="max-h-80 overflow-y-auto rounded-lg border border-zinc-300 bg-white p-2 dark:border-zinc-600 dark:bg-zinc-900">
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(2.5rem, 1fr)); gap: 0.25rem;">
                @foreach($icons as $icon)
                    <button type="button"
                            wire:key="icon-{{ $icon['name'] }}"
                            class="flex aspect-square items-center justify-center rounded border border-zinc-200 transition-colors hover:border-blue-500 hover:bg-blue-50 dark:border-zinc-700 dark:hover:bg-blue-950"
                            onclick="event.preventDefault(); event.stopPropagation();
                                const imgTag = `<img src=&quot;{{ $icon['path'] }}&quot; alt=&quot;{{ $icon['name'] }}&quot; class=&quot;inline-block&quot; style=&quot;width: 1.5rem; height: 1.5rem;&quot; />`;
                                const trixEditor = document.querySelector('trix-editor');
                                if (trixEditor) { trixEditor.editor.insertHTML(imgTag); }"
                            title="{{ $icon['name'] }}">
                        <img src="{{ $icon['path'] }}" alt="{{ $icon['name'] }}" class="h-5 w-5 opacity-70" loading="lazy" />
                    </button>
                @endforeach
            </div>
        </div>
    @else
        <div class="py-8 text-center text-zinc-500 dark:text-zinc-400">
            @if(empty($search))
                <p>Inga ikoner tillgängliga.</p>
            @else
                <p>Inga ikoner matchade "{{ $search }}"</p>
            @endif
        </div>
    @endif
</div>
