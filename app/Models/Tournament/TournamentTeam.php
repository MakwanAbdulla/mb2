<?php

namespace App\Models\Tournament;

use App\Models\Membership\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TournamentTeam extends Model
{
    protected $table = 'tournament_teams';

    protected $fillable = ['tournament_id', 'name'];

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'tournament_team_members', 'team_id', 'member_id')
            ->withPivot('is_captain');
    }

    public function captain(): ?Member
    {
        return $this->members()->wherePivot('is_captain', true)->first();
    }
}
