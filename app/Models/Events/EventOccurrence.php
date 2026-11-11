<?php

namespace App\Models\Events;

use App\Models\Events\EventRegistrationOccurrence;
use App\Models\Booking\Booking;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventOccurrence extends Model
{
    /** @use HasFactory<\Database\Factories\EventOccurrenceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'event_id',
        'title',
        'starts_at',
        'ends_at',
        'booking_id',
        'location_override',
        'max_participants',
        'min_participants',
        'is_dropin',
        'cancelled_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at'    => 'datetime',
            'ends_at'      => 'datetime',
            'cancelled_at' => 'datetime',
            'is_dropin'    => 'boolean',
        ];
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** Alias for max_participants for view compatibility. */
    public function getCapacityAttribute(): ?int
    {
        return $this->max_participants;
    }

    /**
     * Number of confirmed registrations for this occurrence.
     */
    public function confirmedCount(): int
    {
        return $this->attendances()
            ->whereHas('registration', fn ($q) => $q->confirmed())
            ->count();
    }

    /**
     * Whether this occurrence has available spots (null max = unlimited).
     */
    public function hasAvailableSpots(): bool
    {
        if ($this->max_participants === null) {
            return true;
        }

        return $this->confirmedCount() < $this->max_participants;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(EventRegistrationOccurrence::class, 'occurrence_id');
    }
}
