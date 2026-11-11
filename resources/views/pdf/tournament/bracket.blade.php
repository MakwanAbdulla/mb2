<!DOCTYPE html>
<html lang="sv">
<head>
<meta charset="utf-8">
<style>
@page { margin: 12mm 10mm; size: landscape; }
body  { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; margin: 0; }
h1    { font-size: 14px; margin: 0 0 2px; }
.sub  { color: #555; font-size: 8px; margin-bottom: 10px; }
.rounds { display: table; width: 100%; border-collapse: separate; border-spacing: 6px; }
.round  { display: table-cell; vertical-align: top; width: 1%; }
.round-label { font-size: 8px; font-weight: bold; text-transform: uppercase; color: #6b7280;
               letter-spacing: .05em; text-align: center; padding-bottom: 4px; border-bottom: 1px solid #e5e7eb; margin-bottom: 6px; }
.match  { border: 1px solid #e5e7eb; margin-bottom: 6px; border-radius: 2px; overflow: hidden; }
.player { padding: 4px 6px; border-bottom: 1px solid #f3f4f6; display: block; font-size: 9px; white-space: nowrap; overflow: hidden; }
.player:last-child { border-bottom: none; }
.winner { background: #fef9c3; font-weight: bold; }
.loser  { color: #9ca3af; text-decoration: line-through; }
.tbd    { color: #d1d5db; font-style: italic; }
.score  { float: right; font-weight: bold; color: #374151; }
.footer { font-size: 7px; color: #9ca3af; margin-top: 8px; text-align: right; }
</style>
</head>
<body>

<h1>{{ $tournament->title }} — Bracket</h1>
<p class="sub">{{ $phase->name }} · {{ $phase->formatLabel() }} · Utskrivet {{ now()->format('d/m Y H:i') }}</p>

@php
    $winnersRounds = $rounds->filter(fn($r) =>
        $r->matches->contains('bracket_side', null) || $r->matches->contains('bracket_side', 'winners')
    )->values();
    $totalRounds = $winnersRounds->count();
@endphp

<div class="rounds">
    @foreach($winnersRounds as $rIdx => $round)
        @php
            $roundName = match($totalRounds - $rIdx) {
                1 => 'Final',
                2 => 'Semifinal',
                3 => 'Kvartsfinal',
                default => 'Omg. ' . $round->round_number,
            };
        @endphp
        <div class="round">
            <div class="round-label">{{ $roundName }}</div>
            @foreach($round->matches->where('status', '!=', 'bye')->sortBy('bracket_position') as $match)
                <div class="match">
                    @foreach($match->participants->sortBy(fn($mp) => $mp->participant?->seed ?? 999) as $mp)
                        <span @class(['player',
                            'winner' => $mp->is_winner && $match->status === 'completed',
                            'loser'  => !$mp->is_winner && $match->status === 'completed',
                            'tbd'    => !$mp->participant,
                        ])>
                            {{ $mp->participant?->displayName() ?? 'TBD' }}
                            @if($match->status === 'completed' && $mp->game_score !== null)
                                <span class="score">{{ $mp->game_score }}</span>
                            @endif
                        </span>
                    @endforeach
                    @if($match->participants->count() < 2)
                        <span class="player tbd">TBD</span>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach
</div>

<p class="footer">{{ config('app.name') }} · {{ $tournament->title }}</p>
</body>
</html>
