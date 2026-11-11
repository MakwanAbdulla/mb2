<!DOCTYPE html>
<html lang="sv" class="bg-zinc-950 text-white">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="60">
    <title>{{ $tournament->title }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: system-ui, sans-serif; }
        .brand { color: #fddd10; }
    </style>
</head>
<body class="bg-zinc-950 min-h-screen flex flex-col p-8 gap-8">

    {{-- Header --}}
    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-6xl font-black tracking-tight brand">{{ $tournament->title }}</h1>
            @if($tournament->description)
                <p class="text-zinc-400 text-2xl mt-3 max-w-3xl">{{ $tournament->description }}</p>
            @endif
        </div>
        <div class="text-right shrink-0">
            <p class="brand text-3xl font-mono font-bold">{{ $generatedAt->format('H:i') }}</p>
        </div>
    </div>

    <div class="flex gap-8 flex-1">

        {{-- Participants --}}
        <div class="flex-1">
            <h2 class="text-zinc-500 text-base uppercase tracking-widest mb-4">
                Deltagare ({{ $participants->count() }}
                @if($tournament->max_participants)· max {{ $tournament->max_participants }}@endif)
            </h2>
            <div class="grid grid-cols-2 gap-2">
                @foreach($participants as $p)
                    <div class="flex items-center gap-3 rounded-xl bg-zinc-900 border border-zinc-800 px-4 py-3">
                        @if($p->seed)
                            <span class="shrink-0 size-8 rounded-full border border-yellow-600/40 bg-yellow-900/20 flex items-center justify-center text-sm font-bold text-yellow-500 tabular-nums">
                                {{ $p->seed }}
                            </span>
                        @endif
                        <span class="text-xl font-medium text-white truncate">{{ $p->displayName() }}</span>
                        @if(!empty($p->registration_data))
                            <div class="ml-auto flex gap-1.5 flex-wrap justify-end">
                                @foreach($p->registration_data as $val)
                                    <span class="text-xs text-zinc-500 bg-zinc-800 px-2 py-0.5 rounded">{{ $val }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Format info --}}
        <div class="w-72 shrink-0 space-y-4">
            <h2 class="text-zinc-500 text-base uppercase tracking-widest">Format</h2>

            @foreach($phases as $phase)
                <div class="rounded-xl bg-zinc-900 border border-zinc-800 p-5">
                    <p class="text-zinc-400 text-sm mb-1">Fas {{ $phase->phase_number }}</p>
                    <p class="text-xl font-bold text-white">{{ $phase->name }}</p>
                    <p class="brand font-medium mt-1">{{ $phase->formatLabel() }}</p>
                    <div class="mt-3 space-y-1 text-sm text-zinc-400">
                        @if($phase->rounds_count)
                            <p>{{ $phase->rounds_count }} ronder</p>
                        @endif
                        @if($phase->players_per_match > 2)
                            <p>{{ $phase->players_per_match }} spelare per bord</p>
                        @endif
                        @if($phase->tables_available)
                            <p>{{ $phase->tables_available }} bord tillgängliga</p>
                        @endif
                        @if($phase->uses_game_points)
                            <p>Spelpoäng som tiebreaker</p>
                        @endif
                        @if($phase->advancement_count && ! $loop->last)
                            <p class="text-zinc-500">Topp {{ $phase->advancement_count }} avancerar</p>
                        @endif
                    </div>
                </div>
            @endforeach

            <div class="rounded-xl bg-zinc-900 border border-zinc-800 p-5 space-y-1 text-sm text-zinc-400">
                <p class="text-white font-medium mb-2">Poängsystem</p>
                @php $firstPhase = $phases->first(); @endphp
                @if($firstPhase)
                    <p>Vinst: <span class="text-white font-bold">{{ $firstPhase->match_points_win }} mp</span></p>
                    @if($firstPhase->match_points_draw > 0)
                        <p>Oavgjort: <span class="text-white font-bold">{{ $firstPhase->match_points_draw }} mp</span></p>
                    @endif
                    <p>Förlust: <span class="text-white font-bold">{{ $firstPhase->match_points_loss }} mp</span></p>
                @endif
            </div>
        </div>
    </div>

    {{-- Footer nav --}}
    <div class="flex items-center justify-between text-zinc-700 text-sm">
        <span>{{ config('app.name') }}</span>
        <div class="flex gap-4">
            <a href="{{ route('tournaments.tv.standings', $tournament) }}" class="hover:text-zinc-500">Ställning</a>
            <a href="{{ route('tournaments.tv.draw', $tournament) }}" class="hover:text-zinc-500">Matchschema</a>
            <a href="{{ route('tournaments.tv.timer', $tournament) }}" class="hover:text-zinc-500">Timer</a>
        </div>
    </div>

</body>
</html>
