<?php

namespace App\Models\Events;

use App\Models\Shop\Order;
use App\Models\Events\EventRegistrationOccurrence;
use App\Models\Events\EventRegistrationAddon;
use App\Models\Events\EventPriceTier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use App\Mail\EventRegistrationMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EventRegistration extends Model
{
    /** @use HasFactory<\Database\Factories\EventRegistrationFactory> */
    use HasFactory;

    const STATUS_PENDING_PAYMENT  = 'pending_payment';
    const STATUS_PENDING_APPROVAL = 'pending_approval';
    const STATUS_CONFIRMED        = 'confirmed';
    const STATUS_WAITLISTED       = 'waitlisted';
    const STATUS_CANCELLED        = 'cancelled';
    const STATUS_TRANSFERRED      = 'transferred';

    const PENDING_PAYMENT_EXPIRY_MINUTES = 30;

    public function scopeCountsTowardCapacity($query): void
    {
        $query->where(function ($q) {
            $q->whereIn('status', [self::STATUS_CONFIRMED, self::STATUS_PENDING_APPROVAL])
              ->orWhere(function ($q) {
                  $q->where('status', self::STATUS_PENDING_PAYMENT)
                    ->where('created_at', '>=', now()->subMinutes(self::PENDING_PAYMENT_EXPIRY_MINUTES));
              });
        });
    }

    /** @var list<string> */
    protected $fillable = [
        'event_id',
        'user_id',
        'guest_name',
        'guest_email',
        'price_tier_id',
        'order_id',
        'status',
        'check_in_token',
        'transferred_from_id',
        'transfer_approved_by_id',
        'cancelled_at',
        'form_submission_id',
        'notes',
        'is_walkin',
    ];

    protected function casts(): array
    {
        return [
            'cancelled_at' => 'datetime',
            'is_walkin'    => 'boolean',
        ];
    }

    protected static function booting(): void
    {
        static::creating(function (self $registration) {
            if (empty($registration->check_in_token)) {
                $registration->check_in_token = strtolower(Str::random(32));
            }
        });
    }

    // ── Status helpers ────────────────────────────────────────────────────────

    public function isPendingPayment(): bool  { return $this->status === self::STATUS_PENDING_PAYMENT; }
    public function isPendingApproval(): bool { return $this->status === self::STATUS_PENDING_APPROVAL; }
    public function isConfirmed(): bool       { return $this->status === self::STATUS_CONFIRMED; }
    public function isWaitlisted(): bool      { return $this->status === self::STATUS_WAITLISTED; }
    public function isCancelled(): bool       { return $this->status === self::STATUS_CANCELLED; }
    public function isTransferred(): bool     { return $this->status === self::STATUS_TRANSFERRED; }

    public function confirm(): void
    {
        $this->update(['status' => self::STATUS_CONFIRMED]);
    }

    public function waitlist(): void
    {
        $this->update(['status' => self::STATUS_WAITLISTED]);
    }

    public function cancel(): void
    {
        $this->update([
            'status'       => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Approve a pending_approval registration.
     * Moves to pending_payment if payment is still required, otherwise confirms directly.
     */
    public function approve(): void
    {
        $needsPayment = $this->order
            && $this->order->total_ore > 0
            && ! $this->order->isPaid();

        $this->update(['status' => $needsPayment
            ? self::STATUS_PENDING_PAYMENT
            : self::STATUS_CONFIRMED,
        ]);
    }

    /**
     * Reject a pending_approval or waitlisted registration.
     */
    public function reject(): void
    {
        $this->update([
            'status'       => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * After a confirmed (or pending_payment) registration is cancelled,
     * promote the next waitlisted person for the same tier.
     */
    public function promoteNextWaitlisted(): ?self
    {
        $event = $this->event;

        if ($event->waitlist_mode === Event::WAITLIST_NONE) {
            return null;
        }

        $next = static::where('event_id', $this->event_id)
            ->where('price_tier_id', $this->price_tier_id)
            ->where('status', self::STATUS_WAITLISTED)
            ->orderBy('created_at')
            ->first();

        if (! $next) {
            return null;
        }

        $newStatus = match ($event->admission_type) {
            Event::ADMISSION_AUTOMATIC => self::STATUS_CONFIRMED,
            Event::ADMISSION_MANUAL    => self::STATUS_PENDING_APPROVAL,
            default                    => ($next->order?->total_ore ?? 0) > 0
                ? self::STATUS_PENDING_PAYMENT
                : self::STATUS_CONFIRMED,
        };

        $next->update(['status' => $newStatus]);

        EventRegistrationMail::sendFor($next);

        Log::info('Promoted from waitlist', [
            'registration_id' => $next->id,
            'event_id'        => $this->event_id,
            'new_status'      => $newStatus,
        ]);

        return $next;
    }

    /**
     * Transfer this registration to another user (option A: admin-approved, no new payment).
     * Creates a new confirmed registration for the recipient and marks this one as transferred.
     */
    public function transferTo(User $recipient, User $approvedBy): self
    {
        $newRegistration = static::create([
            'event_id'                => $this->event_id,
            'user_id'                 => $recipient->id,
            'price_tier_id'           => $this->price_tier_id,
            'order_id'                => $this->order_id,
            'status'                  => self::STATUS_CONFIRMED,
            'transferred_from_id'     => $this->id,
            'transfer_approved_by_id' => $approvedBy->id,
            'form_submission_id'       => $this->form_submission_id,
            'notes'                   => $this->notes,
        ]);

        $this->update(['status' => self::STATUS_TRANSFERRED]);

        // Copy occurrence attendance records to new registration
        foreach ($this->occurrenceAttendances as $attendance) {
            EventRegistrationOccurrence::create([
                'registration_id' => $newRegistration->id,
                'occurrence_id'   => $attendance->occurrence_id,
                // attended_at and checked_in_by_id intentionally not copied
            ]);
        }

        return $newRegistration;
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeConfirmed($query)
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }

    public function scopeWaitlisted($query)
    {
        return $query->where('status', self::STATUS_WAITLISTED);
    }

    public function scopePendingPayment($query)
    {
        return $query->where('status', self::STATUS_PENDING_PAYMENT);
    }

    public function scopePendingApproval($query)
    {
        return $query->where('status', self::STATUS_PENDING_APPROVAL);
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(EventPriceTier::class, 'price_tier_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function transferredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transferred_from_id');
    }

    public function transferApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transfer_approved_by_id');
    }

    public function addons(): HasMany
    {
        return $this->hasMany(EventRegistrationAddon::class, 'registration_id');
    }

    public function occurrenceAttendances(): HasMany
    {
        return $this->hasMany(EventRegistrationOccurrence::class, 'registration_id');
    }

    public function formSubmission(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Form\FormSubmission::class, 'form_submission_id');
    }
}
