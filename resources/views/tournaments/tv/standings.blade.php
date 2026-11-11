<!DOCTYPE html>
<html lang="sv" class="bg-zinc-950 text-white">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="30">
    <title>{{ $tournament->title }} — Ställning</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: system-ui, sans-serif; }
        .brand { color: #fddd10; }
        .bg-brand { background-color: #fddd10; }
        @keyframes pulse-soft { 0%,100% { opacity:1; } 50% { opacity:.6; } }
        .pulse { animation: pulse-soft 2s ease-in-out infinite; }
    </style>
</head>
<body class="bg-zinc-950 min-h-screen flex flex-col p-6 gap-6">

    {{-- Header --}}
    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-4xl font-black tracking-tight text-white">{{ $tournament->title }}</h1>
            @if($phase)
                <p class="text-zinc-400 text-lg mt-1">
                    {{ $phase->name }}
                    @if($phase->status === 'ongoing')
                        · Runda {{ $phase->completedRoundsCount() }}
                        @if($phase->rounds_count) / {{ $phase->rounds_count }} @endif
                    @endif
                </p>
            @endif
        </div>
        <div class="text-right shrink-0">
            <p class="brand text-3xl font-mono font-bold">{{ $generatedAt->format('H:i') }}</p>
            <p class="text-zinc-500 text-sm">uppdateras var 30s</p>
        </div>
    </div>

    {{-- Standings table --}}
    @if($standings->isNotEmpty())
        <div class="flex-1 overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-900">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-zinc-800">
                        <th class="px-6 py-4 text-left text-zinc-500 font-semibold text-lg w-16">#</th>
                        <th class="px-6 py-4 text-left text-zinc-300 font-semibold text-lg">Deltagare</th>
                        <th class="px-6 py-4 text-center text-zinc-300 font-semibold text-lg">MP</th>
                        @if($phase->uses_game_points)
                            <th class="px-6 py-4 text-center text-zinc-300 font-semibold text-lg">SP</th>
                        @endif
                        <th class="px-6 py-4 text-center text-zinc-400 font-normal text-base hidden sm:table-cell">V</th>
                        <th class="px-6 py-4 text-center text-zinc-400 font-normal text-base hidden sm:table-cell">O</th>
                        <th class="px-6 py-4 text-center text-zinc-400 font-normal text-base hidden sm:table-cell">F</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($standings as $s)
                        @php
                            $rank = $s->rank;
                            $rowClass = match(true) {
                                $rank === 1 => 'bg-yellow-950/40 border-yellow-800/30',
                                $rank === 2 => 'bg-zinc-800/60',
                                $rank === 3 => 'bg-amber-950/20',
                                default     => '',
                            };
                        @endphp
                        <tr class="border-t border-zinc-800/60 {{ $rowClass }}">
                            <td class="px-6 py-4 text-center">
                                @if($rank === 1)
                                    <span class="text-3xl">🥇</span>
                                @elseif($rank === 2)
                                    <span class="text-3xl">🥈</span>
                                @elseif($rank === 3)
                                    <span class="text-3xl">🥉</span>
                                @else
                                    <span class="text-2xl font-bold text-zinc-500 tabular-nums">{{ $rank }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <span @class([
                                    'font-bold tabular-nums',
                                    'text-3xl text-yellow-300' => $rank === 1,
                                    'text-2xl text-zinc-100'   => $rank === 2 || $rank === 3,
                                    'text-xl text-zinc-200'    => $rank > 3,
                                ])>{{ $s->participant?->displayName() }}</span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span @class([
                                    'font-black tabular-nums brand',
                                    'text-4xl' => $rank <= 3,
                                    'text-3xl text-white' => $rank > 3,
                                ])>{{ tournament_num($s->match_points) }}</span>
                            </td>
                            @if($phase->uses_game_points)
                                <td class="px-6 py-4 text-center text-2xl text-zinc-400 tabular-nums">{{ tournament_num($s->game_points) }}</td>
                            @endif
                            <td class="px-6 py-4 text-center text-xl text-zinc-400 hidden sm:table-cell tabular-nums">{{ $s->wins }}</td>
                            <td class="px-6 py-4 text-center text-xl text-zinc-400 hidden sm:table-cell tabular-nums">{{ $s->draws }}</td>
                            <td class="px-6 py-4 text-center text-xl text-zinc-400 hidden sm:table-cell tabular-nums">{{ $s->losses }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="flex-1 flex items-center justify-center">
            <p class="text-zinc-600 text-2xl">Turneringen har inte startat ännu.</p>
        </div>
    @endif

    {{-- Footer --}}
    <div class="flex items-center justify-between text-zinc-600 text-sm">
        <span>{{ config('app.name') }}</span>
        <a href="{{ route('tournaments.tv.draw', $tournament) }}"
           class="text-zinc-700 hover:text-zinc-500">→ Matchschema</a>
    </div>

</body>
</html>
