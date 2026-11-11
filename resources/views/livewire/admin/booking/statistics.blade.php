<div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 space-y-8"
     x-data="bookingStats({{ Js::from($chartData) }})"
     x-init="init()">

    {{-- Header --}}
    <div class="flex items-center gap-4 flex-wrap">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Statistik</h1>
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
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        @php
            $pctDiff = fn($a, $b) => $b > 0 ? round(($a - $b) / $b * 100) : null;
            $diffLabel = fn($pct) => $pct !== null ? ($pct >= 0 ? "+{$pct}%" : "{$pct}%") : '';
            $diffCls = fn($pct) => $pct > 0 ? 'text-green-600 dark:text-green-400' : ($pct < 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-400');
        @endphp

        @foreach ([
            ['Bokningar', $totalCurrent, $totalPrev],
            ['Unika bokare', $uniqueMembersCurrent, $uniqueMembersPrev],
            ['Öppna arrangemang', $openCurrent, null],
            ['Populärast', $topActivity, null],
        ] as [$label, $val, $prev])
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $label }}</p>
                <p class="mt-2 text-3xl font-bold text-zinc-900 dark:text-white tabular-nums">{{ $val ?? '—' }}</p>
                @if ($prev !== null)
                    @php $pct = $pctDiff($val, $prev); @endphp
                    <p class="mt-1 text-xs {{ $diffCls($pct) }}">{{ $diffLabel($pct) }} vs {{ $year - 1 }} ({{ $prev }})</p>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Månadsdiagram --}}
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6">
        <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-4">Bokningar per månad</h2>
        <div class="flex items-end gap-2 h-40">
            @php $maxMonth = collect($monthly)->max(fn($m) => max($m['current'], $m['prev'])); @endphp
            @foreach ($monthly as $m)
                <div class="flex-1 flex flex-col items-center gap-1">
                    <div class="w-full flex items-end gap-0.5 h-32">
                        @php $h = $maxMonth > 0 ? round($m['prev'] / $maxMonth * 100) : 0; @endphp
                        <div class="flex-1 rounded-t bg-zinc-200 dark:bg-zinc-700 transition-all"
                             style="height: {{ $h }}%" title="{{ $year - 1 }}: {{ $m['prev'] }}"></div>
                        @php $h = $maxMonth > 0 ? round($m['current'] / $maxMonth * 100) : 0; @endphp
                        <div class="flex-1 rounded-t bg-brand transition-all"
                             style="height: {{ $h }}%" title="{{ $year }}: {{ $m['current'] }}"></div>
                    </div>
                    <span class="text-xs text-zinc-400">{{ $m['label'] }}</span>
                </div>
            @endforeach
        </div>
        <div class="mt-3 flex items-center gap-4 text-xs text-zinc-500 dark:text-zinc-400">
            <span class="flex items-center gap-1.5"><span class="inline-block h-2 w-4 rounded bg-brand"></span>{{ $year }}</span>
            <span class="flex items-center gap-1.5"><span class="inline-block h-2 w-4 rounded bg-zinc-200 dark:bg-zinc-700"></span>{{ $year - 1 }}</span>
        </div>
    </div>

    {{-- Rum + Veckodag --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Rum --}}
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-4">Rum</h2>
            @php $maxRoom = collect($roomStats)->max('count'); @endphp
            <div class="space-y-3">
                @foreach ($roomStats->sortByDesc('count')->take(10) as $r)
                    <div>
                        <div class="flex items-center justify-between text-xs mb-1">
                            <span class="text-zinc-700 dark:text-zinc-300 font-medium">{{ $r['name'] }}</span>
                            <span class="text-zinc-500 dark:text-zinc-400 tabular-nums">{{ $r['count'] }} ({{ $r['hours'] }}h, {{ $r['open_pct'] }}% öppna)</span>
                        </div>
                        <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div class="h-2 rounded-full bg-complement transition-all"
                                 style="width: {{ $maxRoom > 0 ? round($r['count'] / $maxRoom * 100) : 0 }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Veckodagar --}}
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-4">Veckodag</h2>
            @php $maxDay = collect($weekdayStats)->max('count'); @endphp
            <div class="flex items-end gap-2 h-32">
                @foreach ($weekdayStats as $d)
                    <div class="flex-1 flex flex-col items-center gap-1">
                        <div class="w-full flex items-end h-24">
                            @php $h = $maxDay > 0 ? round($d['count'] / $maxDay * 100) : 0; @endphp
                            <div class="w-full rounded-t bg-brand-orange transition-all"
                                 style="height: {{ $h }}%" title="{{ $d['label'] }}: {{ $d['count'] }}"></div>
                        </div>
                        <span class="text-xs text-zinc-400">{{ $d['label'] }}</span>
                        <span class="text-xs font-medium text-zinc-600 dark:text-zinc-300 tabular-nums">{{ $d['count'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

    </div>

    {{-- Aktiviteter + Topplista --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Aktiviteter --}}
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-4">Aktiviteter</h2>
            @php $maxAct = collect($activityStats)->max('current'); @endphp
            <div class="space-y-3">
                @foreach ($activityStats->take(10) as $a)
                    <div>
                        <div class="flex items-center justify-between text-xs mb-1">
                            <span class="text-zinc-700 dark:text-zinc-300 font-medium">{{ $a['label'] ?: '—' }}</span>
                            <span class="text-zinc-500 dark:text-zinc-400 tabular-nums">
                                {{ $a['current'] }}
                                @if ($a['prev'] > 0) <span class="text-zinc-300 dark:text-zinc-600">({{ $a['prev'] }})</span> @endif
                            </span>
                        </div>
                        <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div class="h-2 rounded-full bg-brand transition-all"
                                 style="width: {{ $maxAct > 0 ? round($a['current'] / $maxAct * 100) : 0 }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Top 10 bokare --}}
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-4">Topp 10 bokare</h2>
            @if ($topUsers->isEmpty())
                <p class="text-sm text-zinc-400">Inga data.</p>
            @else
                <div class="space-y-2">
                    @foreach ($topUsers as $i => $u)
                        <div class="flex items-center gap-3">
                            <span class="w-5 text-right text-xs text-zinc-400 tabular-nums flex-shrink-0">{{ $i + 1 }}.</span>
                            <span class="flex-1 text-sm text-zinc-700 dark:text-zinc-300 truncate">{{ $u['user']->name }}</span>
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white tabular-nums">{{ $u['count'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

    </div>

</div>

@script
<script>
function bookingStats(data) {
    return {
        data,
        init() {
            // Chart data available as this.data for any Alpine-based charts
        }
    };
}
</script>
@endscript
