{{--
    Widget reference panel — embed inside a tab container.
    Usage: <x-cms.widget-reference />
    Requires the insertWidget(tag) JS function in the parent view.
--}}
@php
    $widgets = app(\App\Services\WidgetService::class)->all();
@endphp

@if(empty($widgets))
    <p class="py-6 text-center text-xs text-zinc-400 dark:text-zinc-500">Inga widgets är registrerade.</p>
@else
    <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">
        Klicka på en widget för att infoga den i innehållet. Widgets ersätts med dynamiskt innehåll när sidan visas.
    </p>

    <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
        @foreach($widgets as $widget)
            <div class="py-3" x-data="{ copied: false, preview: false }">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">

                        {{-- Tag button + copied feedback --}}
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button"
                                    @click="
                                        insertWidget({{ Js::from($widget['example']) }});
                                        copied = true;
                                        setTimeout(() => copied = false, 1500);
                                    "
                                    class="inline-flex items-center gap-1.5 rounded bg-zinc-100 px-2 py-1 font-mono text-xs font-medium text-zinc-700 transition-colors hover:bg-brand/20 hover:text-brand-dark dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-brand/20 dark:hover:text-brand">
                                <x-bootstrap-icon name="braces" class="size-3" />
                                {{ $widget['example'] }}
                            </button>
                            <span x-show="copied" x-cloak class="text-xs text-green-600 dark:text-green-400">
                                ✓ Infogad!
                            </span>
                        </div>

                        {{-- Params --}}
                        @if(!empty($widget['params']))
                            <div class="mt-1.5 space-y-0.5">
                                @foreach($widget['params'] as $param)
                                    <p class="text-xs text-zinc-400 dark:text-zinc-500">
                                        <span class="font-mono">{{ $param['name'] }}{{ $param['required'] ? '' : '?' }}</span>
                                        — {{ $param['description'] }}
                                    </p>
                                @endforeach
                            </div>
                        @endif

                        {{-- Description --}}
                        <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $widget['description'] }}</p>
                    </div>

                    {{-- Preview toggle --}}
                    <button type="button"
                            @click="preview = !preview"
                            class="shrink-0 rounded px-2 py-1 text-xs text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                            x-text="preview ? 'Dölj' : 'Förhandsvisning'">
                    </button>
                </div>

                {{-- Live preview --}}
                <div x-show="preview" x-cloak class="mt-3 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <div class="border-b border-zinc-100 bg-zinc-50 px-3 py-1.5 dark:border-zinc-700 dark:bg-zinc-800/50">
                        <p class="text-xs text-zinc-400 dark:text-zinc-500">Förhandsvisning (live data)</p>
                    </div>
                    <div class="p-4">
                        {!! app(\App\Services\WidgetService::class)->render($widget['example']) !!}
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif
