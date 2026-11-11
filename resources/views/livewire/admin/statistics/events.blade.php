<div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 space-y-8">

    {{-- Header --}}
    <div class="flex items-center gap-4 flex-wrap">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Evenemangsstatistik</h1>
        <div class="flex gap-1 rounded-lg bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 p-1">
            @foreach ($years as $y)
                <button wire:click="$set('year', {{ $y }})"
                        class="px-3 py-1 rounded-md text-sm font-medium transition-colors tabular-nums
                               {{ $year === $y
                                   ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white shadow-sm'
                                   : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200' }}">
                    {{ $y }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- KPI-kort --}}
    @php
        $pctDiff  = fn($a, $b) => $b > 0 ? round(($a - $b) / $b * 100) : null;
        $diffLabel = fn($pct) => $pct !== null ? ($pct >= 0 ? "+{$pct}%" : "{$pct}%") : '';
        $diffCls   = fn($pct) => $pct > 0 ? 'text-green-600 dark:text-green-400' : ($pct < 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-400');
    @endphp
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        @foreach ([
            ['Evenemang', $totalEvents, $totalEventsPrev],
            ['Deltagare', $totalRegs, $regsLastYear],
            ['Snitt per event', $avgRegsPerEvent, null],
            ['År', $year, null],
        ] as [$label, $val, $prev])
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $label }}</p>
                <p class="mt-2 text-3xl font-bold text-zinc-900 dark:text-white tabular-nums">{{ $val }}</p>
                @if ($prev !== null)
                    @php $pct = $pctDiff($val, $prev); @endphp
                    <p class="mt-1 text-xs {{ $diffCls($pct) }}">{{ $diffLabel($pct) }} vs {{ $year - 1 }} ({{ $prev }})</p>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Månadsdiagram --}}
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6">
        <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-4">Tillfällen och deltagare per månad</h2>
        <div class="flex items-end gap-2 h-40">
            @php $maxMonth = collect($monthly)->max(fn($m) => max($m['occurrences'], $m['regs'])); @endphp
            @foreach ($monthly as $m)
                <div class="flex-1 flex flex-col items-center gap-1">
                    <div class="w-full flex items-end gap-0.5 h-32">
                        @php $h = $maxMonth > 0 ? round($m['occurrences'] / $maxMonth * 100) : 0; @endphp
                        <div class="flex-1 rounded-t bg-zinc-200 dark:bg-zinc-700 transition-all"
                             title="Tillfällen {{ $year }}: {{ $m['occurrences'] }}"
                             style="height: {{ $h }}%"></div>
                        @php $h = $maxMonth > 0 ? round($m['regs'] / $maxMonth * 100) : 0; @endphp
                        <div class="flex-1 rounded-t bg-brand transition-all"
                             title="Deltagare {{ $year }}: {{ $m['regs'] }}"
                             style="height: {{ $h }}%"></div>
                    </div>
                    <span class="text-xs text-zinc-400">{{ $m['label'] }}</span>
                </div>
            @endforeach
        </div>
        <div class="mt-3 flex items-center gap-4 text-xs text-zinc-500 dark:text-zinc-400">
            <span class="flex items-center gap-1.5"><span class="inline-block h-2 w-4 rounded bg-brand"></span>Deltagare</span>
            <span class="flex items-center gap-1.5"><span class="inline-block h-2 w-4 rounded bg-zinc-200 dark:bg-zinc-700"></span>Tillfällen</span>
        </div>
    </div>

    {{-- Topp-event --}}
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6">
        <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-4">Topp 10 evenemang (bekräftade deltagare)</h2>
        @if ($topEvents->isEmpty())
            <p class="text-sm text-zinc-400">Inga data.</p>
        @else
            @php $maxTop = $topEvents->max('count'); @endphp
            <div class="space-y-3">
                @foreach ($topEvents as $i => $e)
                    <div>
                        <div class="flex items-center justify-between text-xs mb-1">
                            <span class="text-zinc-700 dark:text-zinc-300 font-medium truncate max-w-xs">{{ $i + 1 }}. {{ $e['title'] }}</span>
                            <span class="text-zinc-500 dark:text-zinc-400 tabular-nums flex-shrink-0 ml-2">{{ $e['count'] }}</span>
                        </div>
                        <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div class="h-2 rounded-full bg-brand transition-all"
                                 style="width: {{ $maxTop > 0 ? round($e['count'] / $maxTop * 100) : 0 }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

</div>
