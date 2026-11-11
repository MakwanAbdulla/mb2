<?php

namespace App\Models\Booking;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $user_id
 * @property string      $event_name
 * @property string      $responsible_name
 * @property string|null $responsible_phone
 * @property string      $responsible_email
 * @property \Carbon\Carbon $starts_at
 * @property \Carbon\Carbon $ends_at
 * @property int         $participants
 * @property bool        $needs_overnight
 * @property string      $needs_key
 * @property bool        $has_alcohol
 * @property string      $event_type
 * @property string|null $event_type_reason
 * @property array       $resource_ids
 * @property string|null $activity
 * @property string|null $notes
 * @property string      $status
 * @property string|null $admin_notes
 * @property array|null  $booking_ids
 */
class VenueApplication extends Model
{
    protected $fillable = [
        'user_id', 'event_name', 'description', 'responsible_name', 'responsible_phone',
        'responsible_email', 'starts_at', 'ends_at', 'participants',
        'needs_overnight', 'needs_key', 'has_alcohol', 'event_type',
        'event_type_reason', 'resource_ids', 'activity', 'notes',
        'status', 'admin_notes', 'booking_ids',
    ];

    protected $casts = [
        'starts_at'       => 'datetime',
        'ends_at'         => 'datetime',
        'needs_overnight' => 'boolean',
        'has_alcohol'     => 'boolean',
        'resource_ids'    => 'array',
        'booking_ids'     => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Mark application as approved with the resulting booking IDs. */
    public function approve(array $bookingIds, ?string $adminNotes = null): void
    {
        $this->update([
            'status'      => 'approved',
            'admin_notes' => $adminNotes,
            'booking_ids' => $bookingIds,
        ]);
    }

    /** Mark application as rejected. */
    public function reject(?string $adminNotes = null): void
    {
        $this->update([
            'status'      => 'rejected',
            'admin_notes' => $adminNotes,
        ]);
    }
}
