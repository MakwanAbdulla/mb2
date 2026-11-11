<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventRegistrationResource;
use App\Http\Resources\EventResource;
use App\Mail\EventRegistrationMail;
use App\Models\AuditLog;
use App\Models\Events\Event;
use App\Models\Events\EventAddon;
use App\Models\Events\EventOccurrence;
use App\Models\Events\EventPriceTier;
use App\Models\Events\EventRegistration;
use App\Models\Events\EventRegistrationOccurrence;
use App\Models\Gamification\XpLog;
use App\Models\Membership\Member;
use App\Services\DiscordService;
use App\Services\GamificationService;
use App\Services\MemberCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Str;

class EventAdminController extends Controller
{
    public function index(Request $request): ResourceCollection
    {
        abort_unless($request->user()->hasAnyPermission(['events.view', 'events.manage']), 403);

        $events = Event::query()
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('visibility'), fn ($q, $v) => $q->where('visibility', $v))
            ->with(['occurrences', 'priceTiers'])
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return EventResource::collection($events);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:events,slug',
            'description' => 'nullable|string',
            'visibility' => 'required|in:public,members_only,section_only,invite_only',
            'registration_mode' => 'required|in:standard,dropin,both',
            'admission_type' => 'required|in:on_payment,automatic,manual',
            'waitlist_mode' => 'required|in:none,manual',
            'location_type' => 'nullable|in:room,venue_application,external,digital',
            'location_name' => 'nullable|string|max:255',
            'requires_membership' => 'boolean',
            'section_id' => 'nullable|exists:sections,id',
            'registration_opens_at' => 'nullable|date',
            'registration_closes_at' => 'nullable|date',
            'registration_instructions' => 'nullable|string',
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['title']);
        $data['status'] = Event::STATUS_DRAFT;

        $event = Event::create($data);

        AuditLog::record('event_created', 'events', $event->title, $event);

        return (new EventResource($event))->response()->setStatusCode(201);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'visibility' => 'sometimes|in:public,members_only,section_only,invite_only',
            'registration_mode' => 'sometimes|in:standard,dropin,both',
            'admission_type' => 'sometimes|in:on_payment,automatic,manual',
            'waitlist_mode' => 'sometimes|in:none,manual',
            'location_type' => 'nullable|in:room,venue_application,external,digital',
            'location_name' => 'nullable|string|max:255',
            'requires_membership' => 'boolean',
            'section_id' => 'nullable|exists:sections,id',
            'registration_opens_at' => 'nullable|date',
            'registration_closes_at' => 'nullable|date',
            'registration_instructions' => 'nullable|string',
        ]);

        $event->update($data);

        AuditLog::record('event_updated', 'events', $event->title, $event);

        return (new EventResource($event->fresh(['occurrences', 'priceTiers', 'addons'])))->response();
    }

    public function publish(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);
        abort_unless($event->status === Event::STATUS_DRAFT, 422, 'Only draft events can be published.');

        $event->update(['status' => Event::STATUS_PUBLISHED]);

        AuditLog::record('event_published', 'events', $event->title, $event);

        app(DiscordService::class)->notifyEventPublished($event);

        return response()->json(['message' => 'Event published.', 'status' => $event->status]);
    }

    public function cancel(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);
        abort_unless(in_array($event->status, [Event::STATUS_DRAFT, Event::STATUS_PUBLISHED]), 422, 'Event cannot be cancelled.');

        $event->update(['status' => Event::STATUS_CANCELLED]);

        AuditLog::record('event_cancelled', 'events', $event->title, $event);

        return response()->json(['message' => 'Event cancelled.', 'status' => $event->status]);
    }

    // ── Occurrences ───────────────────────────────────────────────────────────

    public function storeOccurrence(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);

        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'location_override' => 'nullable|string|max:255',
            'max_participants' => 'nullable|integer|min:1',
            'is_dropin' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $occurrence = $event->occurrences()->create($data);

        AuditLog::record('event_occurrence_created', 'events', $event->title, $event);

        return response()->json(['data' => $occurrence], 201);
    }

    public function updateOccurrence(Request $request, Event $event, EventOccurrence $occurrence): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);
        abort_unless($occurrence->event_id === $event->id, 404);

        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'starts_at' => 'sometimes|date',
            'ends_at' => 'sometimes|date',
            'location_override' => 'nullable|string|max:255',
            'max_participants' => 'nullable|integer|min:1',
            'is_dropin' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $occurrence->update($data);

        AuditLog::record('event_occurrence_updated', 'events', $event->title, $event);

        return response()->json(['data' => $occurrence]);
    }

    public function destroyOccurrence(Request $request, Event $event, EventOccurrence $occurrence): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);
        abort_unless($occurrence->event_id === $event->id, 404);

        $occurrence->delete();

        AuditLog::record('event_occurrence_deleted', 'events', $event->title, null);

        return response()->json(['message' => 'Occurrence deleted.']);
    }

    // ── Price tiers ───────────────────────────────────────────────────────────

    public function storePriceTier(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);

        $data = $request->validate([
            'label' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'price_ore' => 'required|integer|min:0',
            'requires_membership' => 'boolean',
            'access_section_id' => 'nullable|exists:sections,id',
            'min_age' => 'nullable|integer|min:0',
            'max_age' => 'nullable|integer|min:0',
            'max_count' => 'nullable|integer|min:1',
            'sort_order' => 'integer',
        ]);

        $tier = $event->priceTiers()->create($data);

        AuditLog::record('event_price_tier_created', 'events', $event->title, $event);

        return response()->json(['data' => $tier], 201);
    }

    public function updatePriceTier(Request $request, Event $event, EventPriceTier $priceTier): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);
        abort_unless($priceTier->event_id === $event->id, 404);

        $data = $request->validate([
            'label' => 'sometimes|string|max:100',
            'description' => 'nullable|string|max:500',
            'price_ore' => 'sometimes|integer|min:0',
            'requires_membership' => 'boolean',
            'max_count' => 'nullable|integer|min:1',
            'sort_order' => 'integer',
        ]);

        $priceTier->update($data);

        AuditLog::record('event_price_tier_updated', 'events', $event->title, $event);

        return response()->json(['data' => $priceTier]);
    }

    public function destroyPriceTier(Request $request, Event $event, EventPriceTier $priceTier): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);
        abort_unless($priceTier->event_id === $event->id, 404);

        $priceTier->delete();

        AuditLog::record('event_price_tier_deleted', 'events', $event->title, null);

        return response()->json(['message' => 'Price tier deleted.']);
    }

    // ── Addons ────────────────────────────────────────────────────────────────

    public function storeAddon(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'price_ore' => 'required|integer|min:0',
            'options' => 'nullable|array',
            'options.*' => 'string|max:100',
            'max_count' => 'nullable|integer|min:1',
            'sort_order' => 'integer',
        ]);

        $addon = $event->addons()->create($data);

        AuditLog::record('event_addon_created', 'events', $event->title, $event);

        return response()->json(['data' => $addon], 201);
    }

    public function updateAddon(Request $request, Event $event, EventAddon $addon): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);
        abort_unless($addon->event_id === $event->id, 404);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:500',
            'price_ore' => 'sometimes|integer|min:0',
            'options' => 'nullable|array',
            'options.*' => 'string|max:100',
            'max_count' => 'nullable|integer|min:1',
            'sort_order' => 'integer',
        ]);

        $addon->update($data);

        AuditLog::record('event_addon_updated', 'events', $event->title, $event);

        return response()->json(['data' => $addon]);
    }

    public function destroyAddon(Request $request, Event $event, EventAddon $addon): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);
        abort_unless($addon->event_id === $event->id, 404);

        $addon->delete();

        AuditLog::record('event_addon_deleted', 'events', $event->title, null);

        return response()->json(['message' => 'Addon deleted.']);
    }

    // ── Admission ─────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/events/{event}/admission
     *
     * Returns pending approval list, waitlist, confirmed count, capacity, spots remaining.
     */
    public function admissionSummary(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);

        $pendingApproval = $event->registrations()
            ->with(['user', 'priceTier'])
            ->pendingApproval()
            ->orderBy('created_at')
            ->get();

        $waitlisted = $event->registrations()
            ->with(['user', 'priceTier'])
            ->waitlisted()
            ->orderBy('created_at')
            ->get();

        $confirmedCount = $event->registrations()->confirmed()->count();

        $tiers = $event->priceTiers;
        $totalCapacity = $tiers->whereNull('max_count')->isNotEmpty()
            ? null
            : $tiers->sum('max_count');

        $spotsRemaining = $totalCapacity === null
            ? null
            : max(0, $totalCapacity - $confirmedCount);

        $mapReg = fn (EventRegistration $reg) => [
            'id' => $reg->id,
            'name' => $reg->user?->name ?? $reg->guest_name ?? '–',
            'email' => $reg->user?->email ?? $reg->guest_email,
            'price_tier' => $reg->priceTier ? [
                'id' => $reg->priceTier->id,
                'label' => $reg->priceTier->label,
                'price_ore' => $reg->priceTier->price_ore,
            ] : null,
            'notes' => $reg->notes,
            'status' => $reg->status,
            'created_at' => $reg->created_at->toIso8601String(),
        ];

        return response()->json([
            'pending_approval' => $pendingApproval->map($mapReg)->values(),
            'waitlisted' => $waitlisted->map($mapReg)->values(),
            'confirmed_count' => $confirmedCount,
            'capacity' => $totalCapacity,
            'spots_remaining' => $spotsRemaining,
        ]);
    }

    /**
     * POST /api/v1/admin/events/{event}/admission/approve
     *
     * Approve selected registrations (pending_approval).
     * Body: { "registration_ids": [1, 2, 3] }
     */
    public function approveRegistrations(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);

        $data = $request->validate([
            'registration_ids' => 'required|array|min:1',
            'registration_ids.*' => 'integer',
        ]);

        $registrations = $event->registrations()
            ->pendingApproval()
            ->whereIn('id', $data['registration_ids'])
            ->with('order')
            ->get();

        if ($registrations->isEmpty()) {
            return response()->json(['message' => 'No matching pending registrations found.'], 422);
        }

        foreach ($registrations as $reg) {
            $reg->approve();
            EventRegistrationMail::sendFor($reg->fresh());

            AuditLog::record(
                'event_registration_approved',
                'events',
                ($reg->user?->name ?? $reg->guest_name).' – '.$event->title,
                $reg,
            );
        }

        return response()->json([
            'message' => 'Registrations approved.',
            'approved' => $registrations->count(),
        ]);
    }

    /**
     * POST /api/v1/admin/events/{event}/admission/reject
     *
     * Reject selected registrations (pending_approval).
     * Body: { "registration_ids": [1, 2, 3] }
     */
    public function rejectRegistrations(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);

        $data = $request->validate([
            'registration_ids' => 'required|array|min:1',
            'registration_ids.*' => 'integer',
        ]);

        $registrations = $event->registrations()
            ->pendingApproval()
            ->whereIn('id', $data['registration_ids'])
            ->get();

        if ($registrations->isEmpty()) {
            return response()->json(['message' => 'No matching pending registrations found.'], 422);
        }

        foreach ($registrations as $reg) {
            $reg->reject();
            EventRegistrationMail::sendFor($reg->fresh());

            AuditLog::record(
                'event_registration_rejected',
                'events',
                ($reg->user?->name ?? $reg->guest_name).' – '.$event->title,
                $reg,
            );
        }

        return response()->json([
            'message' => 'Registrations rejected.',
            'rejected' => $registrations->count(),
        ]);
    }

    /**
     * POST /api/v1/admin/events/{event}/admission/admit-waitlist
     *
     * Admit the next person(s) from the waitlist.
     * Body: { "count": 1 } (optional, defaults to 1)
     */
    public function admitFromWaitlist(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);

        $data = $request->validate([
            'count' => 'nullable|integer|min:1|max:100',
        ]);

        $toAdmit = max(1, (int) ($data['count'] ?? 1));

        $waitlisted = $event->registrations()
            ->waitlisted()
            ->with('order')
            ->orderBy('created_at')
            ->limit($toAdmit)
            ->get();

        if ($waitlisted->isEmpty()) {
            return response()->json(['message' => 'No waitlisted registrations.', 'admitted' => 0]);
        }

        $admitted = 0;

        foreach ($waitlisted as $reg) {
            $newStatus = match ($event->admission_type) {
                Event::ADMISSION_AUTOMATIC => EventRegistration::STATUS_CONFIRMED,
                Event::ADMISSION_MANUAL => EventRegistration::STATUS_PENDING_APPROVAL,
                default => ($reg->order?->total_ore ?? 0) > 0
                    ? EventRegistration::STATUS_PENDING_PAYMENT
                    : EventRegistration::STATUS_CONFIRMED,
            };

            $reg->update(['status' => $newStatus]);
            EventRegistrationMail::sendFor($reg->fresh());
            $admitted++;
        }

        AuditLog::record('event_waitlist_admitted', 'events', $event->title." ({$admitted} admitted)", $event);

        return response()->json([
            'message' => "Admitted {$admitted} from waitlist.",
            'admitted' => $admitted,
        ]);
    }

    /**
     * POST /api/v1/admin/events/{event}/admission/walkin
     *
     * Add a walk-in registration for a member.
     * Body: { "member_id": int } — member must exist and not already be registered.
     */
    public function addWalkin(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);

        $data = $request->validate([
            'member_id' => 'required|integer|exists:members,id',
        ]);

        $member = Member::with(['user'])->findOrFail($data['member_id']);

        $alreadyRegistered = $event->registrations()
            ->where('user_id', $member->user_id)
            ->whereNotIn('status', [
                EventRegistration::STATUS_CANCELLED,
                EventRegistration::STATUS_TRANSFERRED,
            ])
            ->exists();

        if ($alreadyRegistered) {
            return response()->json([
                'message' => 'Member is already registered for this event.',
            ], 422);
        }

        $reg = EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $member->user_id,
            'status' => EventRegistration::STATUS_CONFIRMED,
            'is_walkin' => true,
            'check_in_token' => strtolower(Str::random(32)),
        ]);

        $occurrence = $event->occurrences()->orderBy('starts_at')->first();

        if ($occurrence) {
            EventRegistrationOccurrence::create([
                'registration_id' => $reg->id,
                'occurrence_id' => $occurrence->id,
                'attended_at' => now(),
                'checked_in_by_id' => $request->user()->id,
            ]);
        }

        AuditLog::record(
            'event_walkin_added',
            'events',
            "Oanmäld: {$member->full_name} registrerades på {$event->title}",
            $reg,
        );

        return response()->json([
            'message' => 'Walk-in registered.',
            'registration' => new EventRegistrationResource($reg->load('priceTier')),
        ], 201);
    }

    // ── Check-in ──────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/admin/events/{event}/occurrences/{occurrence}/check-in
     *
     * Check in a participant for a specific occurrence.
     * Supports two authentication methods:
     *   Method A — Member card QR (rotating HMAC): { member_id, token }
     *   Method B — Static ticket QR:               { check_in_token }
     */
    public function checkIn(Request $request, Event $event, EventOccurrence $occurrence): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);
        abort_unless($occurrence->event_id === $event->id, 404);

        $data = $request->validate([
            'member_id' => 'required_without:check_in_token|integer|exists:members,id',
            'token' => 'required_with:member_id|string|size:64',
            'check_in_token' => 'required_without:member_id|string|size:32',
        ]);

        if (isset($data['member_id'])) {
            // Method A — Member card QR (rotating HMAC)
            if (! MemberCardService::verifyToken((int) $data['member_id'], $data['token'])) {
                return response()->json(['message' => 'Ogiltig QR-kod. Låt medlemmen visa sin aktuella QR-kod.'], 401);
            }

            $member = Member::with(['user'])->findOrFail($data['member_id']);

            $registration = EventRegistration::where('event_id', $event->id)
                ->where('user_id', $member->user_id)
                ->whereIn('status', [
                    EventRegistration::STATUS_CONFIRMED,
                    EventRegistration::STATUS_PENDING_PAYMENT,
                ])
                ->first();
        } else {
            // Method B — Static ticket QR
            $registration = EventRegistration::where('check_in_token', $data['check_in_token'])
                ->whereHas('occurrenceAttendances', fn ($q) => $q->where('occurrence_id', $occurrence->id))
                ->first();
        }

        if (! $registration) {
            return response()->json(['message' => 'Ingen anmälan hittades för denna aktivitet.'], 404);
        }

        if (! in_array($registration->status, [
            EventRegistration::STATUS_CONFIRMED,
            EventRegistration::STATUS_PENDING_PAYMENT,
        ])) {
            return response()->json([
                'message' => 'Anmälan är inte bekräftad (status: '.$registration->status.').',
            ], 422);
        }

        $regOccurrence = EventRegistrationOccurrence::firstOrCreate(
            ['registration_id' => $registration->id, 'occurrence_id' => $occurrence->id],
        );

        $alreadyCheckedIn = $regOccurrence->hasAttended();

        if (! $alreadyCheckedIn) {
            $regOccurrence->checkIn($request->user());

            $member = $registration->user?->member;
            if ($member) {
                $alreadyAwarded = XpLog::where('member_id', $member->id)
                    ->where('source_type', XpLog::SOURCE_EVENT_CHECKIN)
                    ->where('source_id', $regOccurrence->id)
                    ->exists();

                if (! $alreadyAwarded) {
                    app(GamificationService::class)->award(
                        $member,
                        XpLog::SOURCE_EVENT_CHECKIN,
                        GamificationService::XP_VALUES[XpLog::SOURCE_EVENT_CHECKIN],
                        null,
                        $regOccurrence->id,
                    );
                }
            }
        }

        $regOccurrence->refresh();

        AuditLog::record(
            'event_check_in',
            'events',
            $event->title.': '.($registration->user?->member?->full_name ?? 'Gäst'),
            $regOccurrence,
        );

        // Load member data and relations for response
        $registration->load(['priceTier', 'addons.addon', 'user.member.membershipPeriods']);
        $member = $registration->user?->member;
        $user = $registration->user;

        return response()->json([
            'data' => [
                'already_checked_in' => $alreadyCheckedIn,
                'attended_at' => $regOccurrence->attended_at?->toIso8601String(),
                'member' => $member ? [
                    'id' => $member->id,
                    'name' => $member->full_name,
                    'avatar_url' => $user?->avatarUrl() ?? null,
                    'membership_type' => $member->membership_type,
                    'is_active' => $member->isActive(),
                ] : null,
                'registration' => [
                    'id' => $registration->id,
                    'status' => $registration->status,
                    'price_tier' => $registration->priceTier ? $registration->priceTier->label : null,
                    'addons' => $registration->addons->map(fn ($a) => [
                        'name' => $a->addon?->name ?? '–',
                        'selected_option' => $a->selected_option,
                    ])->values(),
                ],
                'occurrence' => [
                    'id' => $occurrence->id,
                    'starts_at' => $occurrence->starts_at->toIso8601String(),
                    'location' => $occurrence->location_override ?? $event->location_name,
                ],
                'event' => [
                    'id' => $event->id,
                    'title' => $event->title,
                    'brand' => $event->brand,
                ],
            ],
        ]);
    }
}
