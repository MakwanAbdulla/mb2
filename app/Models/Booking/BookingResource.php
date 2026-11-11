<?php

namespace App\Models\Booking;

use App\Models\Booking\RecurringBooking;
use App\Models\Booking\BookingCategory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property int|null    $category_id
 * @property string      $name
 * @property string      $slug
 * @property string|null $description
 * @property string|null $location
 * @property int         $capacity
 * @property int|null    $max_duration
 * @property int         $min_duration
 * @property bool        $requires_approval
 * @property bool        $active
 * @property string|null $color
 * @property string|null $icon
 * @property array|null  $metadata
 */
class BookingResource extends Model
{
    protected $table = 'booking_resources';

    protected $fillable = [
        'category_id', 'name', 'slug', 'description', 'location',
        'capacity', 'max_duration', 'min_duration',
        'requires_approval', 'active', 'color', 'icon', 'metadata',
    ];

    protected $casts = [
        'requires_approval' => 'boolean',
        'active'            => 'boolean',
        'metadata'          => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(BookingCategory::class, 'category_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'resource_id');
    }

    public function recurringBookings(): HasMany
    {
        return $this->hasMany(RecurringBooking::class, 'resource_id');
    }

    public function isAvailable(Carbon $startsAt, Carbon $endsAt, ?int $excludeBookingId = null): bool
    {
        $query = $this->bookings()
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);

        if ($excludeBookingId) {
            $query->where('id', '!=', $excludeBookingId);
        }

        return $query->count() < $this->capacity;
    }

    public function firstConflict(Carbon $startsAt, Carbon $endsAt, ?int $excludeBookingId = null): ?Booking
    {
        $query = $this->bookings()
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);

        if ($excludeBookingId) {
            $query->where('id', '!=', $excludeBookingId);
        }

        return $query->first();
    }
}
