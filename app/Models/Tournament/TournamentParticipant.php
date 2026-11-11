<?php

namespace App\Models\Tournament;

use App\Models\Membership\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TournamentParticipant extends Model
{
    protected $table = 'tournament_participants';

    protected $fillable = [
        'tournament_id', 'member_id', 'team_id',
        'guest_name', 'guest_email',
        'seed', 'status', 'registration_data',
    ];

    protected function casts(): array
    {
        return [
            'registration_data' => 'array',
        ];
    }

    public const string STATUS_REGISTERED    = 'registered';
    public const string STATUS_CHECKED_IN    = 'checked_in';
    public const string STATUS_WAITLISTED    = 'waitlisted';
    public const string STATUS_WITHDRAWN     = 'withdrawn';
    public const string STATUS_DISQUALIFIED  = 'disqualified';

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(TournamentTeam::class, 'team_id');
    }

    public function matchParticipations(): HasMany
    {
        return $this->hasMany(TournamentMatchParticipant::class, 'participant_id');
    }

    public function displayName(): string
    {
        if ($this->team_id && $this->team) {
            return $this->team->name;
        }
        return $this->member?->full_name
            ?? $this->guest_name
            ?? "Deltagare #{$this->id}";
    }

    public function isGuest(): bool
    {
        return $this->member_id === null && $this->team_id === null;
    }
}
