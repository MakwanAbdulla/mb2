<!DOCTYPE html>
<html lang="sv">
<head>
<meta charset="utf-8">
<style>
@page { margin: 15mm 12mm; }
body  { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; margin: 0; }
h1    { font-size: 16px; margin: 0 0 2px; }
.sub  { color: #555; font-size: 9px; margin-bottom: 12px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
th    { text-align: left; padding: 5px 8px; font-weight: bold; background: #f3f4f6;
        border-bottom: 2px solid #9ca3af; font-size: 9px; text-transform: uppercase; }
th.r, td.r { text-align: right; }
td    { padding: 5px 8px; border-bottom: 1px solid #f3f4f6; }
.gold { background: #fef9c3; }
.silver { background: #f9fafb; }
.footer { font-size: 8px; color: #9ca3af; margin-top: 8px; text-align: right; }
</style>
</head>
<body>

<h1>{{ $tournament->title }}</h1>
<p class="sub">
    {{ $phase->name }} · {{ $phase->formatLabel() }}
    @if($phase->rounds_count) · {{ $phase->completedRoundsCount() }}/{{ $phase->rounds_count }} ronder @endif
    · Utskrivet {{ now()->format('d/m Y H:i') }}
</p>

<table>
    <thead>
        <tr>
            <th style="width:32px">#</th>
            <th>Deltagare</th>
            <th class="r">MP</th>
            @if($phase->uses_game_points) <th class="r">SP</th> @endif
            <th class="r">V</th>
            <th class="r">O</th>
            <th class="r">F</th>
        </tr>
    </thead>
    <tbody>
        @foreach($standings as $s)
            <tr @class(['gold' => $s->rank === 1, 'silver' => $s->rank === 2])>
                <td>
                    @if($s->rank === 1) 1 🥇
                    @elseif($s->rank === 2) 2 🥈
                    @elseif($s->rank === 3) 3 🥉
                    @else {{ $s->rank }}
                    @endif
                </td>
                <td>{{ $s->participant?->displayName() }}</td>
                <td class="r"><strong>{{ tournament_num($s->match_points) }}</strong></td>
                @if($phase->uses_game_points) <td class="r">{{ tournament_num($s->game_points) }}</td> @endif
                <td class="r">{{ $s->wins }}</td>
                <td class="r">{{ $s->draws }}</td>
                <td class="r">{{ $s->losses }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<p class="footer">{{ config('app.name') }} · {{ $tournament->title }}</p>
</body>
</html>
