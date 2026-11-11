<?php

namespace App\Models\Tournament;

use App\Models\Events\Event;
use App\Models\Events\EventOccurrence;
use App\Models\Organization\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Tournament extends Model
{
    protected $fillable = [
        'title', 'description', 'event_id', 'event_occurrence_id', 'section_id',
        'status', 'participant_type', 'max_participants', 'is_public',
        'registration_fields', 'tv_message', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_public'           => 'boolean',
            'registration_fields' => 'array',
        ];
    }

    public const string STATUS_DRAFT        = 'draft';
    public const string STATUS_REGISTRATION = 'registration';
    public const string STATUS_ONGOING      = 'ongoing';
    public const string STATUS_COMPLETED    = 'completed';
    public const string STATUS_CANCELLED    = 'cancelled';

    public const string TYPE_INDIVIDUAL = 'individual';
    public const string TYPE_TEAM       = 'team';

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'event_occurrence_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function phases(): HasMany
    {
        return $this->hasMany(TournamentPhase::class)->orderBy('phase_number');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(TournamentParticipant::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(TournamentTeam::class);
    }

    public function activeParticipants(): HasMany
    {
        return $this->hasMany(TournamentParticipant::class)
            ->whereNotIn('status', [TournamentParticipant::STATUS_WITHDRAWN, TournamentParticipant::STATUS_DISQUALIFIED]);
    }

    public function canBeManagedBy(User $user): bool
    {
        if ($user->hasPermissionTo('tournaments.manage')) {
            return true;
        }

        // Event-owned: event organizers with admin role
        if ($this->event_id && $this->event) {
            $isEventAdmin = $this->event->organizers()
                ->where('user_id', $user->id)
                ->wherePivot('role', 'admin')
                ->exists();
            if ($isEventAdmin) {
                return true;
            }
        }

        // Section-owned: section members with sections.manage OR any active section member
        if ($this->section_id && $user->hasPermissionTo('sections.manage')) {
            $inSection = \App\Models\Organization\SectionMembership::where('section_id', $this->section_id)
                ->where('member_id', $user->member?->id)
                ->where('status', \App\Models\Organization\SectionMembership::STATUS_ACTIVE)
                ->exists();
            if ($inSection) {
                return true;
            }
        }

        return false;
    }

    public function isRegistrationOpen(): bool
    {
        return $this->status === self::STATUS_REGISTRATION;
    }

    public function isFull(): bool
    {
        if (! $this->max_participants) {
            return false;
        }
        return $this->activeParticipants()->count() >= $this->max_participants;
    }

    public function participantFor(\App\Models\Membership\Member $member): ?TournamentParticipant
    {
        return $this->participants()->where('member_id', $member->id)->first();
    }

    public function waitlistCount(): int
    {
        return $this->participants()->where('status', TournamentParticipant::STATUS_WAITLISTED)->count();
    }

    public function currentPhase(): ?TournamentPhase
    {
        return $this->phases()->where('status', TournamentPhase::STATUS_ONGOING)->first()
            ?? $this->phases()->where('status', TournamentPhase::STATUS_PENDING)->first();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT        => 'Utkast',
            self::STATUS_REGISTRATION => 'Anmälan öppen',
            self::STATUS_ONGOING      => 'Pågående',
            self::STATUS_COMPLETED    => 'Avslutad',
            self::STATUS_CANCELLED    => 'Inställd',
            default                   => $this->status,
        };
    }
}
