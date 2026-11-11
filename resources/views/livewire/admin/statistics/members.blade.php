<div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 space-y-8">

    {{-- Header --}}
    <div class="flex items-center gap-4 flex-wrap">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Medlemsstatistik</h1>
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

    {{-- Över tid: total / nya / tappade per år --}}
    @if ($overTime->isNotEmpty())
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6">
        <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-5">Medlemsskap över tid</h2>

        {{-- Stapeldiagram --}}
        @php $maxTotal = $overTime->max('total'); @endphp
        <div class="flex items-end gap-3 h-48 mb-4">
            @foreach ($overTime as $row)
                <div class="flex-1 flex flex-col items-center gap-1 min-w-0">
                    <div class="w-full flex flex-col items-stretch justify-end h-40 gap-px">
                        {{-- Total stapel --}}
                        @php $h = $maxTotal > 0 ? round($row['total'] / $maxTotal * 100) : 0; @endphp
                        <div class="w-full rounded-t bg-brand transition-all"
                             style="height: {{ $h }}%"
                             title="{{ $row['year'] }}: {{ $row['total'] }} aktiva"></div>
                    </div>
                    <span class="text-xs text-zinc-400 tabular-nums">{{ $row['year'] }}</span>
                </div>
            @endforeach
        </div>

        {{-- Tabell: år / totalt / nya / tappade / netto --}}
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 dark:border-zinc-700">
                        <th class="pb-2 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">År</th>
                        <th class="pb-2 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Totalt aktiva</th>
                        <th class="pb-2 text-right text-xs font-semibold uppercase tracking-wide text-green-600 dark:text-green-400">Nya</th>
                        <th class="pb-2 text-right text-xs font-semibold uppercase tracking-wide text-red-500 dark:text-red-400">Tappade</th>
                        <th class="pb-2 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Netto</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800">
                    @foreach ($overTime->sortByDesc('year') as $row)
                        @php $net = $row['new'] - $row['churned']; @endphp
                        <tr class="{{ $row['year'] === $year ? 'bg-brand/5' : '' }}">
                            <td class="py-2 font-semibold tabular-nums {{ $row['year'] === $year ? 'text-zinc-900 dark:text-white' : 'text-zinc-700 dark:text-zinc-300' }}">
                                {{ $row['year'] }}
                            </td>
                            <td class="py-2 text-right tabular-nums text-zinc-700 dark:text-zinc-300 font-medium">
                                {{ $row['total'] }}
                            </td>
                            <td class="py-2 text-right tabular-nums {{ $row['new'] > 0 ? 'text-green-600 dark:text-green-400' : 'text-zinc-400' }}">
                                @if ($row['new'] > 0)+{{ $row['new'] }}@else —@endif
                            </td>
                            <td class="py-2 text-right tabular-nums {{ $row['churned'] > 0 ? 'text-red-500 dark:text-red-400' : 'text-zinc-400' }}">
                                @if ($row['churned'] > 0)−{{ $row['churned'] }}@else —@endif
                            </td>
                            <td class="py-2 text-right tabular-nums font-medium
                                {{ $net > 0 ? 'text-green-600 dark:text-green-400' : ($net < 0 ? 'text-red-500 dark:text-red-400' : 'text-zinc-400') }}">
                                {{ $net > 0 ? '+' : '' }}{{ $net !== 0 ? $net : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-zinc-400 dark:text-zinc-500">
            Nya = första aktiva period någonsin. Tappade = hade aktiv period förra året men inte i år.
        </p>
    </div>
    @endif

    {{-- KPI-kort (för valt år) --}}
    @php
        $pctDiff  = fn($a, $b) => $b > 0 ? round(($a - $b) / $b * 100) : null;
        $diffLabel = fn($pct) => $pct !== null ? ($pct >= 0 ? "+{$pct}%" : "{$pct}%") : '';
        $diffCls   = fn($pct) => $pct > 0 ? 'text-green-600 dark:text-green-400' : ($pct < 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-400');
    @endphp
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        @foreach ([
            ['Aktiva nu', $totalActive, null],
            ['Nya i år', $newThisYear, $newPrevYear],
            ['Förnyade perioder', $renewals, null],
            ['Valt år', $year, null],
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

    {{-- Månadsdiagram (nya medlemmar) --}}
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6">
        <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-4">Nya medlemmar per månad — {{ $year }}</h2>
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

    {{-- Säsongsmönster: nya medlemmar per kalendermånad, alla år --}}
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6">
        <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Nya medlemmar per månad — alla år</h2>
        <p class="text-xs text-zinc-400 dark:text-zinc-500 mb-4">
            Summerat sedan {{ $seasonalityYears }}, oavsett år. Visar när på året nya medlemmar tillkommer.
        </p>
        @php
            $seasonTotal = $seasonality->sum('count');
            $seasonMax   = $seasonality->max('count');
            $seasonPeak  = $seasonality->sortByDesc('count')->first()['label'] ?? null;
        @endphp
        <div class="flex items-end gap-2 h-40">
            @foreach ($seasonality as $m)
                @php
                    $h   = $seasonMax > 0 ? round($m['count'] / $seasonMax * 100) : 0;
                    $pct = $seasonTotal > 0 ? round($m['count'] / $seasonTotal * 100) : 0;
                    $isPeak = $m['label'] === $seasonPeak && $m['count'] > 0;
                @endphp
                <div class="flex-1 flex flex-col items-center gap-1 min-w-0">
                    <div class="w-full flex items-end h-32">
                        <div class="w-full rounded-t transition-all {{ $isPeak ? 'bg-brand' : 'bg-brand/50' }}"
                             style="height: {{ $h }}%"
                             title="{{ $m['label'] }}: {{ $m['count'] }} nya ({{ $pct }} %)"></div>
                    </div>
                    <span class="text-xs {{ $isPeak ? 'text-zinc-700 dark:text-zinc-200 font-semibold' : 'text-zinc-400' }}">{{ $m['label'] }}</span>
                </div>
            @endforeach
        </div>
        @if ($seasonPeak)
            <p class="mt-3 text-xs text-zinc-400 dark:text-zinc-500">
                Flest nya medlemmar i <span class="font-medium text-zinc-600 dark:text-zinc-300">{{ $seasonPeak }}</span>.
            </p>
        @endif
    </div>

    {{-- Typ + Status --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Typ (aktiva nu) --}}
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-4">Typer (aktiva nu)</h2>
            @php $maxType = collect($typeStats)->max('count'); @endphp
            <div class="space-y-3">
                @forelse ($typeStats as $t)
                    <div>
                        <div class="flex items-center justify-between text-xs mb-1">
                            <span class="text-zinc-700 dark:text-zinc-300 font-medium">{{ $t['label'] }}</span>
                            <span class="text-zinc-500 dark:text-zinc-400 tabular-nums">{{ $t['count'] }}</span>
                        </div>
                        <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div class="h-2 rounded-full bg-brand transition-all"
                                 style="width: {{ $maxType > 0 ? round($t['count'] / $maxType * 100) : 0 }}%"></div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-zinc-400">Inga data.</p>
                @endforelse
            </div>
        </div>

        {{-- Status --}}
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-4">Status (alla)</h2>
            @php $maxStatus = collect($statusStats)->max('count'); @endphp
            <div class="space-y-3">
                @foreach ($statusStats as $s)
                    @if ($s['count'] > 0)
                        <div>
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="text-zinc-700 dark:text-zinc-300 font-medium">{{ $s['label'] }}</span>
                                <span class="text-zinc-500 dark:text-zinc-400 tabular-nums">{{ $s['count'] }}</span>
                            </div>
                            <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <div class="h-2 rounded-full bg-complement transition-all"
                                     style="width: {{ $maxStatus > 0 ? round($s['count'] / $maxStatus * 100) : 0 }}%"></div>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>

    </div>

    {{-- Åldersfördelning --}}
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6">
        <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-4">Åldersfördelning (aktiva med känt födelseår)</h2>
        @php $maxAge = collect($ageStats)->max('count'); @endphp
        <div class="flex items-end gap-3 h-40">
            @foreach ($ageStats as $a)
                <div class="flex-1 flex flex-col items-center gap-1">
                    <span class="text-xs font-medium text-zinc-600 dark:text-zinc-300 tabular-nums">{{ $a['count'] }}</span>
                    <div class="w-full flex items-end h-24">
                        @php $h = $maxAge > 0 ? round($a['count'] / $maxAge * 100) : 0; @endphp
                        <div class="w-full rounded-t bg-brand-orange transition-all"
                             style="height: {{ $h }}%" title="{{ $a['label'] }}: {{ $a['count'] }}"></div>
                    </div>
                    <span class="text-xs text-zinc-400 text-center">{{ $a['label'] }}</span>
                </div>
            @endforeach
        </div>
    </div>

</div>
