<?php

namespace App\Models\Tournament;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TournamentRound extends Model
{
    protected $table = 'tournament_rounds';

    protected $fillable = ['phase_id', 'round_number', 'status', 'slot_times'];

    protected function casts(): array
    {
        return ['slot_times' => 'array'];
    }

    public const string STATUS_PENDING   = 'pending';
    public const string STATUS_ONGOING   = 'ongoing';
    public const string STATUS_COMPLETED = 'completed';

    public function phase(): BelongsTo
    {
        return $this->belongsTo(TournamentPhase::class, 'phase_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class, 'round_id')->orderBy('bracket_position')->orderBy('id');
    }

    public function isComplete(): bool
    {
        return $this->matches()
            ->whereNotIn('status', [TournamentMatch::STATUS_COMPLETED, TournamentMatch::STATUS_BYE])
            ->doesntExist();
    }
}
