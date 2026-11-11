<?php

namespace App\Models\Tournament;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class TournamentPhase extends Model
{
    protected $table = 'tournament_phases';

    protected $fillable = [
        'tournament_id', 'phase_number', 'name', 'format', 'status',
        'players_per_match', 'min_players_per_match', 'rounds_count', 'match_points_win',
        'match_points_draw', 'match_points_loss', 'uses_game_points',
        'advancement_count', 'group_count', 'tables_available', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'uses_game_points' => 'boolean',
            'settings'         => 'array',
        ];
    }

    public const string FORMAT_SINGLE_ELIM = 'single_elim';
    public const string FORMAT_DOUBLE_ELIM = 'double_elim';
    public const string FORMAT_ROUND_ROBIN = 'round_robin';
    public const string FORMAT_SWISS       = 'swiss';
    public const string FORMAT_POD_SWISS   = 'pod_swiss';

    public const string STATUS_PENDING   = 'pending';
    public const string STATUS_ONGOING   = 'ongoing';
    public const string STATUS_COMPLETED = 'completed';

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(TournamentRound::class, 'phase_id')->orderBy('round_number');
    }

    public function standings(): HasMany
    {
        return $this->hasMany(TournamentStanding::class, 'phase_id');
    }

    public function matches(): HasManyThrough
    {
        return $this->hasManyThrough(TournamentMatch::class, TournamentRound::class, 'phase_id', 'round_id');
    }

    public function currentRound(): ?TournamentRound
    {
        return $this->rounds()->where('status', TournamentRound::STATUS_ONGOING)->first()
            ?? $this->rounds()->where('status', TournamentRound::STATUS_PENDING)->first();
    }

    public function completedRoundsCount(): int
    {
        return $this->rounds()->where('status', TournamentRound::STATUS_COMPLETED)->count();
    }

    public function isElimination(): bool
    {
        return in_array($this->format, [self::FORMAT_SINGLE_ELIM, self::FORMAT_DOUBLE_ELIM]);
    }

    public function isSwiss(): bool
    {
        return in_array($this->format, [self::FORMAT_SWISS, self::FORMAT_POD_SWISS]);
    }

    public function formatLabel(): string
    {
        return match ($this->format) {
            self::FORMAT_SINGLE_ELIM => 'Single Elimination',
            self::FORMAT_DOUBLE_ELIM => 'Double Elimination',
            self::FORMAT_ROUND_ROBIN => 'Round Robin',
            self::FORMAT_SWISS       => 'Swiss',
            self::FORMAT_POD_SWISS   => 'Pod-Swiss',
            default                  => $this->format,
        };
    }
}
