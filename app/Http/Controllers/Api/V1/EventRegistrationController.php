<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventRegistrationResource;
use App\Mail\EventRegistrationMail;
use App\Models\AuditLog;
use App\Models\Shop\Discount;
use App\Models\Shop\DiscountCode;
use App\Models\Shop\DiscountUse;
use App\Models\Events\Event;
use App\Models\Events\EventAddon;
use App\Models\Events\EventRegistration;
use App\Models\Events\EventRegistrationAddon;
use App\Models\Events\EventRegistrationOccurrence;
use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use App\Models\Events\EventPriceTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class EventRegistrationController extends Controller
{
    /**
     * GET /api/v1/events/{slug}/registrations
     *
     * Returns the participant list. Requires events.view permission or being an organizer.
     */
    public function index(Request $request, string $slug): ResourceCollection|JsonResponse
    {
        $event = Event::where('slug', $slug)->firstOrFail();

        $user   = $request->user();
        $member = $user?->member;

        $canView = $user && (
            $user->hasAnyPermission(['events.view', 'events.manage-all'])
            || ($member && $event->isOrganizedBy($member))
        );

        if (! $canView) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $registrations = $event->registrations()
            ->with(['priceTier', 'user'])
            ->whereNotIn('status', [EventRegistration::STATUS_CANCELLED])
            ->orderBy('created_at')
            ->get();

        return EventRegistrationResource::collection($registrations);
    }

    /**
     * POST /api/v1/events/{slug}/registrations
     *
     * Register for an event. No auth required (guests can register).
     *
     * Body:
     *   price_tier_id  int   required
     *   notes          string optional
     *   promo_code     string optional
     *   guest_name     string required if not authenticated
     *   guest_email    string required if not authenticated
     *   guest_phone    string optional
     */
    public function store(Request $request, string $slug): JsonResponse
    {
        $event = Event::where('slug', $slug)
            ->where('status', Event::STATUS_PUBLISHED)
            ->with(['priceTiers', 'addons'])
            ->firstOrFail();

        if (! $event->isRegistrationOpen()) {
            return response()->json(['message' => 'Registration is not open.'], 422);
        }

        $user   = $request->user();
        $member = $user?->member;

        // Visibility / access check
        $memberSectionIds = $member
            ? $member->sections()->pluck('sections.id')->toArray()
            : [];

        $canRegister = match ($event->visibility) {
            Event::VISIBILITY_PUBLIC       => true,
            Event::VISIBILITY_MEMBERS_ONLY => $user !== null,
            Event::VISIBILITY_SECTION_ONLY => $user !== null
                && in_array($event->visibility_section_id, $memberSectionIds, true),
            default                        => false,
        };

        if (! $canRegister) {
            return response()->json(['message' => 'You are not eligible to register for this event.'], 403);
        }

        if ($event->requires_membership && (! $member || ! $member->hasFullAccess())) {
            return response()->json(['message' => 'Active membership is required.'], 403);
        }

        if ($event->access_section_id) {
            if (! $member || ! in_array($event->access_section_id, $memberSectionIds, true)) {
                return response()->json(['message' => 'You must belong to the required section.'], 403);
            }
        }

        // Validate request
        $data = $request->validate([
            'price_tier_id'          => ['required', 'integer'],
            'notes'                  => ['nullable', 'string', 'max:1000'],
            'promo_code'             => ['nullable', 'string', 'max:100'],
            'addons'                 => ['nullable', 'array'],
            'addons.*.addon_id'      => ['required', 'integer'],
            'addons.*.option_value'  => ['nullable', 'string', 'max:100'],
            'addons.*.quantity'      => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        // Check that the tier belongs to this event
        $tier = $event->priceTiers->firstWhere('id', $data['price_tier_id']);
        if (! $tier) {
            return response()->json(['message' => 'Invalid price tier.'], 422);
        }

        // Check capacity
        $isFull = $tier->max_count !== null && EventRegistration::where('price_tier_id', $tier->id)
            ->whereIn('status', [
                EventRegistration::STATUS_CONFIRMED,
                EventRegistration::STATUS_PENDING_PAYMENT,
                EventRegistration::STATUS_PENDING_APPROVAL,
            ])
            ->count() >= $tier->max_count;

        if ($isFull && $event->waitlist_mode === Event::WAITLIST_NONE) {
            return response()->json(['message' => 'This ticket type is sold out.'], 422);
        }

        // Validate and collect addon selections
        $addonSelections = [];
        foreach ($data['addons'] ?? [] as $selection) {
            $addon = $event->addons->firstWhere('id', $selection['addon_id']);
            if (! $addon) {
                return response()->json(['message' => 'Invalid addon.'], 422);
            }
            if ($addon->hasVariants() && empty($selection['option_value'])) {
                return response()->json(['message' => "Addon '{$addon->name}' requires an option."], 422);
            }
            if ($addon->hasVariants() && ! in_array($selection['option_value'], $addon->options, true)) {
                return response()->json(['message' => "Invalid option for addon '{$addon->name}'."], 422);
            }
            $addonSelections[] = [
                'addon'        => $addon,
                'option_value' => $selection['option_value'] ?? null,
                'quantity'     => $selection['quantity'] ?? 1,
            ];
        }

        $addonsTotalOre = collect($addonSelections)->sum(
            fn ($s) => $s['addon']->price_ore * $s['quantity']
        );

        // Validate promo code
        $appliedDiscount  = null;
        $promoCodeId      = null;
        $discountAmount   = 0;

        if (! empty($data['promo_code'])) {
            $code = DiscountCode::where('code', strtoupper(trim($data['promo_code'])))->first();

            if (! $code || ($code->is_single_use && $code->redeemed_at)) {
                return response()->json(['message' => 'Invalid or already used promo code.'], 422);
            }

            $discount = $code->discount;

            if (! $discount || ! $discount->isValid()) {
                return response()->json(['message' => 'Promo code is not valid at this time.'], 422);
            }

            if (! in_array($discount->applies_to, [Discount::APPLIES_EVENT, Discount::APPLIES_ALL])) {
                return response()->json(['message' => 'Promo code does not apply to events.'], 422);
            }

            $appliedDiscount = $discount;
            $promoCodeId     = $code->id;
            $discountAmount  = $discount->calculate($tier->price_ore);
        }

        $totalOre = max(0, $tier->price_ore + $addonsTotalOre - $discountAmount);

        // Build order
        $order = Order::create([
            'user_id'        => $user?->id,
            'guest_name'     => $user ? null : $data['guest_name'],
            'guest_email'    => $user ? null : $data['guest_email'],
            'guest_phone'    => $user ? null : ($data['guest_phone'] ?? null),
            'status'         => $totalOre === 0 ? 'paid' : 'pending',
            'total_ore'      => $totalOre,
            'payment_method' => $totalOre === 0 ? 'free' : null,
            'paid_at'        => $totalOre === 0 ? now() : null,
        ]);

        $line = OrderLine::create([
            'order_id'       => $order->id,
            'category'       => OrderLine::CATEGORY_EVENT,
            'orderable_type' => EventPriceTier::class,
            'orderable_id'   => $tier->id,
            'description'    => $tier->label . ' — ' . $event->title,
            'unit_price_ore' => $tier->price_ore,
            'quantity'       => 1,
            'line_total_ore' => $tier->price_ore,
        ]);

        if ($appliedDiscount && $discountAmount > 0) {
            DiscountUse::create([
                'discount_id'       => $appliedDiscount->id,
                'discount_code_id'  => $promoCodeId,
                'discountable_type' => OrderLine::class,
                'discountable_id'   => $line->id,
                'original_amount'   => $tier->price_ore,
                'discount_amount'   => $discountAmount,
            ]);

            if ($promoCodeId && $code->is_single_use) {
                $code->update(['redeemed_at' => now()]);
            }
        }

        // Determine initial status
        if ($isFull) {
            $initialStatus = EventRegistration::STATUS_WAITLISTED;
        } else {
            $initialStatus = match ($event->admission_type) {
                Event::ADMISSION_AUTOMATIC => EventRegistration::STATUS_CONFIRMED,
                Event::ADMISSION_MANUAL    => EventRegistration::STATUS_PENDING_APPROVAL,
                default                    => $totalOre === 0
                    ? EventRegistration::STATUS_CONFIRMED
                    : EventRegistration::STATUS_PENDING_PAYMENT,
            };
        }

        $registration = EventRegistration::create([
            'event_id'      => $event->id,
            'user_id'       => $user?->id,
            'guest_name'    => $user ? null : $data['guest_name'],
            'guest_email'   => $user ? null : $data['guest_email'],
            'price_tier_id' => $tier->id,
            'order_id'      => $order->id,
            'status'        => $initialStatus,
            'notes'         => $data['notes'] ?? null,
        ]);

        // Create addon records and order lines
        foreach ($addonSelections as $selection) {
            EventRegistrationAddon::create([
                'registration_id' => $registration->id,
                'addon_id'        => $selection['addon']->id,
                'option_value'    => $selection['option_value'],
                'quantity'        => $selection['quantity'],
            ]);

            if ($selection['addon']->price_ore > 0) {
                OrderLine::create([
                    'order_id'       => $order->id,
                    'category'       => OrderLine::CATEGORY_EVENT,
                    'orderable_type' => EventAddon::class,
                    'orderable_id'   => $selection['addon']->id,
                    'description'    => $selection['addon']->name
                        . ($selection['option_value'] ? ' (' . $selection['option_value'] . ')' : '')
                        . ' — ' . $event->title,
                    'unit_price_ore' => $selection['addon']->price_ore,
                    'quantity'       => $selection['quantity'],
                    'line_total_ore' => $selection['addon']->price_ore * $selection['quantity'],
                ]);
            }
        }

        EventRegistrationMail::sendFor($registration);

        $responseData = [
            'registration' => new EventRegistrationResource($registration->load('priceTier')),
            'order_id'     => $order->id,
            'total_ore'    => $totalOre,
        ];

        // For paid on_payment registrations, include a payment URL hint
        if ($initialStatus === EventRegistration::STATUS_PENDING_PAYMENT) {
            $responseData['payment_url'] = route('events.payment', $event->slug);
        }

        return response()->json($responseData, 201);
    }

    /**
     * POST /api/v1/events/{slug}/check-in
     *
     * Check in a participant by check_in_token or registration_id.
     * Requires events.check-in permission or being an organizer.
     *
     * Body:
     *   check_in_token  string  required (or registration_id)
     *   occurrence_id   int     optional — defaults to the first occurrence
     */
    public function checkIn(Request $request, string $slug): JsonResponse
    {
        $event = Event::where('slug', $slug)->with('occurrences')->firstOrFail();

        $user   = $request->user();
        $member = $user?->member;

        $canCheckIn = $user && (
            $user->hasAnyPermission(['events.check-in', 'events.manage-all'])
            || ($member && $event->isOrganizedBy($member))
        );

        if (! $canCheckIn) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'check_in_token' => ['required_without:registration_id', 'nullable', 'string'],
            'registration_id' => ['required_without:check_in_token', 'nullable', 'integer'],
            'occurrence_id'  => ['nullable', 'integer'],
        ]);

        $registration = isset($data['check_in_token'])
            ? EventRegistration::where('check_in_token', $data['check_in_token'])
                ->where('event_id', $event->id)
                ->first()
            : EventRegistration::where('id', $data['registration_id'])
                ->where('event_id', $event->id)
                ->first();

        if (! $registration) {
            return response()->json(['message' => 'Registration not found.'], 404);
        }

        if (! $registration->isConfirmed()) {
            return response()->json([
                'message' => 'Registration is not confirmed.',
                'status'  => $registration->status,
            ], 422);
        }

        // Resolve occurrence
        $occurrenceId = $data['occurrence_id']
            ?? $event->occurrences->first()?->id;

        if (! $occurrenceId) {
            return response()->json(['message' => 'No occurrence found for this event.'], 422);
        }

        // Check if already checked in for this occurrence
        $existing = EventRegistrationOccurrence::where('registration_id', $registration->id)
            ->where('occurrence_id', $occurrenceId)
            ->first();

        if ($existing && $existing->attended_at !== null) {
            return response()->json([
                'message'     => 'Already checked in.',
                'attended_at' => $existing->attended_at->toIso8601String(),
            ], 409);
        }

        if ($existing) {
            $existing->update([
                'attended_at'       => now(),
                'checked_in_by_id'  => $user->id,
            ]);
        } else {
            EventRegistrationOccurrence::create([
                'registration_id'  => $registration->id,
                'occurrence_id'    => $occurrenceId,
                'attended_at'      => now(),
                'checked_in_by_id' => $user->id,
            ]);
        }

        $name = $registration->user?->name ?? $registration->guest_name;
        AuditLog::record(
            'event_checked_in', 'events',
            "{$name} – {$event->name}",
            $registration,
        );

        return response()->json([
            'message'          => 'Checked in successfully.',
            'registration_id'  => $registration->id,
            'occurrence_id'    => $occurrenceId,
            'attended_at'      => now()->toIso8601String(),
            'name'             => $name,
        ]);
    }

    /**
     * POST /api/v1/events/{slug}/registrations/{registration}/approve
     *
     * Approve a pending_approval registration. Requires events.manage-all or manage-own.
     */
    public function approve(Request $request, string $slug, EventRegistration $registration): JsonResponse
    {
        $event = Event::where('slug', $slug)->firstOrFail();
        $user  = $request->user();
        $member = $user?->member;

        $canManage = $user && (
            $user->hasPermissionTo('events.manage-all')
            || ($user->hasPermissionTo('events.manage-own') && $member && $event->isOrganizedBy($member))
        );

        if (! $canManage) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($registration->event_id !== $event->id) {
            return response()->json(['message' => 'Registration not found.'], 404);
        }

        if ($registration->status !== EventRegistration::STATUS_PENDING_APPROVAL) {
            return response()->json(['message' => 'Registration is not pending approval.'], 422);
        }

        $registration->approve();
        EventRegistrationMail::sendFor($registration);

        AuditLog::record(
            'event_registration_approved', 'events',
            ($registration->user?->name ?? $registration->guest_name) . ' – ' . $event->name,
            $registration,
        );

        return response()->json([
            'message' => 'Registration approved.',
            'data'    => new EventRegistrationResource($registration),
        ]);
    }

    /**
     * POST /api/v1/events/{slug}/registrations/{registration}/reject
     *
     * Reject a pending_approval or waitlisted registration. Requires events.manage-all or manage-own.
     */
    public function reject(Request $request, string $slug, EventRegistration $registration): JsonResponse
    {
        $event = Event::where('slug', $slug)->firstOrFail();
        $user  = $request->user();
        $member = $user?->member;

        $canManage = $user && (
            $user->hasPermissionTo('events.manage-all')
            || ($user->hasPermissionTo('events.manage-own') && $member && $event->isOrganizedBy($member))
        );

        if (! $canManage) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($registration->event_id !== $event->id) {
            return response()->json(['message' => 'Registration not found.'], 404);
        }

        if (! in_array($registration->status, [
            EventRegistration::STATUS_PENDING_APPROVAL,
            EventRegistration::STATUS_WAITLISTED,
        ])) {
            return response()->json(['message' => 'Registration cannot be rejected in its current status.'], 422);
        }

        $registration->reject();
        EventRegistrationMail::sendFor($registration);

        AuditLog::record(
            'event_registration_rejected', 'events',
            ($registration->user?->name ?? $registration->guest_name) . ' – ' . $event->name,
            $registration,
        );

        return response()->json([
            'message' => 'Registration rejected.',
            'data'    => new EventRegistrationResource($registration),
        ]);
    }
}
