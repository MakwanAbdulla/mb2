<!DOCTYPE html>
<html lang="sv" class="bg-zinc-950 text-white">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="30">
    <title>{{ $tournament->title }} — Bracket</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: system-ui, sans-serif; }
        .brand { color: #fddd10; }
        .match-line::after {
            content: '';
            position: absolute;
            right: -1.5rem;
            top: 50%;
            width: 1.5rem;
            height: 1px;
            background: #3f3f46;
        }
        .match-connector {
            position: absolute;
            right: -1.5rem;
            width: 1.5rem;
            border-right: 1px solid #3f3f46;
        }
    </style>
</head>
<body class="bg-zinc-950 min-h-screen flex flex-col p-6 gap-6 overflow-x-auto">

    {{-- Header --}}
    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-4xl font-black tracking-tight text-white">{{ $tournament->title }}</h1>
            @if($phase)
                <p class="text-zinc-400 text-xl mt-1">{{ $phase->name }} · {{ $phase->formatLabel() }}</p>
            @endif
        </div>
        <p class="brand text-3xl font-mono font-bold shrink-0">{{ $generatedAt->format('H:i') }}</p>
    </div>

    @if($phase && $rounds->isNotEmpty())

        @php
            $winnersRounds = $rounds->filter(fn($r) => $r->matches->contains('bracket_side', 'winners') || $r->matches->contains('bracket_side', null))->values();
            $losersRounds  = $rounds->filter(fn($r) => $r->matches->contains('bracket_side', 'losers'))->values();
            $totalRounds   = $winnersRounds->count();
        @endphp

        {{-- Winners bracket --}}
        <div class="flex gap-0 min-w-max items-start">
            @foreach($winnersRounds as $rIdx => $round)
                @php
                    $matchCount = $round->matches->where('status', '!=', 'bye')->count();
                    $roundName  = match($totalRounds - $rIdx) {
                        1 => 'Final',
                        2 => 'Semifinal',
                        3 => 'Kvartsfinal',
                        default => 'Omg. ' . $round->round_number,
                    };
                @endphp

                <div class="flex flex-col min-w-[14rem] mr-12">
                    {{-- Round label --}}
                    <p class="text-zinc-500 text-xs uppercase tracking-widest text-center mb-4">{{ $roundName }}</p>

                    {{-- Matches --}}
                    <div class="flex flex-col gap-6">
                        @foreach($round->matches->where('status', '!=', 'bye')->sortBy('bracket_position') as $match)
                            <div class="relative">
                                <div @class([
                                    'rounded-xl border overflow-hidden',
                                    'border-zinc-700 bg-zinc-900' => $match->status !== 'completed',
                                    'border-zinc-800 bg-zinc-900' => $match->status === 'completed',
                                ])>
                                    @foreach($match->participants->sortBy(fn($mp) => $mp->participant?->seed ?? 999) as $mp)
                                        <div @class([
                                            'flex items-center justify-between px-4 py-3 border-b border-zinc-800/60 last:border-0',
                                            'bg-yellow-950/20' => $mp->is_winner && $match->status === 'completed',
                                        ])>
                                            <div class="flex items-center gap-2 min-w-0">
                                                @if($mp->participant?->seed)
                                                    <span class="shrink-0 text-xs font-bold text-yellow-600 tabular-nums w-4">{{ $mp->participant->seed }}</span>
                                                @endif
                                                <span @class([
                                                    'text-base font-medium truncate',
                                                    'text-white' => $mp->participant !== null,
                                                    'text-zinc-600 italic' => $mp->participant === null,
                                                    'brand' => $mp->is_winner && $match->status === 'completed',
                                                    'line-through text-zinc-600' => !$mp->is_winner && $match->status === 'completed',
                                                ])>
                                                    {{ $mp->participant?->displayName() ?? 'TBD' }}
                                                </span>
                                            </div>
                                            @if($match->status === 'completed' && $mp->game_score !== null)
                                                <span @class([
                                                    'text-sm font-bold tabular-nums ml-2 shrink-0',
                                                    'brand' => $mp->is_winner,
                                                    'text-zinc-500' => !$mp->is_winner,
                                                ])>{{ $mp->game_score }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                    @if($match->participants->isEmpty())
                                        <div class="px-4 py-3 text-zinc-600 italic text-sm">TBD</div>
                                        <div class="px-4 py-3 text-zinc-600 italic text-sm border-t border-zinc-800/60">TBD</div>
                                    @elseif($match->participants->count() === 1)
                                        <div class="px-4 py-3 text-zinc-600 italic text-sm border-t border-zinc-800/60">TBD</div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Losers bracket (double elim) --}}
        @if($losersRounds->isNotEmpty())
            <div>
                <p class="text-zinc-600 text-xs uppercase tracking-widest mb-4">Förlorarbracket</p>
                <div class="flex gap-0 min-w-max items-start">
                    @foreach($losersRounds as $round)
                        <div class="flex flex-col min-w-[14rem] mr-8">
                            <p class="text-zinc-600 text-xs uppercase tracking-widest text-center mb-4">R{{ $round->round_number }}</p>
                            <div class="flex flex-col gap-4">
                                @foreach($round->matches->where('status', '!=', 'bye')->sortBy('bracket_position') as $match)
                                    <div class="rounded-xl border border-zinc-800 bg-zinc-900/60 overflow-hidden">
                                        @foreach($match->participants as $mp)
                                            <div @class([
                                                'flex items-center justify-between px-3 py-2.5 border-b border-zinc-800/40 last:border-0',
                                                'bg-yellow-950/10' => $mp->is_winner && $match->status === 'completed',
                                            ])>
                                                <span @class([
                                                    'text-sm font-medium truncate',
                                                    'text-zinc-200' => $mp->participant !== null,
                                                    'text-zinc-600 italic' => $mp->participant === null,
                                                    'line-through text-zinc-700' => !$mp->is_winner && $match->status === 'completed',
                                                ])>{{ $mp->participant?->displayName() ?? 'TBD' }}</span>
                                                @if($match->status === 'completed' && $mp->game_score !== null)
                                                    <span class="text-xs text-zinc-500 tabular-nums ml-2 shrink-0">{{ $mp->game_score }}</span>
                                                @endif
                                            </div>
                                        @endforeach
                                        @if($match->participants->isEmpty())
                                            <div class="px-3 py-2.5 text-zinc-700 italic text-sm">TBD</div>
                                            <div class="px-3 py-2.5 text-zinc-700 italic text-sm border-t border-zinc-800/40">TBD</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

    @else
        <div class="flex-1 flex items-center justify-center">
            <p class="text-zinc-600 text-2xl">
                @if(!$phase)
                    Ingen elimination-fas är aktiv.
                @else
                    Bracket har inte genererats ännu.
                @endif
            </p>
        </div>
    @endif

    {{-- Footer nav --}}
    <div class="flex items-center justify-between text-zinc-700 text-sm mt-auto pt-4">
        <span>{{ config('app.name') }}</span>
        <div class="flex gap-4">
            <a href="{{ route('tournaments.tv.standings', $tournament) }}" class="hover:text-zinc-500">Ställning</a>
            <a href="{{ route('tournaments.tv.draw', $tournament) }}" class="hover:text-zinc-500">Matchschema</a>
        </div>
    </div>

</body>
</html>
