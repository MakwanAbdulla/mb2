@php
    $calendarStart = 8;
    $calendarEnd   = 24;
    $pxPerHour     = 45;
    $totalHeight   = ($calendarEnd - $calendarStart) * $pxPerHour;
    $timeColWidth  = 52;
    $eventColor    = '#4c1d95'; // complement
    $eventColorBg  = '#4c1d9520';
@endphp

<div class="px-4 py-6 sm:px-6 space-y-4">

    {{-- NAV ROW --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

        <div class="flex items-center gap-3 flex-wrap">

            {{-- Vy-växlare --}}
            <div class="flex rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-600 shrink-0">
                @foreach (['day' => 'Dag', 'week' => 'Vecka', 'list' => 'Lista'] as $v => $vLabel)
                    <button wire:click="$set('view', '{{ $v }}')"
                            class="{{ $view === $v ? 'bg-brand text-brand-dark' : 'bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700' }} px-3 py-1.5 text-sm font-medium transition-colors @if ($v !== 'day') border-l border-zinc-200 dark:border-zinc-600 @endif">
                        {{ $vLabel }}
                    </button>
                @endforeach
            </div>

            @if ($view === 'week')
                <div class="flex items-center gap-2">
                    <button wire:click="previousWeek"
                            class="inline-flex items-center rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-3 py-1.5 text-sm text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700">
                        <x-bootstrap-icon name="chevron-left" class="h-4 w-4" />
                    </button>
                    <input type="date" wire:model.live="date"
                           class="rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm py-1.5 px-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron">
                    <button wire:click="nextWeek"
                            class="inline-flex items-center rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-3 py-1.5 text-sm text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700">
                        <x-bootstrap-icon name="chevron-right" class="h-4 w-4" />
                    </button>
                    @if (! $isCurrentWeek)
                        <button wire:click="goToToday"
                                class="rounded-lg bg-brand border border-brand px-3 py-1.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                            Denna vecka
                        </button>
                    @endif
                </div>
            @else
                <div class="flex items-center gap-2">
                    <button wire:click="previousDay"
                            class="inline-flex items-center rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-3 py-1.5 text-sm text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700">
                        <x-bootstrap-icon name="chevron-left" class="h-4 w-4" />
                    </button>
                    <input type="date" wire:model.live="date"
                           class="rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm py-1.5 px-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron">
                    <button wire:click="nextDay"
                            class="inline-flex items-center rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-3 py-1.5 text-sm text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700">
                        <x-bootstrap-icon name="chevron-right" class="h-4 w-4" />
                    </button>
                    @if (! $isToday)
                        <button wire:click="goToToday"
                                class="rounded-lg bg-brand border border-brand px-3 py-1.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                            Idag
                        </button>
                    @endif
                </div>
            @endif
        </div>

        @can('events.create')
            <a href="{{ route('admin.events.create') }}" wire:navigate
               class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors shrink-0">
                + Skapa event <x-permission-hint permission="events.create" />
            </a>
        @endcan
    </div>

    {{-- ===== LISTVY ===== --}}
    @if ($view === 'list')
        @if ($listOccurrences->isEmpty())
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-10 text-center text-zinc-500 dark:text-zinc-400">
                Inga evenemang denna dag
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                @foreach ($listOccurrences as $occ)
                    @php
                        $lEndH   = $occ->ends_at->hour;
                        $lEndM   = $occ->ends_at->minute;
                        $lEndStr = ($lEndH === 0 && $lEndM === 0 && ! $occ->ends_at->isSameDay($occ->starts_at))
                            ? '24:00' : $occ->ends_at->format('H:i');
                        $lTitle  = $occ->title ?: $occ->event->title;
                    @endphp
                    <a href="{{ route('events.show', $occ->event) }}" wire:navigate
                       class="flex items-center gap-3 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/40 sm:gap-4">
                        <div class="shrink-0 w-24 text-sm tabular-nums text-zinc-700 dark:text-zinc-300 font-medium">
                            {{ $occ->starts_at->format('H:i') }}–{{ $lEndStr }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-zinc-900 dark:text-white font-medium truncate">{{ $lTitle }}</p>
                            @if ($occ->event->organizerSection)
                                <p class="text-xs text-zinc-500 dark:text-zinc-400 truncate">{{ $occ->event->organizerSection->name }}</p>
                            @endif
                        </div>
                        @if ($occ->event->status === 'draft')
                            <span class="shrink-0 hidden sm:inline-flex px-2 py-0.5 rounded-full text-xs bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300">Utkast</span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif

    {{-- ===== DAGVY ===== --}}
    @elseif ($view === 'day')
        <div class="overflow-x-auto">
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm" style="min-width: {{ $timeColWidth + 280 }}px">

                {{-- Rubrikrad --}}
                <div class="flex border-b border-zinc-300 dark:border-zinc-600 sticky top-14 z-30 bg-white dark:bg-zinc-900">
                    <div class="flex-shrink-0 border-r border-zinc-400 dark:border-zinc-600 flex flex-col items-center justify-center"
                         style="width: {{ $timeColWidth }}px; height: 48px">
                        <span class="text-xs font-semibold text-zinc-400 dark:text-zinc-500 uppercase tracking-wide">v.</span>
                        <span class="text-sm font-bold text-zinc-500 dark:text-zinc-400">{{ $currentDate->isoWeek() }}</span>
                    </div>
                    <div class="flex-1 flex flex-col items-center justify-center px-2 overflow-hidden gap-0.5" style="height: 48px">
                        <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            {{ $currentDate->locale('sv')->isoFormat('dddd') }}
                        </span>
                        <span class="text-sm font-bold text-zinc-700 dark:text-zinc-200">{{ $currentDate->format('j/n Y') }}</span>
                    </div>
                </div>

                {{-- Kalenderyta --}}
                <div class="relative bg-white dark:bg-zinc-900" style="height: {{ $totalHeight }}px">

                    {{-- Timlinjer + etiketter --}}
                    <div class="absolute inset-0 pointer-events-none" style="z-index: 1">
                        @for ($h = $calendarStart; $h <= $calendarEnd; $h++)
                            <div class="absolute inset-x-0 flex items-start"
                                 style="top: {{ ($h - $calendarStart) * $pxPerHour }}px">
                                <div class="flex-shrink-0 text-right pr-2 text-xs text-zinc-400 dark:text-zinc-500 leading-none"
                                     style="width: {{ $timeColWidth }}px; margin-top: -7px">
                                    {{ sprintf('%02d:00', $h) }}
                                </div>
                                <div class="flex-1 border-t {{ $h === $calendarStart || $h === $calendarEnd ? 'border-zinc-400 dark:border-zinc-500' : 'border-zinc-300 dark:border-zinc-600' }}"></div>
                            </div>
                        @endfor
                        @for ($h = $calendarStart; $h < $calendarEnd; $h++)
                            <div class="absolute border-t border-dashed border-zinc-200 dark:border-zinc-700"
                                 style="top: {{ ($h - $calendarStart) * $pxPerHour + $pxPerHour / 2 }}px; left: {{ $timeColWidth }}px; right: 0"></div>
                        @endfor
                    </div>

                    {{-- Eventskolumn --}}
                    <div class="absolute inset-0 flex" style="z-index: 2">
                        <div class="flex-shrink-0 border-r border-zinc-400 dark:border-zinc-600" style="width: {{ $timeColWidth }}px"></div>
                        <div class="flex-1 relative min-w-0">
                            @foreach ($dayOccurrences as $occ)
                                @php
                                    $endH     = $occ->ends_at->hour;
                                    $endM     = $occ->ends_at->minute;
                                    $endHours = ($endH === 0 && $endM === 0 && ! $occ->ends_at->isSameDay($occ->starts_at)) ? 24 : $endH;
                                    $posStart = $occ->starts_at->isSameDay($currentDate)
                                        ? ($occ->starts_at->hour * 60 + $occ->starts_at->minute) : 0;
                                    $posEnd   = $occ->ends_at->isSameDay($currentDate)
                                        ? ($endHours * 60 + $endM) : 24 * 60;
                                    $startMin = max(0, min($posStart - $calendarStart * 60, ($calendarEnd - $calendarStart) * 60));
                                    $endMin   = max(0, min($posEnd   - $calendarStart * 60, ($calendarEnd - $calendarStart) * 60));
                                    $top      = round($startMin * $pxPerHour / 60);
                                    $height   = max(round(($endMin - $startMin) * $pxPerHour / 60), 18);
                                    $dispStart = sprintf('%02d:%02d', intdiv($posStart, 60), $posStart % 60);
                                    $dispEnd   = $endHours === 24 ? '24:00' : $occ->ends_at->format('H:i');
                                    $occTitle  = $occ->title ?: $occ->event->title;
                                @endphp
                                <div class="absolute inset-x-1 rounded overflow-hidden text-xs cursor-pointer"
                                     x-data="{
                                         open:false,pinned:false,px:0,py:0,
                                         hov:window.matchMedia('(hover:hover)').matches,
                                         pos(el){const r=el.getBoundingClientRect(),w=252;const fr=r.right+8+w<=window.innerWidth;this.px=fr?r.right+8:Math.max(4,r.left-w-8);this.py=Math.max(4,Math.min(r.top,window.innerHeight-280));}
                                     }"
                                     @mouseenter="if(hov){pos($el);open=true}"
                                     @mouseleave="if(hov&&!pinned)open=false"
                                     @click.stop="if(open&&pinned){open=false;pinned=false}else{pos($el);open=true;pinned=true}"
                                     @click.outside="open=false;pinned=false"
                                     style="top: {{ $top }}px; height: {{ $height }}px; z-index: 20; background-color: {{ $eventColorBg }}; border: 1.5px solid {{ $eventColor }};">
                                    <div class="px-1 pt-0.5 font-semibold leading-none" style="color: {{ $eventColor }}; font-size: 10px; white-space: nowrap">
                                        {{ $dispStart }}@if($height >= 22)–{{ $dispEnd }}@endif
                                    </div>
                                    @if ($height >= 34)
                                        <div class="px-1 truncate text-zinc-800 dark:text-zinc-100" style="font-size: 10px">{{ $occTitle }}</div>
                                    @endif

                                    <template x-teleport="body">
                                        <div x-show="open"
                                             :style="`position:fixed;left:${px}px;top:${py}px;z-index:9999;width:252px`"
                                             @mouseenter="if(hov)pinned=true"
                                             @mouseleave="if(hov){pinned=false;open=false}"
                                             @click.stop
                                             class="rounded-xl border border-zinc-200 dark:border-zinc-600 shadow-2xl p-4 pointer-events-auto bg-white dark:bg-zinc-950">
                                            <div class="text-zinc-900 dark:text-white font-semibold text-sm leading-snug mb-1">{{ $occTitle }}</div>
                                            <div class="text-zinc-500 dark:text-zinc-400 text-xs mb-0.5">{{ $occ->starts_at->locale('sv')->isoFormat('dddd D MMMM') }}</div>
                                            <div class="text-zinc-600 dark:text-zinc-300 text-xs mb-2">{{ $dispStart }}–{{ $dispEnd }}</div>
                                            @if ($occ->event->organizerSection)
                                                <div class="text-zinc-500 dark:text-zinc-400 text-xs mb-2">{{ $occ->event->organizerSection->name }}</div>
                                            @endif
                                            <a href="{{ route('events.show', $occ->event) }}" wire:navigate
                                               class="inline-block text-xs text-complement hover:underline dark:text-complement-light">
                                                Visa event →
                                            </a>
                                        </div>
                                    </template>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>

    {{-- ===== VECKOVY ===== --}}
    @else
        @php
            $wkStart = $calendarStart;
            $wkEnd   = $calendarEnd;
            $wkPx    = $pxPerHour;
            $wkH     = ($wkEnd - $wkStart) * $wkPx;
            $wkTimeW = $timeColWidth;
        @endphp

        @if ($weekDays->isEmpty())
            <p class="text-center text-sm text-zinc-400 py-10">Laddar…</p>
        @else
            <div class="overflow-x-auto">
                <div class="min-w-[560px] overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm">

                    {{-- Rubrikrad --}}
                    <div class="flex border-b border-zinc-300 dark:border-zinc-600 sticky top-14 z-30 bg-white dark:bg-zinc-900">
                        <div class="shrink-0 border-r border-zinc-400 dark:border-zinc-600 flex flex-col items-center justify-center"
                             style="width: {{ $wkTimeW }}px; height: 40px">
                            <span class="text-xs font-semibold text-zinc-400 dark:text-zinc-500 uppercase tracking-wide">v.</span>
                            <span class="text-sm font-bold text-zinc-500 dark:text-zinc-400">{{ $weekStart->isoWeek() }}</span>
                        </div>
                        @foreach ($weekDays as $day)
                            @php
                                $isDayToday = $day->isToday();
                                $isDayRed   = $day->isSunday() || in_array($day->format('Y-m-d'), $holidays);
                            @endphp
                            <div class="flex-1 flex flex-col items-center justify-center py-1 border-r border-zinc-400 dark:border-zinc-600 last:border-r-0 overflow-hidden" style="height: 40px">
                                <span class="text-xs font-semibold uppercase tracking-wide {{ $isDayToday ? 'text-brand-orange' : ($isDayRed ? 'text-red-500 dark:text-red-400' : 'text-zinc-400 dark:text-zinc-500') }}">
                                    {{ $day->locale('sv')->isoFormat('ddd') }}
                                </span>
                                <span class="text-sm font-bold {{ $isDayToday ? 'text-zinc-900 dark:text-white' : ($isDayRed ? 'text-red-600 dark:text-red-400' : 'text-zinc-700 dark:text-zinc-300') }}">
                                    {{ $day->format('j/n') }}
                                </span>
                            </div>
                        @endforeach
                    </div>

                    {{-- Grid-kropp --}}
                    <div class="relative flex bg-white dark:bg-zinc-900" style="height: {{ $wkH }}px">

                        {{-- Timlinjer --}}
                        <div class="absolute inset-0 pointer-events-none" style="z-index: 1">
                            @for ($h = $wkStart; $h <= $wkEnd; $h++)
                                <div class="absolute inset-x-0 flex items-start" style="top: {{ ($h - $wkStart) * $wkPx }}px">
                                    <div class="shrink-0 text-right pr-2 text-xs text-zinc-400 dark:text-zinc-500 leading-none"
                                         style="width: {{ $wkTimeW }}px; margin-top: -7px">
                                        {{ sprintf('%02d:00', $h) }}
                                    </div>
                                    <div class="flex-1 border-t {{ ($h === $wkStart || $h === $wkEnd) ? 'border-zinc-400 dark:border-zinc-500' : 'border-zinc-300 dark:border-zinc-600' }}"></div>
                                </div>
                            @endfor
                        </div>

                        <div class="shrink-0 border-r border-zinc-400 dark:border-zinc-600" style="width: {{ $wkTimeW }}px"></div>

                        {{-- Dagkolumner --}}
                        @foreach ($weekDays as $day)
                            @php
                                $dayKey         = $day->format('Y-m-d');
                                $dayOccs        = $weekData->get($dayKey, collect())->sortBy('starts_at')->values();
                                $isDayToday     = $day->isToday();
                                $isDayRed       = $day->isSunday() || in_array($dayKey, $holidays);

                                // Greedy lane assignment for overlap
                                $laneEnds    = [];
                                $occMeta     = [];
                                foreach ($dayOccs as $o) {
                                    $oStartMin = $o->starts_at->isSameDay($day)
                                        ? ($o->starts_at->hour * 60 + $o->starts_at->minute) : 0;
                                    $oEhRaw = $o->ends_at->hour;
                                    $oEmRaw = $o->ends_at->minute;
                                    $oEndMin = $o->ends_at->isSameDay($day)
                                        ? (($oEhRaw === 0 && $oEmRaw === 0 && ! $o->ends_at->isSameDay($o->starts_at)) ? 24 * 60 : $oEhRaw * 60 + $oEmRaw)
                                        : 24 * 60;
                                    $lane = 0;
                                    foreach ($laneEnds as $l => $lEnd) {
                                        if ($oStartMin >= $lEnd) { $lane = $l; break; }
                                    }
                                    if (! isset($laneEnds[$lane]) || $oStartMin < $laneEnds[$lane]) {
                                        $lane = count($laneEnds);
                                    }
                                    $laneEnds[$lane] = $oEndMin;
                                    $oTopPx = max(0, $oStartMin - $wkStart * 60) * $wkPx / 60;
                                    $oEndPx = min($oEndMin - $wkStart * 60, ($wkEnd - $wkStart) * 60) * $wkPx / 60;
                                    $occMeta[$o->id] = [
                                        'lane'      => $lane,
                                        'startMin'  => $oStartMin,
                                        'endMin'    => $oEndMin,
                                        'topPx'     => $oTopPx,
                                        'hPx'       => max($oEndPx - $oTopPx, 6),
                                        'dispStart' => $o->starts_at->isSameDay($day) ? $o->starts_at->format('H:i') : '00:00',
                                        'dispEnd'   => ($oEndMin === 24 * 60 && ! $o->ends_at->isSameDay($day)) ? '24:00' : (($oEhRaw === 0 && $oEmRaw === 0 && ! $o->ends_at->isSameDay($o->starts_at)) ? '00:00' : $o->ends_at->format('H:i')),
                                        'numCols'   => 1,
                                    ];
                                }
                                // BFS connected components for numCols
                                $visited = [];
                                foreach ($dayOccs as $o) {
                                    if (isset($visited[$o->id])) continue;
                                    $component = [];
                                    $queue = [$o->id];
                                    while (! empty($queue)) {
                                        $curr = array_shift($queue);
                                        if (isset($visited[$curr])) continue;
                                        $visited[$curr] = true;
                                        $component[] = $curr;
                                        $cm = $occMeta[$curr];
                                        foreach ($dayOccs as $other) {
                                            if (! isset($visited[$other->id]) && $cm['startMin'] < $occMeta[$other->id]['endMin'] && $cm['endMin'] > $occMeta[$other->id]['startMin']) {
                                                $queue[] = $other->id;
                                            }
                                        }
                                    }
                                    $maxLane = 0;
                                    foreach ($component as $id) $maxLane = max($maxLane, $occMeta[$id]['lane']);
                                    foreach ($component as $id) $occMeta[$id]['numCols'] = $maxLane + 1;
                                }
                            @endphp
                            <div class="flex-1 relative border-r border-zinc-400 dark:border-zinc-600 last:border-r-0 min-w-0"
                                 style="z-index: 2; {{ $isDayToday ? 'background-color: rgba(253,221,16,0.04)' : ($isDayRed ? 'background-color: rgba(239,68,68,0.03)' : '') }}">

                                @foreach ($dayOccs as $occ)
                                    @php
                                        $meta      = $occMeta[$occ->id];
                                        $topPx     = $meta['topPx'];
                                        $hPx       = $meta['hPx'];
                                        $widthPct  = 100 / $meta['numCols'];
                                        $leftPct   = $meta['lane'] * $widthPct;
                                        $dispStart = $meta['dispStart'];
                                        $dispEnd   = $meta['dispEnd'];
                                        $occTitle  = $occ->title ?: $occ->event->title;
                                    @endphp
                                    <div class="absolute rounded overflow-hidden cursor-pointer"
                                         x-data="{
                                             open:false,pinned:false,px:0,py:0,
                                             hov:window.matchMedia('(hover:hover)').matches,
                                             pos(el){const r=el.getBoundingClientRect(),w=252;const fr=r.right+8+w<=window.innerWidth;this.px=fr?r.right+8:Math.max(4,r.left-w-8);this.py=Math.max(4,Math.min(r.top,window.innerHeight-280));}
                                         }"
                                         @mouseenter="if(hov&&!document.body.classList.contains('cal-dragging')){pos($el);open=true}"
                                         @mouseleave="if(hov&&!pinned)open=false"
                                         @click.stop="if(open&&pinned){open=false;pinned=false}else{pos($el);open=true;pinned=true}"
                                         @click.outside="open=false;pinned=false"
                                         style="top: {{ $topPx }}px; height: {{ $hPx }}px; z-index: 20;
                                                left: calc({{ $leftPct }}% + 1px); width: calc({{ $widthPct }}% - 2px);
                                                background-color: {{ $eventColorBg }}; border: 1.5px solid {{ $eventColor }}">
                                        @if ($hPx >= 10)
                                            <div class="flex items-center overflow-hidden" style="padding: 2px 2px 0; gap: 2px; color: {{ $eventColor }}; font-size: 9px; white-space: nowrap">
                                                {{ $dispStart }}@if($hPx >= 22)–{{ $dispEnd }}@endif
                                            </div>
                                        @endif
                                        @if ($hPx >= 34)
                                            <p class="leading-tight truncate text-zinc-800 dark:text-zinc-100" style="font-size:9px; padding:0 3px">{{ $occTitle }}</p>
                                        @endif

                                        <template x-teleport="body">
                                            <div x-show="open"
                                                 :style="`position:fixed;left:${px}px;top:${py}px;z-index:9999;width:252px`"
                                                 @mouseenter="if(hov)pinned=true"
                                                 @mouseleave="if(hov){pinned=false;open=false}"
                                                 @click.stop
                                                 class="rounded-xl border border-zinc-200 dark:border-zinc-600 shadow-2xl p-4 pointer-events-auto bg-white dark:bg-zinc-950">
                                                <div class="text-zinc-900 dark:text-white font-semibold text-sm leading-snug mb-1">{{ $occTitle }}</div>
                                                <div class="text-zinc-500 dark:text-zinc-400 text-xs mb-0.5">{{ $occ->starts_at->locale('sv')->isoFormat('dddd D MMMM') }}</div>
                                                <div class="text-zinc-600 dark:text-zinc-300 text-xs mb-2">{{ $dispStart }}–{{ $dispEnd }}</div>
                                                @if ($occ->event->organizerSection)
                                                    <div class="text-zinc-500 dark:text-zinc-400 text-xs mb-2">{{ $occ->event->organizerSection->name }}</div>
                                                @endif
                                                <a href="{{ route('events.show', $occ->event) }}" wire:navigate
                                                   class="inline-block text-xs text-complement hover:underline dark:text-complement-light">
                                                    Visa event →
                                                </a>
                                            </div>
                                        </template>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    @endif

    @script
    (function () {
        const k = 'membrum_event_cal';
        const s = JSON.parse(sessionStorage.getItem(k) || '{}');
        if (s.date || s.view) {
            $wire.restoreState?.(s.date || '', s.view || 'week');
        }
        $wire.$watch('date', v => { const d = JSON.parse(sessionStorage.getItem(k) || '{}'); d.date = v; sessionStorage.setItem(k, JSON.stringify(d)); });
        $wire.$watch('view', v => { const d = JSON.parse(sessionStorage.getItem(k) || '{}'); d.view = v; sessionStorage.setItem(k, JSON.stringify(d)); });
    })();
    @endscript
</div>
