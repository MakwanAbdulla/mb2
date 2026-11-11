<?php

namespace App\Models\Booking;

use App\Models\Organization\Section;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * @property int         $id
 * @property string|null $source_type
 * @property int|null    $source_id
 * @property int         $resource_id
 * @property int         $user_id
 * @property string      $title
 * @property string      $activity
 * @property string      $booking_type
 * @property string|null $participation_info
 * @property string|null $notes
 * @property \Carbon\Carbon $starts_at
 * @property \Carbon\Carbon $ends_at
 * @property string      $status
 * @property bool        $exempt_from_limit
 * @property string|null $rejection_reason
 */
class Booking extends Model
{
    protected $fillable = [
        'source_type', 'source_id', 'resource_id', 'user_id', 'section_id',
        'title', 'description', 'activity', 'booking_type', 'participation_info', 'notes',
        'starts_at', 'ends_at', 'status', 'rejection_reason', 'exempt_from_limit',
    ];

    protected $casts = [
        'starts_at'         => 'datetime',
        'ends_at'           => 'datetime',
        'exempt_from_limit' => 'boolean',
    ];

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(BookingResource::class, 'resource_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function bookerName(): string
    {
        return $this->section?->name ?? $this->user->name;
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function getDurationInMinutesAttribute(): int
    {
        return $this->starts_at->diffInMinutes($this->ends_at);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('starts_at', '>=', now())->orderBy('starts_at');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
