<?php

namespace App\Models\Tournament;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TournamentStanding extends Model
{
    public $timestamps = false;
    public $incrementing = false;

    protected $table = 'tournament_standings';

    protected $fillable = [
        'phase_id', 'participant_id', 'rank',
        'match_points', 'game_points', 'wins', 'losses', 'draws',
    ];

    protected function casts(): array
    {
        return [
            'match_points' => 'float',
            'game_points'  => 'float',
        ];
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(TournamentPhase::class, 'phase_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TournamentParticipant::class, 'participant_id');
    }
}
