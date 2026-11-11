<?php

namespace App\Models\Tournament;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TournamentMatch extends Model
{
    protected $table = 'tournament_matches';

    protected $fillable = [
        'round_id', 'group_name', 'bracket_position', 'bracket_side',
        'next_match_id', 'loser_next_match_id', 'status',
        'table_number', 'time_slot',
    ];

    public const string STATUS_PENDING   = 'pending';
    public const string STATUS_ONGOING   = 'ongoing';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_BYE       = 'bye';

    public const string SIDE_WINNERS = 'winners';
    public const string SIDE_LOSERS  = 'losers';

    public function round(): BelongsTo
    {
        return $this->belongsTo(TournamentRound::class, 'round_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(TournamentMatchParticipant::class, 'match_id');
    }

    public function nextMatch(): BelongsTo
    {
        return $this->belongsTo(TournamentMatch::class, 'next_match_id');
    }

    public function loserNextMatch(): BelongsTo
    {
        return $this->belongsTo(TournamentMatch::class, 'loser_next_match_id');
    }

    public function winner(): ?TournamentParticipant
    {
        $mp = $this->participants()->where('is_winner', true)->first();
        return $mp?->participant;
    }

    public function phase(): TournamentPhase
    {
        return $this->round->phase;
    }
}
