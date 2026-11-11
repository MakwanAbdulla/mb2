<!DOCTYPE html>
<html lang="sv" class="bg-zinc-950 text-white">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $tournament->title }} — Timer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: system-ui, sans-serif; }
        .brand { color: #fddd10; }
        @keyframes pulse-urgent { 0%,100% { opacity:1; } 50% { opacity:.3; } }
        .urgent { animation: pulse-urgent 1s ease-in-out infinite; }
    </style>
</head>
<body class="bg-zinc-950 min-h-screen flex flex-col items-center justify-center gap-8 p-8"
      x-data="timer()" x-init="init()">

    {{-- Tournament name --}}
    <div class="text-center">
        <p class="text-zinc-500 text-lg uppercase tracking-widest">{{ $tournament->title }}</p>
        @if($phase && $round)
            <p class="text-zinc-300 text-2xl mt-1">
                {{ $phase->name }} · Runda {{ $round->round_number }}
                @if($phase->rounds_count) / {{ $phase->rounds_count }} @endif
            </p>
        @endif
    </div>

    @if($round && count($slotTimes) > 0)

        {{-- Slot selector --}}
        @php
            $normalizedSlots = collect($slotTimes)->map(fn($v) => is_array($v) ? $v : ['start' => $v, 'end' => ''])->filter(fn($v) => $v['start']);
        @endphp
        @if($normalizedSlots->count() > 1)
            <div class="flex gap-3 flex-wrap justify-center">
                @foreach($normalizedSlots as $slotNum => $slotData)
                    <button @click="setSlot({{ $slotNum }})"
                            :class="activeSlot === {{ $slotNum }}
                                ? 'bg-zinc-700 text-white border-zinc-500'
                                : 'bg-zinc-900 text-zinc-400 border-zinc-800'"
                            class="px-5 py-2.5 rounded-xl border text-lg font-medium transition-colors">
                        Pass {{ $slotNum }} · {{ $slotData['start'] }}
                        @if($slotData['end']) <span class="text-sm opacity-70">–{{ $slotData['end'] }}</span> @endif
                    </button>
                @endforeach
            </div>
        @else
            @foreach($normalizedSlots as $slotNum => $slotData)
                <p class="text-zinc-400 text-2xl">
                    Pass {{ $slotNum }} · <span class="brand font-bold">{{ $slotData['start'] }}</span>
                    @if($slotData['end']) <span class="text-zinc-500">– {{ $slotData['end'] }}</span> @endif
                </p>
            @endforeach
        @endif

        {{-- Clock --}}
        <div class="text-center">
            <div class="text-[10rem] font-black tabular-nums leading-none brand"
                 :class="{ 'text-red-500 urgent': isUrgent }"
                 x-text="displayTime">
                --:--
            </div>
            <p class="text-zinc-500 text-xl mt-2" x-text="statusText"></p>
        </div>

        {{-- Progress bar --}}
        <div class="w-full max-w-2xl">
            <div class="h-3 rounded-full bg-zinc-800 overflow-hidden">
                <div class="h-full rounded-full transition-all duration-1000"
                     :class="isUrgent ? 'bg-red-500' : 'bg-yellow-400'"
                     :style="'width:' + progressPct + '%'"></div>
            </div>
        </div>

    @elseif($round)
        {{-- Round active but no slot times set --}}
        <div class="text-center">
            <div class="text-[10rem] font-black tabular-nums leading-none brand"
                 x-text="clockTime">00:00:00</div>
            <p class="text-zinc-500 text-xl mt-2">Klockan just nu</p>
        </div>

    @else
        <p class="text-zinc-600 text-3xl">Ingen aktiv runda.</p>
    @endif

    {{-- Current clock always shown --}}
    <div class="absolute top-6 right-8 text-right">
        <p class="brand text-3xl font-mono font-bold" x-text="clockTime">--:--:--</p>
        <p class="text-zinc-600 text-sm">{{ $generatedAt->locale('sv')->isoFormat('dddd D MMM') }}</p>
    </div>

    {{-- Nav --}}
    <div class="absolute bottom-6 left-8 right-8 flex items-center justify-between text-zinc-700 text-sm">
        <span>{{ config('app.name') }}</span>
        <div class="flex gap-4">
            <a href="{{ route('tournaments.tv.standings', $tournament) }}" class="hover:text-zinc-500">Ställning</a>
            <a href="{{ route('tournaments.tv.draw', $tournament) }}" class="hover:text-zinc-500">Matchschema</a>
        </div>
    </div>

    <script>
    function timer() {
        return {
            clockTime: '--:--:--',
            displayTime: '--:--',
            statusText: '',
            progressPct: 100,
            isUrgent: false,
            activeSlot: {{ array_key_first($slotTimes ?? [1 => null]) ?? 1 }},
            slotStartTime: null,
            roundMinutes: 60, // default round length in minutes
            interval: null,

            slotTimes: @json($slotTimes),

            init() {
                this.autoDetectSlot();
                this.tick();
                this.interval = setInterval(() => this.tick(), 1000);
            },

            // Normalise slot entry to {start, end} regardless of old string format
            slotNorm(entry) {
                if (!entry) return {start: '', end: ''};
                if (typeof entry === 'string') return {start: entry, end: ''};
                return {start: entry.start || '', end: entry.end || ''};
            },

            autoDetectSlot() {
                const now = this.nowMinutes();
                let bestSlot = null;
                let bestTime = -1;
                for (const [slot, entry] of Object.entries(this.slotTimes)) {
                    const {start} = this.slotNorm(entry);
                    if (!start) continue;
                    const [h, m] = start.split(':').map(Number);
                    const slotMins = h * 60 + m;
                    if (slotMins <= now && slotMins > bestTime) {
                        bestTime = slotMins;
                        bestSlot = parseInt(slot);
                    }
                }
                if (bestSlot) this.setSlot(bestSlot);
            },

            setSlot(slotNum) {
                this.activeSlot = slotNum;
                const entry = this.slotTimes[slotNum];
                const {start, end} = this.slotNorm(entry);
                if (!start) return;

                const [h, m] = start.split(':').map(Number);
                const today  = new Date();
                today.setHours(h, m, 0, 0);
                this.slotStartTime = today;

                if (end) {
                    // Explicit end time set — use it directly
                    const [eh, em] = end.split(':').map(Number);
                    const endDate  = new Date();
                    endDate.setHours(eh, em, 0, 0);
                    this.roundMinutes = (endDate - today) / 60000;
                } else {
                    // Fall back: next slot start - 5 min buffer
                    const slots = Object.entries(this.slotTimes)
                        .filter(([,v]) => this.slotNorm(v).start)
                        .sort(([a],[b]) => parseInt(a) - parseInt(b));
                    const idx = slots.findIndex(([k]) => parseInt(k) === slotNum);
                    if (idx >= 0 && idx < slots.length - 1) {
                        const [, nextEntry] = slots[idx + 1];
                        const {start: ns} = this.slotNorm(nextEntry);
                        const [nh, nm] = ns.split(':').map(Number);
                        this.roundMinutes = (nh * 60 + nm) - (h * 60 + m) - 5;
                    } else {
                        this.roundMinutes = 90;
                    }
                }
            },

            nowMinutes() {
                const d = new Date();
                return d.getHours() * 60 + d.getMinutes();
            },

            tick() {
                const now = new Date();
                this.clockTime = now.toTimeString().slice(0, 8);

                if (!this.slotStartTime) return;

                const elapsed  = Math.floor((now - this.slotStartTime) / 1000);
                const total    = this.roundMinutes * 60;
                const remaining = total - elapsed;

                if (remaining <= 0) {
                    this.displayTime = '00:00';
                    this.statusText  = 'Rundan är klar!';
                    this.progressPct = 0;
                    this.isUrgent    = true;
                    return;
                }

                const mins = Math.floor(remaining / 60);
                const secs = remaining % 60;
                this.displayTime = String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
                this.progressPct = Math.round((remaining / total) * 100);
                this.isUrgent    = remaining <= 5 * 60; // last 5 minutes

                if (elapsed < 0) {
                    this.statusText = 'Startar om ' + Math.abs(Math.floor(elapsed / 60)) + ' min';
                } else {
                    this.statusText = 'kvar av rundan';
                }
            },
        }
    }
    </script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</body>
</html>
