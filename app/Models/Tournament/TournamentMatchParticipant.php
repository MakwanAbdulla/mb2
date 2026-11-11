<?php

namespace App\Models\Tournament;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TournamentMatchParticipant extends Model
{
    public $timestamps = false;

    protected $table = 'tournament_match_participants';

    protected $fillable = [
        'match_id', 'participant_id', 'game_score',
        'match_points', 'placement', 'is_winner', 'bonus_points',
    ];

    protected function casts(): array
    {
        return [
            'is_winner'    => 'boolean',
            'game_score'   => 'float',
            'bonus_points' => 'float',
            'match_points' => 'float',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(TournamentMatch::class, 'match_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TournamentParticipant::class, 'participant_id');
    }
}
