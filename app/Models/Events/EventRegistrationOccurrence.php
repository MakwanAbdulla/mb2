<?php

namespace App\Models\Events;

use App\Models\Events\EventOccurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRegistrationOccurrence extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'registration_id',
        'occurrence_id',
        'attended_at',
        'checked_in_by_id',
    ];

    protected function casts(): array
    {
        return [
            'attended_at' => 'datetime',
        ];
    }

    public function hasAttended(): bool
    {
        return $this->attended_at !== null;
    }

    public function checkIn(User $by): void
    {
        $this->update([
            'attended_at'      => now(),
            'checked_in_by_id' => $by->id,
        ]);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'occurrence_id');
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by_id');
    }
}
