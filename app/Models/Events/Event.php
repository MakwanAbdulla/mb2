<?php

namespace App\Models\Events;

use App\Models\Accounting\CostCenter;
use App\Models\Booking\Booking;
use App\Models\Booking\VenueApplication;
use App\Models\Form\Form;
use App\Models\Membership\Member;
use App\Models\Organization\Section;
use App\Models\Survey\Survey;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, SoftDeletes;

    // Status
    const STATUS_DRAFT = 'draft';

    const STATUS_PUBLISHED = 'published';

    const STATUS_CANCELLED = 'cancelled';

    const STATUS_COMPLETED = 'completed';

    // Brand — which organization's visual identity the event/scanner UI should use
    const BRAND_ARMAGEDON = 'armagedon';

    const BRAND_BSK = 'bsk';

    // Visibility
    const VISIBILITY_PUBLIC = 'public';

    const VISIBILITY_MEMBERS_ONLY = 'members_only';

    const VISIBILITY_SECTION_ONLY = 'section_only';

    const VISIBILITY_INVITE_ONLY = 'invite_only';

    // Location
    const LOCATION_ROOM = 'room';

    const LOCATION_VENUE_APPLICATION = 'venue_application';

    const LOCATION_EXTERNAL = 'external';

    const LOCATION_DIGITAL = 'digital';

    // Registration mode
    const REGISTRATION_STANDARD = 'standard';

    const REGISTRATION_DROPIN = 'dropin';

    const REGISTRATION_BOTH = 'both';

    // Waitlist
    const WAITLIST_NONE = 'none';

    const WAITLIST_MANUAL = 'manual';

    // Admission type
    const ADMISSION_ON_PAYMENT = 'on_payment'; // confirmed only after payment

    const ADMISSION_AUTOMATIC = 'automatic';  // confirmed immediately (spot reserved even before payment)

    const ADMISSION_MANUAL = 'manual';     // pending_approval until organizer approves

    // Organizer roles
    const ORGANIZER_ADMIN = 'admin';

    const ORGANIZER_HELPER = 'helper';

    // External revenue type
    const REVENUE_NONE = 'none';

    const REVENUE_PERCENTAGE = 'percentage';

    const REVENUE_PER_PARTICIPANT = 'per_participant';

    const REVENUE_PER_EVENT = 'per_event';

    const REVENUE_PER_OCCURRENCE = 'per_occurrence';

    /** @var list<string> */
    protected $fillable = [
        'title',
        'slug',
        'brand',
        // organizer_member_id removed — use event_organizers pivot instead
        'description',
        'status',
        'visibility',
        'organizer_member_id',
        'organizer_section_id',
        'organizer_external_name',
        'cost_center_id',
        'visibility_section_id',
        'requires_membership',
        'access_section_id',
        'min_age',
        'max_age',
        'location_type',
        'booking_id',
        'venue_application_id',
        'location_name',
        'location_url',
        'registration_mode',
        'allow_ticket_transfer',
        'waitlist_mode',
        'admission_type',
        'registration_opens_at',
        'registration_closes_at',
        'notify_organizer_on_registration',
        'notify_waitlist_on_opening',
        'reminder_hours_before',
        'registration_instructions',
        'reminder_instructions',
        'external_organizer_name',
        'external_organizer_contact',
        'external_fee_ore',
        'external_invoiced_at',
        'external_fee_paid_at',
        'external_revenue_type',
        'external_revenue_ore',
        'external_revenue_pct',
        'external_settlement_ore',
        'external_settled_at',
        'registration_form_id',
    ];

    protected function casts(): array
    {
        return [
            'registration_opens_at' => 'datetime',
            'registration_closes_at' => 'datetime',
            'external_invoiced_at' => 'datetime',
            'external_fee_paid_at' => 'datetime',
            'external_settled_at' => 'datetime',
            'requires_membership' => 'boolean',
            'allow_ticket_transfer' => 'boolean',
            'notify_organizer_on_registration' => 'boolean',
            'notify_waitlist_on_opening' => 'boolean',
        ];
    }

    protected static function booting(): void
    {
        static::creating(function (self $event) {
            if (empty($event->slug)) {
                $event->slug = static::uniqueSlug($event->title);
            }
        });
    }

    public static function uniqueSlug(string $title): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $i = 2;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    // ── Status helpers ────────────────────────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isRegistrationOpen(): bool
    {
        if (! $this->isPublished()) {
            return false;
        }

        $now = now();

        if ($this->registration_opens_at && $now->lt($this->registration_opens_at)) {
            return false;
        }

        if ($this->registration_closes_at && $now->gt($this->registration_closes_at)) {
            return false;
        }

        return true;
    }

    // ── External organizer settlement ─────────────────────────────────────────

    public function calculateExternalSettlement(): int
    {
        return match ($this->external_revenue_type) {
            self::REVENUE_PER_PARTICIPANT => (int) ($this->external_revenue_ore ?? 0)
                * $this->registrations()->confirmed()->count(),

            self::REVENUE_PER_EVENT => (int) ($this->external_revenue_ore ?? 0),

            self::REVENUE_PER_OCCURRENCE => (int) ($this->external_revenue_ore ?? 0)
                * $this->occurrences()->whereNull('cancelled_at')->count(),

            self::REVENUE_PERCENTAGE => (function () {
                $totalOre = $this->registrations()
                    ->where('event_registrations.status', EventRegistration::STATUS_CONFIRMED)
                    ->join('orders', 'event_registrations.order_id', '=', 'orders.id')
                    ->sum('orders.total_ore');

                return (int) round($totalOre * ($this->external_revenue_pct ?? 0) / 100);
            })(),

            default => 0,
        };
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    /**
     * All organizers (admin + helper) for this event.
     */
    public function organizers(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'event_organizers')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Organizers with the admin role — can edit event and manage registrations.
     */
    public function adminOrganizers(): BelongsToMany
    {
        return $this->organizers()->wherePivot('role', self::ORGANIZER_ADMIN);
    }

    /**
     * Organizers with the helper role — can check in and see participant list only.
     */
    public function helperOrganizers(): BelongsToMany
    {
        return $this->organizers()->wherePivot('role', self::ORGANIZER_HELPER);
    }

    public function isManagedBy(Member $member): bool
    {
        return $this->adminOrganizers()->where('members.id', $member->id)->exists();
    }

    public function isOrganizedBy(Member $member): bool
    {
        return $this->organizers()->where('members.id', $member->id)->exists();
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function organizerSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'organizer_section_id');
    }

    public function visibilitySection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'visibility_section_id');
    }

    public function accessSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'access_section_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function bookings(): MorphMany
    {
        return $this->morphMany(Booking::class, 'source');
    }

    public function venueApplication(): BelongsTo
    {
        return $this->belongsTo(VenueApplication::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(EventOccurrence::class)->orderBy('starts_at');
    }

    public function priceTiers(): HasMany
    {
        return $this->hasMany(EventPriceTier::class)->orderBy('sort_order');
    }

    public function addons(): HasMany
    {
        return $this->hasMany(EventAddon::class)->orderBy('sort_order');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function registrationForm(): BelongsTo
    {
        return $this->belongsTo(Form::class, 'registration_form_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(EventInvitation::class);
    }

    public function interests(): HasMany
    {
        return $this->hasMany(EventInterest::class);
    }

    public function memberIsInterested(Member $member): bool
    {
        return $this->interests()->where('member_id', $member->id)->exists();
    }

    public function memberInvitation(Member $member): ?EventInvitation
    {
        return $this->invitations()->where('member_id', $member->id)->first();
    }

    public function registrationSurvey(): MorphOne
    {
        return $this->morphOne(Survey::class, 'surveyable')
            ->where('role', Survey::ROLE_REGISTRATION);
    }

    public function followupSurvey(): MorphOne
    {
        return $this->morphOne(Survey::class, 'surveyable')
            ->where('role', Survey::ROLE_FOLLOWUP);
    }
}
