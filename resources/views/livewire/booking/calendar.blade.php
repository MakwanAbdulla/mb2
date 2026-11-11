@php
    $calendarStart = 8;
    $calendarEnd   = 24;
    $pxPerHour     = 45;
    $totalHeight   = ($calendarEnd - $calendarStart) * $pxPerHour;
    $timeColWidth  = 52;
@endphp

<div class="px-4 py-6 sm:px-6 space-y-4">

    {{-- ── NAV ROW ─────────────────────────────────────────────── --}}
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
                            wire:loading.attr="disabled"
                            @disabled(! $canGoPrevWeek)
                            class="inline-flex items-center rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-3 py-1.5 text-sm text-zinc-600 dark:text-zinc-300 {{ $canGoPrevWeek ? 'hover:bg-zinc-50 dark:hover:bg-zinc-700' : 'opacity-30 cursor-not-allowed' }}">
                        <x-bootstrap-icon name="chevron-left" class="h-4 w-4" />
                    </button>
                    <input type="date"
                           wire:model.live="date"
                           wire:loading.attr="disabled"
                           title="Hoppa till datum"
                           @if ($navMinDate) min="{{ $navMinDate }}" @endif
                           @if ($navMaxDate) max="{{ $navMaxDate }}" @endif
                           class="rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm py-1.5 px-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron">
                    <button wire:click="nextWeek"
                            wire:loading.attr="disabled"
                            @disabled(! $canGoNextWeek)
                            class="inline-flex items-center rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-3 py-1.5 text-sm text-zinc-600 dark:text-zinc-300 {{ $canGoNextWeek ? 'hover:bg-zinc-50 dark:hover:bg-zinc-700' : 'opacity-30 cursor-not-allowed' }}">
                        <x-bootstrap-icon name="chevron-right" class="h-4 w-4" />
                    </button>
                    @if (! $isCurrentWeek)
                        <button wire:click="goToToday"
                                wire:loading.attr="disabled"
                                class="rounded-lg bg-brand border border-brand px-3 py-1.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                            Denna vecka
                        </button>
                    @endif
                </div>
            @else
                <div class="flex items-center gap-2">
                    <button wire:click="previousDay"
                            wire:loading.attr="disabled"
                            @disabled(! $canGoPrevDay)
                            class="inline-flex items-center rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-3 py-1.5 text-sm text-zinc-600 dark:text-zinc-300 {{ $canGoPrevDay ? 'hover:bg-zinc-50 dark:hover:bg-zinc-700' : 'opacity-30 cursor-not-allowed' }}">
                        <x-bootstrap-icon name="chevron-left" class="h-4 w-4" />
                    </button>
                    <input type="date"
                           wire:model.live="date"
                           wire:loading.attr="disabled"
                           @if ($navMinDate) min="{{ $navMinDate }}" @endif
                           @if ($navMaxDate) max="{{ $navMaxDate }}" @endif
                           class="rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm py-1.5 px-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron">
                    <button wire:click="nextDay"
                            wire:loading.attr="disabled"
                            @disabled(! $canGoNextDay)
                            class="inline-flex items-center rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-3 py-1.5 text-sm text-zinc-600 dark:text-zinc-300 {{ $canGoNextDay ? 'hover:bg-zinc-50 dark:hover:bg-zinc-700' : 'opacity-30 cursor-not-allowed' }}">
                        <x-bootstrap-icon name="chevron-right" class="h-4 w-4" />
                    </button>
                    @if (! $isToday)
                        <button wire:click="goToToday"
                                wire:loading.attr="disabled"
                                class="rounded-lg bg-brand border border-brand px-3 py-1.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                            Idag
                        </button>
                    @endif
                </div>
            @endif

        </div>

        {{-- Rumfilter + Boka (högerjusterat) --}}
        <div class="flex items-center gap-3 flex-wrap">

            {{-- Rumfilter: samma segmenterade stil som vy-växlaren --}}
            @if ($allRooms->isNotEmpty())
                <div class="flex rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-600 shrink-0 flex-wrap">
                    @php $noneHidden = count($hiddenRooms) === 0; @endphp
                    <button wire:click="toggleAllRooms"
                            class="px-3 py-1.5 text-sm font-medium whitespace-nowrap transition-colors
                                   {{ $noneHidden ? 'bg-zinc-200 dark:bg-zinc-600 text-zinc-800 dark:text-white' : 'bg-white dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-700' }}">
                        Alla
                    </button>
                    @foreach ($allRooms as $room)
                        @php
                            $isHidden = in_array($room->id, $hiddenRooms, true);
                            $fColor   = $roomColors->get($room->id, '#818cf8');
                            $fShape   = $roomShapes->get($room->id, '<circle cx="6" cy="6" r="4.5"/>');
                        @endphp
                        <button wire:click="toggleRoom({{ $room->id }})"
                                title="{{ $isHidden ? 'Visa' : 'Dölj' }} {{ $room->name }}"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium border-l border-zinc-200 dark:border-zinc-600 whitespace-nowrap transition-all"
                                style="{{ $isHidden ? '' : 'background-color:'.$fColor.'22;color:'.$fColor.';' }}{{ $isHidden ? 'color:#9ca3af' : '' }}">
                            <svg viewBox="0 0 12 12" width="10" height="10" aria-hidden="true" class="shrink-0"
                                 style="fill: {{ $isHidden ? 'transparent' : $fColor }}; stroke: {{ $fColor }}; stroke-width: {{ $isHidden ? '1.5' : '0' }}">{!! $fShape !!}</svg>
                            <span @class(['line-through opacity-50' => $isHidden])>{{ $room->name }}</span>
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- + Boka --}}
            @auth
                @if (auth()->user()->isMember() || auth()->user()->isAdmin())
                    <a href="{{ route('booking.create') }}" wire:navigate
                       class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors shrink-0">
                        + Boka
                    </a>
                @endif
            @endauth

        </div>
    </div>

    {{-- ===== LISTVY ===== --}}
    @if ($view === 'list')
        @if ($listBookings->isEmpty())
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-10 text-center text-zinc-500 dark:text-zinc-400">
                Inga bokningar denna dag
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                @foreach ($listBookings as $booking)
                    @php
                        $lAccent = $roomColors->get($booking->resource_id, '#818cf8');
                        $lShape  = $roomShapes->get($booking->resource_id, '<circle cx="6" cy="6" r="4.5"/>');
                        $lEndH   = $booking->ends_at->hour;
                        $lEndM   = $booking->ends_at->minute;
                        $lEndStr = ($lEndH === 0 && $lEndM === 0 && ! $booking->ends_at->isSameDay($booking->starts_at))
                            ? '24:00'
                            : $booking->ends_at->format('H:i');
                        $lIsOpen = $booking->booking_type === 'open';
                    @endphp
                    <div class="flex items-center gap-3 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/40 sm:gap-4">
                        <div class="shrink-0 w-24 text-sm tabular-nums text-zinc-700 dark:text-zinc-300 font-medium">
                            {{ $booking->starts_at->format('H:i') }}–{{ $lEndStr }}
                        </div>
                        <div class="shrink-0 flex items-center gap-1.5 w-28 min-w-0">
                            <svg viewBox="0 0 12 12" width="10" height="10" class="shrink-0"
                                 style="fill: {{ $lAccent }}">{!! $lShape !!}</svg>
                            <span class="text-xs font-medium truncate" style="color: {{ $lAccent }}">
                                {{ $booking->resource?->name }}
                            </span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-zinc-900 dark:text-white font-medium truncate">{{ $booking->title }}</p>
                            @php
                                $lSub = Auth::check() && $booking->user ? $booking->user->name : '';
                                if ($booking->activity) $lSub .= ($lSub ? ' · ' : '') . $booking->activity;
                            @endphp
                            @if ($lSub)<p class="text-xs text-zinc-500 dark:text-zinc-400 truncate">{{ $lSub }}</p>@endif
                        </div>
                        @if ($lIsOpen)
                            <span class="shrink-0 hidden sm:inline-flex px-2 py-0.5 rounded-full text-xs bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-700/50">Öppet</span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

    {{-- ===== DAGVY ===== --}}
    @elseif ($view === 'day')
        @if ($rooms->isEmpty())
            <p class="text-center text-sm text-zinc-400 py-10">Alla rum är dolda.</p>
        @else
            <div class="overflow-x-auto">
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm"
                 style="min-width: {{ $rooms->count() * 110 + $timeColWidth }}px">

                {{-- Sticky rubrikrad: veckohörn + rumsnamn --}}
                <div class="flex border-b border-zinc-300 dark:border-zinc-600 sticky top-14 z-30 bg-white dark:bg-zinc-900">
                    <div class="flex-shrink-0 border-r border-zinc-400 dark:border-zinc-600 flex flex-col items-center justify-center"
                         style="width: {{ $timeColWidth }}px; height: 48px">
                        <span class="text-xs font-semibold text-zinc-400 dark:text-zinc-500 uppercase tracking-wide">v.</span>
                        <span class="text-sm font-bold text-zinc-500 dark:text-zinc-400">{{ $currentDate->isoWeek() }}</span>
                    </div>
                    @foreach ($rooms as $room)
                        @php
                            $hdrColor = $roomColors->get($room->id, '#818cf8');
                            $hdrShape = $roomShapes->get($room->id, '<circle cx="6" cy="6" r="4.5"/>');
                        @endphp
                        <div class="flex-1 flex flex-col items-center justify-center px-2 border-r border-zinc-400 dark:border-zinc-600 last:border-r-0 overflow-hidden gap-0.5"
                             style="height: 48px">
                            <svg viewBox="0 0 12 12" width="12" height="12" aria-hidden="true"
                                 style="fill: {{ $hdrColor }}; flex-shrink: 0">{!! $hdrShape !!}</svg>
                            @auth
                                <a href="{{ route('booking.create') }}?rum={{ $room->slug }}"
                                   wire:navigate
                                   class="text-xs font-semibold hover:underline text-center leading-tight"
                                   style="color: {{ $hdrColor }}">{{ $room->name }}</a>
                            @else
                                <span class="text-xs font-semibold text-center leading-tight"
                                      style="color: {{ $hdrColor }}">{{ $room->name }}</span>
                            @endauth
                        </div>
                    @endforeach
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

                        {{-- Halvtimmarslinjer --}}
                        @for ($h = $calendarStart; $h < $calendarEnd; $h++)
                            <div class="absolute border-t border-dashed border-zinc-200 dark:border-zinc-700"
                                 style="top: {{ ($h - $calendarStart) * $pxPerHour + $pxPerHour / 2 }}px; left: {{ $timeColWidth }}px; right: 0"></div>
                        @endfor
                    </div>

                    {{-- Rumskolumner --}}
                    <div class="absolute inset-0 flex" style="z-index: 2">
                        <div class="flex-shrink-0 border-r border-zinc-400 dark:border-zinc-600"
                             style="width: {{ $timeColWidth }}px"></div>

                        @foreach ($rooms as $room)
                            @php
                                $daySlug   = $room->slug;
                                $dayDate   = $currentDate->format('Y-m-d');
                                $dayIsPast = $currentDate->copy()->startOfDay()->lt(today())
                                          || $currentDate->isToday() && ! $allowSameDayBooking;
                            @endphp
                            <div wire:key="daycol-{{ $room->id }}"
                                 class="flex-1 relative border-r border-zinc-400 dark:border-zinc-600 last:border-r-0 min-w-0"
                                 x-data="{
                                     dragging:false,startY:0,curY:0,popup:false,startT:'',endT:'',px:0,py:0,
                                     dragStart(clientY){this.startY=Math.max(0,clientY-this.$el.getBoundingClientRect().top);this.curY=this.startY;this.dragging=true;this.popup=false;document.body.classList.add('cal-dragging');},
                                     dragMove(clientY){if(!this.dragging)return;this.curY=Math.max(0,Math.min({{ $totalHeight }},clientY-this.$el.getBoundingClientRect().top));},
                                     finish(){document.body.classList.remove('cal-dragging');if(this.dragging&&Math.abs(this.curY-this.startY)>12){const _px={{ $pxPerHour }},_st={{ $calendarStart*60 }},_en={{ $calendarEnd*60 }};const sM=Math.max(_st,Math.min(_en,Math.round((Math.min(this.startY,this.curY)/_px*60+_st)/30)*30)),eM=Math.max(_st,Math.min(_en,Math.round((Math.max(this.startY,this.curY)/_px*60+_st)/30)*30));if(sM<eM){const fmt=m=>String(Math.floor(m/60)).padStart(2,'0')+':'+String(m%60).padStart(2,'0');this.startT=sM>=_en?'24:00':fmt(sM);this.endT=eM>=_en?'24:00':fmt(eM);const r=this.$el.getBoundingClientRect(),w=232,h=120;this.px=Math.min(Math.max(8,r.left+r.width/2-w/2),window.innerWidth-w-8);this.py=Math.min(Math.max(8,r.top+Math.min(this.startY,this.curY)+Math.abs(this.curY-this.startY)/2-h/2),window.innerHeight-h-8);this.popup=true;}}this.dragging=false;}
                                 }"
                                 @mousedown="if({{ (!Auth::check() || $dayIsPast) ? 'true' : 'false' }}||$event.button!==0||$event.target.closest('[data-booking]'))return; $event.preventDefault(); dragStart($event.clientY)"
                                 @mousemove.window="dragMove($event.clientY)"
                                 @mouseup.window="finish()"
                                 @touchstart="if({{ (!Auth::check() || $dayIsPast) ? 'true' : 'false' }}||!hov||$event.target.closest('[data-booking]'))return; $event.preventDefault(); dragStart($event.touches[0].clientY)"
                                 @touchmove="if(dragging){$event.preventDefault();dragMove($event.touches[0].clientY)}"
                                 @touchend="finish()"
                                 :class="dragging?'select-none cursor-ns-resize':'{{ $dayIsPast ? 'cursor-default' : '' }}'">

                                {{-- Drag-markering --}}
                                <div x-show="dragging && Math.abs(curY-startY) > 12"
                                     class="absolute inset-x-px pointer-events-none rounded"
                                     style="z-index:25"
                                     :style="`top:${Math.min(startY,curY)}px;height:${Math.abs(curY-startY)}px;background:rgba(99,102,241,0.35);border:2px solid rgb(99,102,241)`">
                                </div>

                                {{-- Bokningspopup --}}
                                <template x-teleport="body">
                                    <div x-show="popup"
                                         x-cloak
                                         x-transition:enter="transition ease-out duration-150"
                                         x-transition:enter-start="opacity-0 translate-y-1 scale-95"
                                         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                                         x-transition:leave="transition ease-in duration-100"
                                         x-transition:leave-start="opacity-100 scale-100"
                                         x-transition:leave-end="opacity-0 scale-95"
                                         :style="`position:fixed;z-index:10000;left:${px}px;top:${py}px`"
                                         class="w-56 rounded-2xl border border-zinc-200 dark:border-zinc-700 shadow-2xl bg-white dark:bg-zinc-950">
                                        <div class="flex items-start justify-between px-4 pt-4 pb-2">
                                            <div>
                                                <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-0.5">Ny bokning</p>
                                                <p class="text-sm font-semibold text-zinc-900 dark:text-white" x-text="startT + ' – ' + endT"></p>
                                            </div>
                                            <button @click="popup=false" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors mt-0.5">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </div>
                                        @auth
                                        <div class="px-4 pb-4">
                                            <a :href="`{{ route('booking.create') }}?rum={{ $daySlug }}&datum={{ $dayDate }}&start=${startT}&slut=${endT}`"
                                               class="flex items-center justify-center gap-1.5 w-full rounded-xl bg-brand hover:bg-brand-saffron text-brand-dark text-sm font-semibold py-2 transition-colors">
                                                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                                Boka {{ $room->name }}
                                            </a>
                                        </div>
                                        @endauth
                                    </div>
                                </template>

                                {{-- Bokningar --}}
                                @foreach ($bookingsByRoom->get($room->id, collect()) as $booking)
                                    @php
                                        $endH      = $booking->ends_at->hour;
                                        $endM      = $booking->ends_at->minute;
                                        $endHours  = ($endH === 0 && $endM === 0 && ! $booking->ends_at->isSameDay($booking->starts_at))
                                            ? 24 : $endH;
                                        $posStartAbs = $booking->starts_at->isSameDay($currentDate)
                                            ? ($booking->starts_at->hour * 60 + $booking->starts_at->minute) : 0;
                                        $posEndAbs = $booking->ends_at->isSameDay($currentDate)
                                            ? ($endHours * 60 + $endM) : 24 * 60;
                                        $startMin  = max(0, min($posStartAbs - $calendarStart * 60, ($calendarEnd - $calendarStart) * 60));
                                        $endMin    = max(0, min($posEndAbs   - $calendarStart * 60, ($calendarEnd - $calendarStart) * 60));
                                        $dispStart = sprintf('%02d:%02d', intdiv($posStartAbs, 60), $posStartAbs % 60);
                                        $dispEnd   = ($posEndAbs === 24 * 60 && ! $booking->ends_at->isSameDay($currentDate))
                                            ? '24:00'
                                            : ($endHours === 24 ? '00:00' : $booking->ends_at->format('H:i'));
                                        $top       = round($startMin * $pxPerHour / 60);
                                        $height    = max(round(($endMin - $startMin) * $pxPerHour / 60), 18);
                                        $isPending      = $booking->status === 'pending';
                                        $isOpen         = $booking->booking_type === 'open';
                                        $isEventBooking   = $booking->source_type === \App\Models\Events\EventOccurrence::class;
                                        $isMeetingBooking = $booking->source_type === \App\Models\Meeting\Meeting::class;
                                        $accent         = $roomColors->get($room->id, '#818cf8');
                                        $bShape         = $roomShapes->get($room->id, '<circle cx="6" cy="6" r="4.5"/>');
                                    @endphp
                                    <div class="absolute inset-x-1 rounded overflow-hidden text-xs cursor-pointer"
                                         data-booking
                                         x-data="{
                                             open:false,pinned:false,px:0,py:0,left:false,
                                             hov:window.matchMedia('(hover:hover)').matches,
                                             pos(el){const r=el.getBoundingClientRect(),w=252;const fr=r.right+8+w<=window.innerWidth;this.left=!fr;this.px=fr?r.right+8:Math.max(4,r.left-w-8);this.py=Math.max(4,Math.min(r.top,window.innerHeight-320));}
                                         }"
                                         @mouseenter="if(hov&&!document.body.classList.contains('cal-dragging')){pos($el);open=true}"
                                         @mouseleave="if(hov&&!pinned)open=false"
                                         @click.stop="if(open&&pinned){open=false;pinned=false}else{pos($el);open=true;pinned=true}"
                                         @click.outside="open=false;pinned=false"
                                         style="top: {{ $top }}px; height: {{ $height }}px; z-index: 20;
                                                background-color: {{ $accent }}30; border: 1.5px solid {{ $accent }};">
                                        <div class="px-1 pt-0.5 font-semibold leading-none flex items-center gap-0.5">
                                            <svg viewBox="0 0 12 12" width="8" height="8" aria-hidden="true"
                                                 style="fill: {{ $accent }}; flex-shrink: 0">{!! $bShape !!}</svg>
                                            <span class="text-zinc-800 dark:text-zinc-100 truncate" style="font-size:10px; white-space:nowrap">{{ $dispStart }}</span>
                                            @if ($isPending)<span class="text-amber-500 font-bold" style="font-size:10px"> ·</span>@endif
                                        </div>
                                        @if ($height >= 22)
                                            <div class="text-zinc-600 dark:text-zinc-300" style="font-size:10px; padding-left:12px; white-space:nowrap">
                                                –{{ $dispEnd }}@if ($isOpen)<span class="opacity-70"> öppet</span>@endif
                                            </div>
                                        @endif
                                        @if ($height >= 34)
                                            <div class="px-1 truncate text-zinc-800 dark:text-zinc-100" style="font-size:10px">
                                                {{ $booking->title }}
                                            </div>
                                        @endif
                                        @auth
                                            @if ($height >= 50)
                                                <div class="px-1 truncate text-zinc-500 dark:text-zinc-300" style="font-size:10px">
                                                    {{ $booking->user?->name }}
                                                </div>
                                            @endif
                                        @endauth
                                        @if ($height >= 65)
                                            <div class="px-1 truncate text-zinc-400 dark:text-zinc-400" style="font-size:10px">
                                                {{ $booking->activity }}
                                            </div>
                                        @endif

                                        {{-- Tooltip --}}
                                        <template x-teleport="body">
                                            <div x-show="open"
                                                 :style="`position:fixed;left:${px}px;top:${py}px;z-index:9999;width:252px`"
                                                 @mouseenter="if(hov)pinned=true"
                                                 @mouseleave="if(hov){pinned=false;open=false}"
                                                 @click.stop
                                                 class="rounded-xl border border-zinc-200 dark:border-zinc-600 shadow-2xl p-4 pointer-events-auto bg-white dark:bg-zinc-950">
                                                <div class="flex items-center justify-between gap-2 mb-2.5">
                                                    <div class="flex items-center gap-1.5 min-w-0">
                                                        <svg viewBox="0 0 12 12" width="12" height="12" aria-hidden="true"
                                                             style="fill:{{ $accent }};flex-shrink:0">{!! $bShape !!}</svg>
                                                        <span class="text-xs font-semibold truncate" style="color:{{ $accent }}">{{ $room->name }}</span>
                                                    </div>
                                                    <div class="flex shrink-0 items-center gap-0.5">
                                                        @can('bookings.manage')
                                                            @if ($booking->source_type === \App\Models\Booking\RecurringBooking::class)
                                                                <a href="{{ route('booking.admin.recurring-bookings.edit') }}?redigera={{ $booking->source_id }}" wire:navigate
                                                                   title="Redigera återkommande bokning"
                                                                   class="p-1 text-zinc-400 transition-colors hover:text-zinc-600 dark:hover:text-zinc-200">
                                                                    <x-bootstrap-icon name="pencil" class="size-3.5" />
                                                                </a>
                                                            @else
                                                                <a href="{{ route('booking.admin.bookings.edit', $booking) }}" wire:navigate
                                                                   title="Redigera bokning"
                                                                   class="p-1 text-zinc-400 transition-colors hover:text-zinc-600 dark:hover:text-zinc-200">
                                                                    <x-bootstrap-icon name="pencil" class="size-3.5" />
                                                                </a>
                                                            @endif
                                                        @endcan
                                                        <button @click.stop="open=false;pinned=false"
                                                                class="p-1 text-zinc-400 transition-colors hover:text-zinc-600 dark:hover:text-zinc-200">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                        </button>
                                                    </div>
                                                </div>
                                                <div class="text-zinc-900 dark:text-white font-semibold text-sm leading-snug mb-2.5">{{ $booking->title }}</div>
                                                <div class="text-zinc-500 dark:text-zinc-400 text-xs mb-0.5">{{ $booking->starts_at->locale('sv')->isoFormat('dddd D MMMM') }}</div>
                                                <div class="text-zinc-600 dark:text-zinc-300 text-xs mb-2">{{ $booking->starts_at->format('H:i') }}–{{ $endHours === 24 ? '00:00' : $booking->ends_at->format('H:i') }}</div>
                                                @auth
                                                    @if ($booking->user)<div class="text-zinc-500 dark:text-zinc-400 text-xs mb-1">{{ $booking->user->name }}</div>@endif
                                                @endauth
                                                @if ($booking->activity)<div class="text-zinc-500 dark:text-zinc-400 text-xs mb-1">{{ $booking->activity }}</div>@endif
                                                @if ($booking->participation_info)<div class="text-zinc-600 dark:text-zinc-300 text-xs mb-2 italic">{{ $booking->participation_info }}</div>@endif
                                                <div class="flex gap-1.5 flex-wrap">
                                                    @if ($isEventBooking)
                                                        <span class="flex items-center gap-1 rounded-full border border-complement/20 bg-complement/10 px-2 py-0.5 text-xs text-complement dark:text-complement-light">
                                                            <x-bootstrap-icon name="calendar-event" class="size-3" /> Evenemang
                                                        </span>
                                                    @elseif ($isMeetingBooking)
                                                        <span class="flex items-center gap-1 rounded-full border border-complement/20 bg-complement/10 px-2 py-0.5 text-xs text-complement dark:text-complement-light">
                                                            <x-bootstrap-icon name="people" class="size-3" /> Möte
                                                        </span>
                                                    @elseif ($booking->source_type === \App\Models\Booking\RecurringBooking::class)
                                                        {{-- handled below --}}
                                                    @else
                                                        <span class="flex items-center gap-1 rounded-full border border-zinc-200 bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                                            <x-bootstrap-icon name="calendar3" class="size-3" /> Bokning
                                                        </span>
                                                    @endif
                                                    @if ($booking->source_type === \App\Models\Booking\RecurringBooking::class)
                                                        <span class="flex items-center gap-1 rounded-full border border-zinc-200 bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                                            <x-bootstrap-icon name="arrow-repeat" class="size-3" /> Återkommande
                                                        </span>
                                                    @endif
                                                    @if ($isOpen)<span class="px-2 py-0.5 rounded-full text-xs bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-700/50">Öppet</span>@endif
                                                    @if ($isPending)<span class="px-2 py-0.5 rounded-full text-xs bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-700/50">Väntar</span>@endif
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                @endforeach

                            </div>
                        @endforeach
                    </div>

                </div>{{-- /kalenderyta --}}
            </div>
            </div>{{-- /overflow-x-auto --}}
        @endif

    {{-- ===== VECKOVY ===== --}}
    @else
        @php
            $wkStart = $calendarStart;
            $wkEnd   = $calendarEnd;
            $wkPx    = $pxPerHour;
            $wkH     = ($wkEnd - $wkStart) * $wkPx;
            $wkTimeW = $timeColWidth;
        @endphp

        @if ($rooms->isEmpty())
            <p class="text-center text-sm text-zinc-400 py-10">Alla rum är dolda.</p>
        @elseif ($weekDays->isEmpty())
            <p class="text-center text-sm text-zinc-400 py-10">Laddar…</p>
        @else
            <div class="overflow-x-auto">
                <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm" style="min-width: {{ $wkTimeW + 7 * 48 }}px">

                    {{-- Rubrikrad: veckohörn + veckodagar --}}
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
                            <div wire:key="weekhead-{{ $day->format('Y-m-d') }}"
                                 class="flex-1 flex flex-col items-center justify-center py-1 border-r border-zinc-400 dark:border-zinc-600 last:border-r-0 overflow-hidden"
                                 style="height: 40px">
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

                        {{-- Timlinjer + etiketter --}}
                        <div class="absolute inset-0 pointer-events-none" style="z-index: 1">
                            @for ($h = $wkStart; $h <= $wkEnd; $h++)
                                <div class="absolute inset-x-0 flex items-start"
                                     style="top: {{ ($h - $wkStart) * $wkPx }}px">
                                    <div class="shrink-0 text-right pr-2 text-xs text-zinc-400 dark:text-zinc-500 leading-none"
                                         style="width: {{ $wkTimeW }}px; margin-top: -7px">
                                        {{ sprintf('%02d:00', $h) }}
                                    </div>
                                    <div class="flex-1 border-t {{ ($h === $wkStart || $h === $wkEnd) ? 'border-zinc-400 dark:border-zinc-500' : 'border-zinc-300 dark:border-zinc-600' }}"></div>
                                </div>
                            @endfor
                        </div>

                        {{-- Tidskolumnbakgrund --}}
                        <div class="shrink-0 border-r border-zinc-400 dark:border-zinc-600"
                             style="width: {{ $wkTimeW }}px"></div>

                        {{-- Dagkolumner --}}
                        @foreach ($weekDays as $day)
                            @php
                                $dayKey      = $day->format('Y-m-d');
                                $dayBookings = $weekData->get($dayKey, collect())->sortBy('starts_at')->values();
                                $isDayToday  = $day->isToday();
                                $isDayRed    = $day->isSunday() || in_array($dayKey, $holidays);

                                // Greedy lane assignment
                                $laneEnds    = [];
                                $bookingMeta = [];

                                foreach ($dayBookings as $b) {
                                    $bStartMin = $b->starts_at->isSameDay($day)
                                        ? ($b->starts_at->hour * 60 + $b->starts_at->minute) : 0;
                                    $bEhRaw = $b->ends_at->hour;
                                    $bEmRaw = $b->ends_at->minute;
                                    $bEndMin = $b->ends_at->isSameDay($day)
                                        ? (($bEhRaw === 0 && $bEmRaw === 0 && ! $b->ends_at->isSameDay($b->starts_at)) ? 24 * 60 : $bEhRaw * 60 + $bEmRaw)
                                        : 24 * 60;
                                    $bDispStart = $b->starts_at->isSameDay($day) ? $b->starts_at->format('H:i') : '00:00';
                                    $bDispEnd   = ($bEndMin === 24 * 60 && ! $b->ends_at->isSameDay($day))
                                        ? '24:00'
                                        : (($bEhRaw === 0 && $bEmRaw === 0 && ! $b->ends_at->isSameDay($b->starts_at)) ? '00:00' : $b->ends_at->format('H:i'));

                                    $lane = 0;
                                    foreach ($laneEnds as $l => $lEnd) {
                                        if ($bStartMin >= $lEnd) { $lane = $l; break; }
                                    }
                                    if (! isset($laneEnds[$lane]) || $bStartMin < $laneEnds[$lane]) {
                                        $lane = count($laneEnds);
                                    }
                                    $laneEnds[$lane] = $bEndMin;

                                    $bTopPx = max(0, $bStartMin - $wkStart * 60) * $wkPx / 60;
                                    $bEndPx = min($bEndMin - $wkStart * 60, ($wkEnd - $wkStart) * 60) * $wkPx / 60;
                                    $bookingMeta[$b->id] = [
                                        'lane'      => $lane,
                                        'startMin'  => $bStartMin,
                                        'endMin'    => $bEndMin,
                                        'topPx'     => $bTopPx,
                                        'hPx'       => max($bEndPx - $bTopPx, 6),
                                        'eh'        => $bEndMin === 24 * 60 ? 24 : $bEhRaw,
                                        'dispStart' => $bDispStart,
                                        'dispEnd'   => $bDispEnd,
                                        'numCols'   => 1,
                                    ];
                                }

                                // Second pass: BFS connected components for numCols
                                $visited = [];
                                foreach ($dayBookings as $b) {
                                    if (isset($visited[$b->id])) continue;
                                    $component = [];
                                    $queue = [$b->id];
                                    while (! empty($queue)) {
                                        $curr = array_shift($queue);
                                        if (isset($visited[$curr])) continue;
                                        $visited[$curr] = true;
                                        $component[] = $curr;
                                        $cm = $bookingMeta[$curr];
                                        foreach ($dayBookings as $other) {
                                            if (! isset($visited[$other->id])) {
                                                $om = $bookingMeta[$other->id];
                                                if ($cm['startMin'] < $om['endMin'] && $cm['endMin'] > $om['startMin']) {
                                                    $queue[] = $other->id;
                                                }
                                            }
                                        }
                                    }
                                    $maxLane = 0;
                                    foreach ($component as $id) $maxLane = max($maxLane, $bookingMeta[$id]['lane']);
                                    foreach ($component as $id) $bookingMeta[$id]['numCols'] = $maxLane + 1;
                                }

                                $dayIsPast = $day->copy()->startOfDay()->lt(today())
                                          || $day->isToday() && ! $allowSameDayBooking;
                            @endphp
                            <div wire:key="weekcol-{{ $dayKey }}"
                                 class="flex-1 relative border-r border-zinc-400 dark:border-zinc-600 last:border-r-0 min-w-0"
                                 x-data="{
                                     dragging:false,startY:0,curY:0,popup:false,startT:'',endT:'',px:0,py:0,
                                     dragStart(clientY){this.startY=Math.max(0,clientY-this.$el.getBoundingClientRect().top);this.curY=this.startY;this.dragging=true;this.popup=false;document.body.classList.add('cal-dragging');},
                                     dragMove(clientY){if(!this.dragging)return;this.curY=Math.max(0,Math.min({{ $wkH }},clientY-this.$el.getBoundingClientRect().top));},
                                     finish(){document.body.classList.remove('cal-dragging');if(this.dragging&&Math.abs(this.curY-this.startY)>12){const _px={{ $wkPx }},_st={{ $wkStart*60 }},_en={{ $wkEnd*60 }};const sM=Math.max(_st,Math.min(_en,Math.round((Math.min(this.startY,this.curY)/_px*60+_st)/30)*30)),eM=Math.max(_st,Math.min(_en,Math.round((Math.max(this.startY,this.curY)/_px*60+_st)/30)*30));if(sM<eM){const fmt=m=>String(Math.floor(m/60)).padStart(2,'0')+':'+String(m%60).padStart(2,'0');this.startT=sM>=_en?'24:00':fmt(sM);this.endT=eM>=_en?'24:00':fmt(eM);const r=this.$el.getBoundingClientRect(),w=232,h=120;this.px=Math.min(Math.max(8,r.left+r.width/2-w/2),window.innerWidth-w-8);this.py=Math.min(Math.max(8,r.top+Math.min(this.startY,this.curY)+Math.abs(this.curY-this.startY)/2-h/2),window.innerHeight-h-8);this.popup=true;}}this.dragging=false;}
                                 }"
                                 @mousedown="if({{ (!Auth::check() || $dayIsPast) ? 'true' : 'false' }}||$event.button!==0||$event.target.closest('[data-booking]'))return; $event.preventDefault(); dragStart($event.clientY)"
                                 @mousemove.window="dragMove($event.clientY)"
                                 @mouseup.window="finish()"
                                 @touchstart="if({{ (!Auth::check() || $dayIsPast) ? 'true' : 'false' }}||!hov||$event.target.closest('[data-booking]'))return; $event.preventDefault(); dragStart($event.touches[0].clientY)"
                                 @touchmove="if(dragging){$event.preventDefault();dragMove($event.touches[0].clientY)}"
                                 @touchend="finish()"
                                 :class="dragging?'select-none cursor-ns-resize':'{{ $dayIsPast ? 'cursor-default' : '' }}'"
                                 style="z-index: 2; {{ $isDayToday ? 'background-color: rgba(253,221,16,0.04)' : ($isDayRed ? 'background-color: rgba(239,68,68,0.03)' : '') }}">

                                {{-- Drag-markering --}}
                                <div x-show="dragging && Math.abs(curY-startY) > 12"
                                     class="absolute inset-x-px pointer-events-none rounded"
                                     style="z-index:25"
                                     :style="`top:${Math.min(startY,curY)}px;height:${Math.abs(curY-startY)}px;background:rgba(99,102,241,0.35);border:2px solid rgb(99,102,241)`">
                                </div>

                                {{-- Bokningspopup --}}
                                <template x-teleport="body">
                                    <div x-show="popup"
                                         x-cloak
                                         x-transition:enter="transition ease-out duration-150"
                                         x-transition:enter-start="opacity-0 translate-y-1 scale-95"
                                         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                                         x-transition:leave="transition ease-in duration-100"
                                         x-transition:leave-start="opacity-100 scale-100"
                                         x-transition:leave-end="opacity-0 scale-95"
                                         :style="`position:fixed;z-index:10000;left:${px}px;top:${py}px`"
                                         class="w-56 rounded-2xl border border-zinc-200 dark:border-zinc-700 shadow-2xl bg-white dark:bg-zinc-950">
                                        <div class="flex items-start justify-between px-4 pt-4 pb-2">
                                            <div>
                                                <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-0.5">Ny bokning — {{ $day->locale('sv')->isoFormat('ddd D MMM') }}</p>
                                                <p class="text-sm font-semibold text-zinc-900 dark:text-white" x-text="startT + ' – ' + endT"></p>
                                            </div>
                                            <button @click="popup=false" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors mt-0.5">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </div>
                                        @auth
                                        <div class="px-4 pb-4">
                                            <a :href="`{{ route('booking.create') }}?datum={{ $dayKey }}&start=${startT}&slut=${endT}`"
                                               class="flex items-center justify-center gap-1.5 w-full rounded-xl bg-brand hover:bg-brand-saffron text-brand-dark text-sm font-semibold py-2 transition-colors">
                                                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                                Välj rum &amp; boka
                                            </a>
                                        </div>
                                        @endauth
                                    </div>
                                </template>

                                {{-- Bokningar --}}
                                @foreach ($dayBookings as $booking)
                                    @php
                                        $wAccent   = $roomColors->get($booking->resource_id, '#818cf8');
                                        $wShape    = $roomShapes->get($booking->resource_id, '<circle cx="6" cy="6" r="4.5"/>');
                                        $meta      = $bookingMeta[$booking->id];
                                        $topPx     = $meta['topPx'];
                                        $hPx       = $meta['hPx'];
                                        $laneIdx   = $meta['lane'];
                                        $widthPct  = 100 / $meta['numCols'];
                                        $leftPct   = $laneIdx * $widthPct;
                                        $wIsPending      = $booking->status === 'pending';
                                        $wIsOpen         = $booking->booking_type === 'open';
                                        $wIsEventBooking   = $booking->source_type === \App\Models\Events\EventOccurrence::class;
                                        $wIsMeetingBooking = $booking->source_type === \App\Models\Meeting\Meeting::class;
                                        $wRoomName       = $allRooms->firstWhere('id', $booking->resource_id)?->name ?? '—';
                                        $wStartStr  = $meta['dispStart'];
                                        $wEndStr    = $meta['dispEnd'];
                                    @endphp
                                    <div class="absolute rounded overflow-hidden cursor-pointer"
                                         data-booking
                                         x-data="{
                                             open:false,pinned:false,px:0,py:0,left:false,
                                             hov:window.matchMedia('(hover:hover)').matches,
                                             pos(el){const r=el.getBoundingClientRect(),w=252;const fr=r.right+8+w<=window.innerWidth;this.left=!fr;this.px=fr?r.right+8:Math.max(4,r.left-w-8);this.py=Math.max(4,Math.min(r.top,window.innerHeight-320));}
                                         }"
                                         @mouseenter="if(hov&&!document.body.classList.contains('cal-dragging')){pos($el);open=true}"
                                         @mouseleave="if(hov&&!pinned)open=false"
                                         @click.stop="if(open&&pinned){open=false;pinned=false}else{pos($el);open=true;pinned=true}"
                                         @click.outside="open=false;pinned=false"
                                         style="top: {{ $topPx }}px; height: {{ $hPx }}px; z-index: 20;
                                                left: calc({{ $leftPct }}% + 1px); width: calc({{ $widthPct }}% - 2px);
                                                background-color: {{ $wAccent }}30; border: 1.5px solid {{ $wAccent }}">
                                        @if ($hPx >= 10)
                                            <div class="flex items-center overflow-hidden" style="padding: 2px 2px 0; gap: 2px">
                                                <svg viewBox="0 0 12 12" width="7" height="7" aria-hidden="true"
                                                     style="fill: {{ $wAccent }}; flex-shrink: 0">{!! $wShape !!}</svg>
                                                <span class="text-zinc-800 dark:text-zinc-100" style="font-size:9px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; flex:1; min-width:0">
                                                    {{ $wStartStr }}@if ($hPx >= 22)–{{ $wEndStr }}@endif
                                                </span>
                                                @if ($wIsPending)<span class="text-amber-500 font-bold" style="font-size:9px; flex-shrink:0"> ·</span>@endif
                                            </div>
                                        @endif
                                        @if ($hPx >= 34)
                                            <p class="leading-tight truncate text-zinc-800 dark:text-zinc-100" style="font-size:9px; padding:0 3px">
                                                {{ $booking->title }}
                                            </p>
                                        @endif
                                        @auth
                                            @if ($hPx >= 50)
                                                <p class="leading-tight truncate text-zinc-500 dark:text-zinc-300" style="font-size:9px; padding:0 3px">
                                                    {{ $booking->user?->name }}
                                                </p>
                                            @endif
                                        @endauth

                                        {{-- Tooltip --}}
                                        <template x-teleport="body">
                                            <div x-show="open"
                                                 :style="`position:fixed;left:${px}px;top:${py}px;z-index:9999;width:252px`"
                                                 @mouseenter="if(hov)pinned=true"
                                                 @mouseleave="if(hov){pinned=false;open=false}"
                                                 @click.stop
                                                 class="rounded-xl border border-zinc-200 dark:border-zinc-600 shadow-2xl p-4 pointer-events-auto bg-white dark:bg-zinc-950">
                                                <div class="flex items-center justify-between gap-2 mb-2.5">
                                                    <div class="flex items-center gap-1.5 min-w-0">
                                                        <svg viewBox="0 0 12 12" width="12" height="12" aria-hidden="true"
                                                             style="fill:{{ $wAccent }};flex-shrink:0">{!! $wShape !!}</svg>
                                                        <span class="text-xs font-semibold truncate" style="color:{{ $wAccent }}">{{ $wRoomName }}</span>
                                                    </div>
                                                    <div class="flex shrink-0 items-center gap-0.5">
                                                        @can('bookings.manage')
                                                            @if ($booking->source_type === \App\Models\Booking\RecurringBooking::class)
                                                                <a href="{{ route('booking.admin.recurring-bookings.edit') }}?redigera={{ $booking->source_id }}" wire:navigate
                                                                   title="Redigera återkommande bokning"
                                                                   class="p-1 text-zinc-400 transition-colors hover:text-zinc-600 dark:hover:text-zinc-200">
                                                                    <x-bootstrap-icon name="pencil" class="size-3.5" />
                                                                </a>
                                                            @else
                                                                <a href="{{ route('booking.admin.bookings.edit', $booking) }}" wire:navigate
                                                                   title="Redigera bokning"
                                                                   class="p-1 text-zinc-400 transition-colors hover:text-zinc-600 dark:hover:text-zinc-200">
                                                                    <x-bootstrap-icon name="pencil" class="size-3.5" />
                                                                </a>
                                                            @endif
                                                        @endcan
                                                        <button @click.stop="open=false;pinned=false"
                                                                class="p-1 text-zinc-400 transition-colors hover:text-zinc-600 dark:hover:text-zinc-200">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                        </button>
                                                    </div>
                                                </div>
                                                <div class="text-zinc-900 dark:text-white font-semibold text-sm leading-snug mb-2.5">{{ $booking->title }}</div>
                                                <div class="text-zinc-500 dark:text-zinc-400 text-xs mb-0.5">{{ $booking->starts_at->locale('sv')->isoFormat('dddd D MMMM') }}</div>
                                                <div class="text-zinc-600 dark:text-zinc-300 text-xs mb-2">{{ $booking->starts_at->format('H:i') }}–{{ $wEndStr }}</div>
                                                @auth
                                                    @if ($booking->user)<div class="text-zinc-500 dark:text-zinc-400 text-xs mb-1">{{ $booking->user->name }}</div>@endif
                                                @endauth
                                                @if ($booking->activity)<div class="text-zinc-500 dark:text-zinc-400 text-xs mb-1">{{ $booking->activity }}</div>@endif
                                                @if ($booking->participation_info)<div class="text-zinc-600 dark:text-zinc-300 text-xs mb-2 italic">{{ $booking->participation_info }}</div>@endif
                                                <div class="flex gap-1.5 flex-wrap">
                                                    @if ($wIsEventBooking)
                                                        <span class="flex items-center gap-1 rounded-full border border-complement/20 bg-complement/10 px-2 py-0.5 text-xs text-complement dark:text-complement-light">
                                                            <x-bootstrap-icon name="calendar-event" class="size-3" /> Evenemang
                                                        </span>
                                                    @elseif ($wIsMeetingBooking)
                                                        <span class="flex items-center gap-1 rounded-full border border-complement/20 bg-complement/10 px-2 py-0.5 text-xs text-complement dark:text-complement-light">
                                                            <x-bootstrap-icon name="people" class="size-3" /> Möte
                                                        </span>
                                                    @elseif ($booking->source_type !== \App\Models\Booking\RecurringBooking::class)
                                                        <span class="flex items-center gap-1 rounded-full border border-zinc-200 bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                                            <x-bootstrap-icon name="calendar3" class="size-3" /> Bokning
                                                        </span>
                                                    @endif
                                                    @if ($booking->source_type === \App\Models\Booking\RecurringBooking::class)
                                                        <span class="flex items-center gap-1 rounded-full border border-zinc-200 bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                                            <x-bootstrap-icon name="arrow-repeat" class="size-3" /> Återkommande
                                                        </span>
                                                    @endif
                                                    @if ($wIsOpen)<span class="px-2 py-0.5 rounded-full text-xs bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-700/50">Öppet</span>@endif
                                                    @if ($wIsPending)<span class="px-2 py-0.5 rounded-full text-xs bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-700/50">Väntar</span>@endif
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                @endforeach

                            </div>
                        @endforeach

                    </div>{{-- /grid-kropp --}}
                </div>
            </div>
        @endif
    @endif

    @script
    (function () {
        const k = 'membrum_cal';
        const s = JSON.parse(sessionStorage.getItem(k) || '{}');
        if (s.date || s.view || s.hiddenRooms) {
            $wire.restoreState(s.date || '', s.view || 'week', s.hiddenRooms || []);
        }
        $wire.$watch('date', v => { const d = JSON.parse(sessionStorage.getItem(k) || '{}'); d.date = v; sessionStorage.setItem(k, JSON.stringify(d)); });
        $wire.$watch('view', v => { const d = JSON.parse(sessionStorage.getItem(k) || '{}'); d.view = v; sessionStorage.setItem(k, JSON.stringify(d)); });
        $wire.$watch('hiddenRooms', v => { const d = JSON.parse(sessionStorage.getItem(k) || '{}'); d.hiddenRooms = v; sessionStorage.setItem(k, JSON.stringify(d)); });
    })();
    @endscript

</div>
