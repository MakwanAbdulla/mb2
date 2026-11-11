<!DOCTYPE html>
<html lang="sv" class="bg-zinc-950 text-white">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="20">
    <title>{{ $tournament->title }} — Resultat</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: system-ui, sans-serif; }
        .brand { color: #fddd10; }
    </style>
</head>
<body class="bg-zinc-950 min-h-screen flex flex-col p-6 gap-6">

    {{-- Header --}}
    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-4xl font-black tracking-tight text-white">{{ $tournament->title }}</h1>
            @if($phase && $round)
                <p class="text-zinc-400 text-xl mt-1">
                    {{ $phase->name }} · Runda {{ $round->round_number }} — Resultat
                </p>
            @endif
        </div>
        <p class="brand text-3xl font-mono font-bold shrink-0">{{ $generatedAt->format('H:i') }}</p>
    </div>

    @if($round)
        @php
            $matchesBySlot = $round->matches
                ->where('status', '!=', 'bye')
                ->groupBy('time_slot')
                ->sortKeys();
        @endphp

        <div class="flex gap-6 flex-1">

            {{-- Match results --}}
            <div class="flex-1 space-y-4">
                @foreach($matchesBySlot as $slotNum => $slotMatches)
                    @if($matchesBySlot->count() > 1)
                        <h3 class="text-zinc-500 text-sm uppercase tracking-widest">
                            Pass {{ $slotNum }}
                            @if(($round->slot_times[$slotNum] ?? '') !== '')
                                · {{ $round->slot_times[$slotNum] }}
                            @endif
                        </h3>
                    @endif
                    <div class="grid gap-3 grid-cols-1 lg:grid-cols-2">
                        @foreach($slotMatches->sortBy('table_number') as $match)
                            <div class="rounded-xl border border-zinc-800 bg-zinc-900 overflow-hidden">
                                @if($match->table_number)
                                    <div class="border-b border-zinc-800 px-4 py-2 flex items-center gap-2">
                                        <span class="text-zinc-500 text-sm">Bord</span>
                                        <span class="brand font-black text-2xl tabular-nums">{{ $match->table_number }}</span>
                                    </div>
                                @endif
                                <div class="divide-y divide-zinc-800/60">
                                    @foreach($match->participants->sortBy('placement')->sortByDesc('match_points') as $mp)
                                        <div @class([
                                            'flex items-center justify-between px-4 py-3',
                                            'bg-yellow-950/30' => $mp->is_winner,
                                        ])>
                                            <div class="flex items-center gap-3">
                                                @if($mp->placement)
                                                    <span @class([
                                                        'shrink-0 size-8 rounded-full flex items-center justify-center text-base font-black',
                                                        'bg-yellow-500 text-zinc-900' => $mp->placement === 1,
                                                        'bg-zinc-600 text-white' => $mp->placement === 2,
                                                        'bg-amber-800 text-white' => $mp->placement === 3,
                                                        'bg-zinc-800 text-zinc-400' => $mp->placement > 3,
                                                    ])>{{ $mp->placement }}</span>
                                                @endif
                                                <span @class([
                                                    'font-semibold',
                                                    'text-xl text-white brand' => $mp->is_winner,
                                                    'text-lg text-zinc-200' => !$mp->is_winner,
                                                ])>{{ $mp->participant?->displayName() }}</span>
                                            </div>
                                            <div class="text-right shrink-0">
                                                <span @class([
                                                    'font-black text-xl tabular-nums',
                                                    'brand' => $mp->is_winner,
                                                    'text-zinc-400' => !$mp->is_winner,
                                                ])>{{ tournament_num($mp->match_points) }} mp</span>
                                                @if($mp->game_score !== null)
                                                    <p class="text-zinc-600 text-sm tabular-nums">{{ tournament_num($mp->game_score) }} sp</p>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>

            {{-- Mini standings --}}
            @if($standings->isNotEmpty())
                <div class="w-64 shrink-0">
                    <h3 class="text-zinc-500 text-sm uppercase tracking-widest mb-3">Ställning</h3>
                    <div class="space-y-1">
                        @foreach($standings->take(10) as $s)
                            <div @class([
                                'flex items-center gap-2 rounded-lg px-3 py-2',
                                'bg-yellow-950/30 border border-yellow-800/30' => $s->rank === 1,
                                'bg-zinc-900' => $s->rank > 1,
                            ])>
                                <span @class([
                                    'shrink-0 w-6 text-center text-sm font-bold tabular-nums',
                                    'brand' => $s->rank === 1,
                                    'text-zinc-500' => $s->rank > 1,
                                ])>{{ $s->rank }}</span>
                                <span class="flex-1 text-sm font-medium text-zinc-200 truncate">{{ $s->participant?->displayName() }}</span>
                                <span class="shrink-0 text-sm font-black tabular-nums text-white">{{ tournament_num($s->match_points) }}</span>
                            </div>
                        @endforeach
                        @if($standings->count() > 10)
                            <p class="text-zinc-700 text-xs text-center pt-1">+{{ $standings->count() - 10 }} till</p>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @else
        <div class="flex-1 flex items-center justify-center">
            <p class="text-zinc-600 text-2xl">Inga resultat ännu.</p>
        </div>
    @endif

    {{-- Footer nav --}}
    <div class="flex items-center justify-between text-zinc-700 text-sm">
        <span>{{ config('app.name') }}</span>
        <div class="flex gap-4">
            <a href="{{ route('tournaments.tv.standings', $tournament) }}" class="hover:text-zinc-500">Ställning</a>
            <a href="{{ route('tournaments.tv.draw', $tournament) }}" class="hover:text-zinc-500">Matchschema</a>
        </div>
    </div>

</body>
</html>
