<!DOCTYPE html>
<html lang="sv" class="bg-zinc-950 text-white">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="30">
    <title>{{ $tournament->title }} — Matchschema</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: system-ui, sans-serif; }
        .brand { color: #fddd10; }
        .bg-brand { background-color: #fddd10; }
    </style>
</head>
<body class="bg-zinc-950 min-h-screen flex flex-col p-6 gap-6">

    {{-- Header --}}
    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-4xl font-black tracking-tight text-white">{{ $tournament->title }}</h1>
            @if($phase && $round)
                <p class="text-zinc-400 text-xl mt-1">
                    {{ $phase->name }} · Runda {{ $round->round_number }}
                    @if($phase->rounds_count) / {{ $phase->rounds_count }} @endif
                    @if($round->status === 'completed')
                        <span class="text-zinc-600 text-base ml-2">(avslutad)</span>
                    @endif
                </p>
            @endif
        </div>
        <div class="text-right shrink-0">
            <p class="brand text-3xl font-mono font-bold">{{ $generatedAt->format('H:i') }}</p>
            <p class="text-zinc-500 text-sm">uppdateras var 30s</p>
        </div>
    </div>

    @if($matchesBySlot->isNotEmpty())

        @foreach($matchesBySlot as $slotNum => $matches)
            @php
                $slotTime = $round->slot_times[$slotNum] ?? null;
                $showSlotHeader = $matchesBySlot->count() > 1;
            @endphp

            @if($showSlotHeader)
                <div class="flex items-center gap-4">
                    <h2 class="text-2xl font-bold text-zinc-300">Pass {{ $slotNum }}</h2>
                    @if($slotTime)
                        <span class="brand text-3xl font-mono font-bold">{{ $slotTime }}</span>
                    @endif
                    <div class="flex-1 h-px bg-zinc-800"></div>
                </div>
            @endif

            {{-- Match grid --}}
            <div @class([
                'grid gap-4',
                'grid-cols-1'          => $matches->count() === 1,
                'grid-cols-2'          => $matches->count() === 2,
                'grid-cols-2 lg:grid-cols-3' => $matches->count() >= 3 && $matches->count() <= 6,
                'grid-cols-3 lg:grid-cols-4' => $matches->count() > 6,
            ])>
                @foreach($matches->sortBy('table_number') as $match)
                    <div @class([
                        'rounded-2xl border flex flex-col overflow-hidden',
                        'border-zinc-700 bg-zinc-900' => $match->status !== 'completed',
                        'border-zinc-800 bg-zinc-900/50 opacity-60' => $match->status === 'completed',
                    ])>
                        {{-- Table number header --}}
                        <div class="flex items-center justify-between px-5 py-3 border-b border-zinc-800">
                            @if($match->table_number)
                                <div>
                                    <span class="text-zinc-500 text-sm uppercase tracking-widest">Bord</span>
                                    <span class="brand text-4xl font-black ml-2 tabular-nums">{{ $match->table_number }}</span>
                                </div>
                            @endif
                            @if($match->status === 'completed')
                                <span class="text-zinc-600 text-sm">✓ klar</span>
                            @elseif($match->group_name)
                                <span class="text-zinc-500 text-sm">{{ $match->group_name }}</span>
                            @endif
                        </div>

                        {{-- Participants --}}
                        <div class="flex-1 divide-y divide-zinc-800/60">
                            @foreach($match->participants->sortBy(fn($mp) => $mp->participant?->seed ?? 99) as $mp)
                                <div @class([
                                    'px-5 py-4 flex items-center justify-between gap-3',
                                    'bg-yellow-950/20' => $mp->is_winner && $match->status === 'completed',
                                ])>
                                    <div class="flex items-center gap-3 min-w-0">
                                        @if($mp->participant?->seed)
                                            <span class="shrink-0 size-7 rounded-full border border-yellow-600/40 bg-yellow-900/20 flex items-center justify-center text-xs font-bold text-yellow-500 tabular-nums">
                                                {{ $mp->participant->seed }}
                                            </span>
                                        @endif
                                        <span @class([
                                            'font-bold truncate',
                                            'text-2xl text-white'   => $matches->count() <= 4,
                                            'text-xl text-zinc-100' => $matches->count() > 4,
                                            'brand'                 => $mp->is_winner && $match->status === 'completed',
                                        ])>
                                            {{ $mp->participant?->displayName() ?? '?' }}
                                        </span>
                                    </div>
                                    @if($match->status === 'completed' && $mp->match_points !== null)
                                        <div class="shrink-0 text-right">
                                            @if($mp->is_winner)
                                                <span class="brand font-black text-xl tabular-nums">{{ $mp->match_points }} mp</span>
                                            @else
                                                <span class="text-zinc-500 text-lg tabular-nums">{{ $mp->match_points }} mp</span>
                                            @endif
                                            @if($mp->game_score !== null)
                                                <p class="text-zinc-600 text-sm tabular-nums">{{ $mp->game_score }} sp</p>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @endforeach

                            @if($match->participants->isEmpty())
                                <div class="px-5 py-8 text-center text-zinc-600 text-lg">Väntar…</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach

        {{-- Bye matches --}}
        @php $byeMatches = $round->matches->where('status', 'bye'); @endphp
        @if($byeMatches->isNotEmpty())
            <div class="flex items-center gap-3 text-zinc-600 text-base">
                <span>Bye:</span>
                @foreach($byeMatches as $bye)
                    @foreach($bye->participants as $mp)
                        <span class="text-zinc-500">{{ $mp->participant?->displayName() }}</span>
                    @endforeach
                @endforeach
            </div>
        @endif

    @elseif($phase)
        <div class="flex-1 flex items-center justify-center">
            <p class="text-zinc-600 text-2xl">Väntar på nästa runda…</p>
        </div>
    @else
        <div class="flex-1 flex items-center justify-center">
            <p class="text-zinc-600 text-2xl">Turneringen har inte startat ännu.</p>
        </div>
    @endif

    {{-- Footer --}}
    <div class="flex items-center justify-between text-zinc-600 text-sm">
        <span>{{ config('app.name') }}</span>
        <a href="{{ route('tournaments.tv.standings', $tournament) }}"
           class="text-zinc-700 hover:text-zinc-500">→ Ställning</a>
    </div>

</body>
</html>
