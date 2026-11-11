<?php

use App\Models\AuditLog;
use App\Models\Events\Event;
use App\Models\Events\EventOccurrence;
use App\Models\Events\EventRegistration;
use App\Models\Events\EventRegistrationAddon;
use App\Models\Events\EventRegistrationOccurrence;
use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingAttendee;
use App\Models\Membership\Member;
use App\Models\Shop\DiscountCode;
use App\Models\Shop\OrderFulfillment;
use App\Services\ScannerRelayStore;
use App\Services\ScannerService;
use App\Services\ScanResult;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.scanner');
title('Skanningsstation');

new class extends Component {

    public string  $scanInput    = '';
    public ?string $resultType   = null;
    public ?string $resultStatus = null;
    public ?int    $modelId      = null;
    public ?string $message      = null;
    public bool    $justActioned = false;
    public ?string $actionName   = null;

    /** Currently selected event ID (null = discount-code/QR only mode). */
    public ?int $eventId = null;

    /** Currently selected meeting ID for attendee check-in. */
    public ?int $meetingId = null;

    /** Active pairing session for a remote (e.g. ESP32) scanner, if any. */
    public ?string $pairingSessionId = null;

    // ── Event stats (shown in banner when event is selected) ──────────────────

    #[Computed]
    public function eventStats(): ?array
    {
        if (! $this->eventId) {
            return null;
        }

        $event = $this->currentEvent;
        if (! $event) {
            return null;
        }

        $confirmed = $event->registrations()
            ->where('status', EventRegistration::STATUS_CONFIRMED)
            ->count();

        $walkins = $event->registrations()
            ->where('status', EventRegistration::STATUS_CONFIRMED)
            ->where('is_walkin', true)
            ->count();

        $tiers    = $event->priceTiers;
        $capacity = $tiers->whereNull('max_count')->isNotEmpty()
            ? null
            : ($tiers->isEmpty() ? null : (int) $tiers->sum('max_count'));

        return [
            'confirmed' => $confirmed,
            'walkins'   => $walkins,
            'capacity'  => $capacity,
            'remaining' => $capacity !== null ? max(0, $capacity - $confirmed) : null,
        ];
    }

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('payments.view'), 403);
    }

    // ── Event selection ───────────────────────────────────────────────────────

    #[Computed]
    public function currentEvent(): ?Event
    {
        return $this->eventId
            ? Event::with(['occurrences'])->find($this->eventId)
            : null;
    }

    /** Events with occurrences in the last 24h or the next 7 days. */
    #[Computed]
    public function selectableEvents()
    {
        return Event::whereIn('status', [Event::STATUS_PUBLISHED, Event::STATUS_COMPLETED])
            ->whereHas('occurrences', fn ($q) => $q
                ->where('starts_at', '>=', now()->subHours(24))
                ->where('starts_at', '<=', now()->addDays(7))
            )
            ->with(['occurrences' => fn ($q) => $q->orderBy('starts_at')])
            ->get()
            ->sortBy(fn ($e) => $e->occurrences->min('starts_at'));
    }

    /**
     * Called from JS: restores the selected event from localStorage (or URL param).
     * Validates that the event hasn't ended. If it has, dispatches 'scanner-event-expired'
     * so the client can clear localStorage.
     */
    public function setEvent(int $id): void
    {
        $event = Event::with('occurrences')->find($id);

        if (! $event) {
            $this->eventId = null;
            $this->dispatch('scanner-event-expired');
            return;
        }

        // Auto-clear if the last occurrence ended more than 6 hours ago
        $lastOccurrence = $event->occurrences->sortByDesc('starts_at')->first();
        if ($lastOccurrence && $lastOccurrence->starts_at->addHours(6)->isPast()) {
            $this->eventId = null;
            $this->dispatch('scanner-event-expired');
            return;
        }

        $this->eventId = $id;
        $this->dispatch('scanner-event-stored', id: $id);
        unset($this->currentEvent, $this->selectableEvents);
    }

    public function clearEvent(): void
    {
        $this->eventId = null;
        $this->dispatch('scanner-event-expired');
        unset($this->currentEvent);
    }

    // ── Meeting selection ─────────────────────────────────────────────────────

    #[Computed]
    public function currentMeeting(): ?Meeting
    {
        return $this->meetingId
            ? Meeting::with(['attendees'])->find($this->meetingId)
            : null;
    }

    /** Meetings from the last 7 days and next 30 days. */
    #[Computed]
    public function selectableMeetings()
    {
        return Meeting::whereBetween('scheduled_at', [now()->subDays(7), now()->addDays(30)])
            ->orderBy('scheduled_at')
            ->get(['id', 'title', 'scheduled_at']);
    }

    #[Computed]
    public function meetingStats(): ?array
    {
        if (! $this->meetingId) {
            return null;
        }

        $meeting = $this->currentMeeting;
        if (! $meeting) {
            return null;
        }

        $totals = $meeting->attendees()
            ->selectRaw('COUNT(*) as total, SUM(present) as present_count, SUM(voting_rights AND present) as voting_count')
            ->first();

        return [
            'total'   => (int) $totals->total,
            'present' => (int) $totals->present_count,
            'voting'  => (int) $totals->voting_count,
        ];
    }

    public function setMeeting(int $id): void
    {
        $meeting = Meeting::find($id);

        if (! $meeting) {
            $this->meetingId = null;
            return;
        }

        $this->meetingId = $id;
        unset($this->currentMeeting, $this->selectableMeetings, $this->meetingStats);
    }

    public function clearMeeting(): void
    {
        $this->meetingId = null;
        unset($this->currentMeeting, $this->meetingStats);
    }

    public function checkInToMeeting(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $member = $this->member;
        if (! $member || ! $this->meetingId) {
            return;
        }

        $meeting = $this->currentMeeting;
        if (! $meeting) {
            return;
        }

        $isActive = $member->status === 'active';

        $attendee = $meeting->attendees()->firstOrCreate(
            ['member_id' => $member->id],
            [
                'role'                   => 'attendee',
                'invited'                => false,
                'present'                => true,
                'voting_rights'          => $isActive,
                'membership_verified_at' => now(),
                'membership_valid'       => $isActive,
            ]
        );

        if (! $attendee->wasRecentlyCreated && ! $attendee->present) {
            $attendee->update([
                'present'                => true,
                'membership_verified_at' => now(),
                'membership_valid'       => $isActive,
                'voting_rights'          => $isActive,
            ]);
        }

        AuditLog::record('meeting_attendee_checked_in', 'meetings', $meeting->title . ' — ' . $member->full_name, $meeting);

        $this->actionName   = $member->full_name;
        $this->justActioned = true;
        unset($this->meetingStats);
    }

    // ── Scan resolution ───────────────────────────────────────────────────────

    #[Computed]
    public function discountCode(): ?DiscountCode
    {
        return $this->resultType === ScanResult::TYPE_DISCOUNT && $this->modelId
            ? DiscountCode::with(['discount', 'redeemedBy'])->find($this->modelId)
            : null;
    }

    #[Computed]
    public function registration(): ?EventRegistration
    {
        return $this->resultType === ScanResult::TYPE_EVENT_REGISTRATION && $this->modelId
            ? EventRegistration::with(['event.occurrences', 'user', 'priceTier'])->find($this->modelId)
            : null;
    }

    #[Computed]
    public function member(): ?Member
    {
        return $this->resultType === ScanResult::TYPE_MEMBER && $this->modelId
            ? Member::with(['user'])->find($this->modelId)
            : null;
    }

    #[Computed]
    public function itemAddon(): ?EventRegistrationAddon
    {
        return $this->resultType === ScanResult::TYPE_ITEM_ADDON && $this->modelId
            ? EventRegistrationAddon::with(['addon', 'registration.user', 'registration.event'])->find($this->modelId)
            : null;
    }

    #[Computed]
    public function itemFulfillment(): ?OrderFulfillment
    {
        return $this->resultType === ScanResult::TYPE_ITEM_FULFILLMENT && $this->modelId
            ? OrderFulfillment::with(['order.user', 'orderLine'])->find($this->modelId)
            : null;
    }

    public function lookup(): void
    {
        $raw = trim($this->scanInput);
        $this->scanInput = '';
        $this->performLookup($raw);
    }

    /** Called directly from Alpine camera mode — skips the scanInput property. */
    public function lookupRaw(string $code): void
    {
        $this->performLookup($code);
    }

    private function performLookup(string $raw): void
    {
        $this->resetResult();

        if ($raw === '') {
            return;
        }

        $result = app(ScannerService::class)->resolve($raw, $this->currentEvent);

        if ($result->type === ScanResult::TYPE_EMPTY) {
            return;
        }

        $this->resultType   = $result->type;
        $this->resultStatus = $result->status;
        $this->modelId      = $result->model?->getKey();
        $this->message      = $result->message;

        unset($this->discountCode, $this->registration, $this->member);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    public function redeemDiscount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('payments.view'), 403);

        $code = $this->discountCode;

        if (! $code || ! $code->isAvailable()) {
            $this->message      = 'Koden är inte längre tillgänglig.';
            $this->resultStatus = ScanResult::STATUS_INVALID;
            return;
        }

        $code->redeem();

        AuditLog::record(
            'discount_code_redeemed',
            'discount',
            "Rabattkod \"{$code->code}\" inlöst via skanningsstation (rabatt: {$code->discount->name})"
        );

        $this->actionName   = $code->discount->name;
        $this->justActioned = true;
        $this->message      = null;
        unset($this->discountCode);
    }

    public function checkInRegistration(): void
    {
        abort_unless(
            Auth::user()->hasPermissionTo('events.manage-all') || Auth::user()->hasPermissionTo('events.checkin'),
            403
        );

        $reg = $this->registration;

        if (! $reg || $reg->status !== EventRegistration::STATUS_CONFIRMED) {
            $this->message      = 'Anmälan kan inte checkas in.';
            $this->resultStatus = ScanResult::STATUS_INVALID;
            return;
        }

        $occurrence = $reg->event->occurrences
            ->sortBy(fn ($o) => abs(now()->diffInSeconds($o->starts_at, false)))
            ->first();

        if (! $occurrence) {
            $this->message      = 'Evenemanget har inga tillfällen.';
            $this->resultStatus = ScanResult::STATUS_INVALID;
            return;
        }

        $attendance = EventRegistrationOccurrence::firstOrNew([
            'registration_id' => $reg->id,
            'occurrence_id'   => $occurrence->id,
        ]);
        $attendance->attended_at      = now();
        $attendance->checked_in_by_id = Auth::id();
        $attendance->save();

        AuditLog::record(
            'event_registration_checked_in',
            'event',
            "Incheckning för {$reg->user?->name} på {$reg->event->title} (tillfälle {$occurrence->starts_at->format('d/m Y H:i')})"
        );

        $this->actionName   = $reg->user?->name ?? $reg->guest_name ?? '';
        $this->justActioned = true;
        $this->message      = null;
        unset($this->registration);
    }

    public function registerWalkin(): void
    {
        abort_unless(
            Auth::user()->hasPermissionTo('events.manage-all')
            || Auth::user()->hasPermissionTo('events.checkin'),
            403
        );

        $event  = $this->currentEvent;
        $member = $this->member;

        if (! $event || ! $member) {
            return;
        }

        $reg = EventRegistration::create([
            'event_id'       => $event->id,
            'user_id'        => $member->user_id,
            'status'         => EventRegistration::STATUS_CONFIRMED,
            'is_walkin'      => true,
            'check_in_token' => strtolower(Str::random(32)),
        ]);

        $occurrence = $event->occurrences
            ->sortBy(fn ($o) => abs(now()->diffInSeconds($o->starts_at, false)))
            ->first();

        if ($occurrence) {
            EventRegistrationOccurrence::create([
                'registration_id'  => $reg->id,
                'occurrence_id'    => $occurrence->id,
                'attended_at'      => now(),
                'checked_in_by_id' => Auth::id(),
            ]);
        }

        AuditLog::record(
            'event_walkin_registered',
            'event',
            "Oanmäld incheckning: {$member->full_name} registrerades och checkades in på {$event->title}"
        );

        $this->actionName   = $member->full_name;
        $this->resultType   = ScanResult::TYPE_EVENT_REGISTRATION;
        $this->modelId      = $reg->id;
        $this->justActioned = true;
        $this->message      = null;
        unset($this->member);
    }

    public function redeemItem(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('payments.view'), 403);

        if ($this->resultType === ScanResult::TYPE_ITEM_ADDON) {
            $addon = $this->itemAddon;
            if (! $addon || $addon->isRedeemed()) {
                return;
            }
            $addon->update([
                'redeemed_at'     => now(),
                'redeemed_by_id'  => Auth::id(),
            ]);
            $name = $addon->registration->user?->name ?? '–';
            $label = ($addon->quantity > 1 ? $addon->quantity . '× ' : '') . $addon->addon->name;
            AuditLog::record('item_redeemed', 'event', "Tillval inlöst: {$label} för {$name}");
            $this->actionName   = "{$name} — {$label}";
            $this->justActioned = true;
            unset($this->itemAddon);
            return;
        }

        if ($this->resultType === ScanResult::TYPE_ITEM_FULFILLMENT) {
            $fulfillment = $this->itemFulfillment;
            if (! $fulfillment || $fulfillment->isPickedUp()) {
                return;
            }
            $fulfillment->update([
                'status'      => OrderFulfillment::STATUS_PICKED_UP,
                'picked_up_at' => now(),
            ]);
            $name  = $fulfillment->order->user?->name ?? '–';
            $label = $fulfillment->orderLine?->description ?? 'Vara';
            AuditLog::record('item_redeemed', 'shop', "Webbshop-vara hämtad: {$label} för {$name}");
            $this->actionName   = "{$name} — {$label}";
            $this->justActioned = true;
            unset($this->itemFulfillment);
        }
    }

    /** Generates a new pairing session and shows its QR for a remote device to scan. */
    public function startPairing(): void
    {
        $this->pairingSessionId = app(ScannerRelayStore::class)->createSession();
        $this->dispatch('scanner-relay-started', session: $this->pairingSessionId);
    }

    public function stopPairing(): void
    {
        $this->pairingSessionId = null;
        $this->dispatch('scanner-relay-stopped');
    }

    public function resetResult(): void
    {
        $this->resultType   = null;
        $this->resultStatus = null;
        $this->modelId      = null;
        $this->message      = null;
        $this->justActioned = false;
        $this->actionName   = null;
        unset($this->discountCode, $this->registration, $this->member, $this->itemAddon, $this->itemFulfillment);
        $this->dispatch('scanner-result-cleared');
    }
}; ?>

{{--
  localStorage key: scanner_event_id
  URL param:        ?event=123  → pre-selects event and saves to localStorage
--}}
<div
    x-data="{
        // ── localStorage / URL event persistence ──────────────────────────────
        init() {
            const urlParam = new URLSearchParams(window.location.search).get('event');
            if (urlParam) {
                const id = parseInt(urlParam);
                localStorage.setItem('scanner_event_id', id);
                $wire.setEvent(id);
                history.replaceState({}, '', window.location.pathname);
                return;
            }
            const stored = localStorage.getItem('scanner_event_id');
            if (stored) $wire.setEvent(parseInt(stored));
        },

        // ── Camera QR scanning ────────────────────────────────────────────────
        cameraOn:   false,
        controls:   null,
        camError:   null,

        async startCamera() {
            this.camError = null;
            try {
                this.controls = await window.startQrCamera(
                    this.$refs.camVideo,
                    (code) => {
                        this.stopCamera();
                        $wire.lookupRaw(code);
                    }
                );
            } catch (e) {
                this.camError = 'Kameran kunde inte startas. Kontrollera behörigheter.';
                this.cameraOn = false;
            }
        },

        stopCamera() {
            this.controls?.stop();
            this.controls = null;
            this.cameraOn = false;
        },

        toggleCamera() {
            if (this.cameraOn) {
                this.stopCamera();
                this.$nextTick(() => this.$refs.scanInput?.focus());
            } else {
                this.cameraOn = true;
                this.$nextTick(() => this.startCamera());
            }
        },

        // ── Remote (paired) scanner polling ─────────────────────────────────────
        remoteCursor:    0,
        remoteConnected: false,
        remotePollTimer: null,

        startRemotePolling(sessionId) {
            this.stopRemotePolling();
            this.remoteCursor = 0;
            this.remoteConnected = false;
            const poll = () => {
                fetch(`{{ url('/admin/scanna/parkoppling') }}/${sessionId}/poll?after=${this.remoteCursor}`, {
                    headers: { Accept: 'application/json' },
                })
                    .then(r => r.ok ? r.json() : Promise.reject(r.status))
                    .then(data => {
                        this.remoteConnected = data.device_connected;
                        this.remoteCursor = data.cursor;
                        for (const entry of data.codes) {
                            $wire.lookupRaw(entry.code);
                        }
                    })
                    .catch(() => { this.remoteConnected = false; });
            };
            poll();
            this.remotePollTimer = setInterval(poll, 3000);
        },

        stopRemotePolling() {
            if (this.remotePollTimer) clearInterval(this.remotePollTimer);
            this.remotePollTimer = null;
            this.remoteConnected = false;
        }
    }"
    x-on:scanner-event-stored.window="localStorage.setItem('scanner_event_id', $event.detail.id)"
    x-on:scanner-event-expired.window="localStorage.removeItem('scanner_event_id')"
    x-on:scanner-result-cleared.window="if (cameraOn) { $nextTick(() => startCamera()) }"
    x-on:scanner-relay-started.window="startRemotePolling($event.detail.session)"
    x-on:scanner-relay-stopped.window="stopRemotePolling()"
    x-init="$nextTick(() => $refs.scanInput?.focus())"
>

    {{-- ── Event selector ────────────────────────────────────────────────────── --}}
    @if($eventId && $this->currentEvent)
        {{-- Active event banner --}}
        @php
            $stats   = $this->eventStats;
            $nextOcc = $this->currentEvent->occurrences->sortBy('starts_at')->first(fn ($o) => ! $o->starts_at->subHours(6)->isPast());
        @endphp
        <div class="mb-5 overflow-hidden rounded-2xl border border-complement/30 bg-complement/10 dark:border-complement-light/20 dark:bg-complement/10">
            <div class="flex items-center justify-between px-5 py-3">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-widest text-complement dark:text-complement-light">Dörrstation</p>
                    <p class="truncate text-base font-bold text-zinc-900 dark:text-white">{{ $this->currentEvent->title }}</p>
                    @if($nextOcc)
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $nextOcc->starts_at->isoFormat('D MMM, HH:mm') }}</p>
                    @endif
                </div>
                <button wire:click="clearEvent" class="btn btn-ghost btn-sm ml-4 shrink-0 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200">
                    <x-bootstrap-icon name="x-lg" class="size-3.5" />
                    Byt
                </button>
            </div>

            @if($stats)
                <div class="flex flex-wrap gap-x-6 gap-y-1 border-t border-complement/20 bg-white/40 px-5 py-2.5 text-sm dark:bg-black/20">
                    <span class="text-zinc-600 dark:text-zinc-400">
                        Anmälda: <strong class="text-zinc-900 dark:text-white">{{ $stats['confirmed'] }}</strong>
                        @if($stats['walkins'] > 0)
                            <span class="text-xs text-amber-600 dark:text-amber-400">({{ $stats['walkins'] }} oanmälda)</span>
                        @endif
                    </span>
                    @if($stats['capacity'] !== null)
                        <span class="text-zinc-600 dark:text-zinc-400">
                            Platser: <strong class="text-zinc-900 dark:text-white">{{ $stats['capacity'] }}</strong>
                        </span>
                        <span class="{{ $stats['remaining'] > 0 ? 'text-green-700 dark:text-green-400' : 'text-red-600 dark:text-red-400' }} font-semibold">
                            @if($stats['remaining'] > 0)
                                {{ $stats['remaining'] }} kvar
                            @else
                                Fullt
                            @endif
                        </span>
                    @else
                        <span class="text-zinc-400 dark:text-zinc-500">Obegränsad kapacitet</span>
                    @endif
                </div>
            @endif
        </div>
    @else
        {{-- Event picker --}}
        <div class="mb-5 overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-100 bg-zinc-50 px-6 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <p class="text-center text-xs font-semibold uppercase tracking-widest text-zinc-400 dark:text-zinc-500">
                    Välj evenemang (valfritt)
                </p>
            </div>
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse($this->selectableEvents as $ev)
                    @php $firstOcc = $ev->occurrences->first(); @endphp
                    <button wire:click="setEvent({{ $ev->id }})"
                            class="flex w-full items-center gap-4 px-5 py-3 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <x-bootstrap-icon name="calendar-event" class="size-4 shrink-0 text-zinc-400" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $ev->title }}</p>
                            @if($firstOcc)
                                <p class="text-xs text-zinc-400">{{ $firstOcc->starts_at->isoFormat('D MMM, HH:mm') }}</p>
                            @endif
                        </div>
                        <x-bootstrap-icon name="chevron-right" class="size-3.5 shrink-0 text-zinc-300" />
                    </button>
                @empty
                    <p class="px-5 py-3 text-sm text-zinc-400 dark:text-zinc-500">Inga evenemang de närmaste dagarna.</p>
                @endforelse
            </div>
            <div class="border-t border-zinc-100 bg-zinc-50 px-5 py-2.5 dark:border-zinc-800 dark:bg-zinc-800">
                <p class="text-xs text-zinc-400 dark:text-zinc-500">
                    Välj ett evenemang för att aktivera RFID-tagg och inmatning av medlemsnummer. Utan val fungerar stationen för rabattkoder och QR-biljetter.
                </p>
            </div>
        </div>
    @endif

    {{-- ── Meeting selector ───────────────────────────────────────────────────── --}}
    @if($meetingId && $this->currentMeeting)
        {{-- Active meeting banner --}}
        @php $mstats = $this->meetingStats; @endphp
        <div class="mb-5 overflow-hidden rounded-2xl border border-brand/30 bg-brand/10 dark:border-brand/20 dark:bg-brand/5">
            <div class="flex items-center justify-between px-5 py-3">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-widest text-brand-orange dark:text-brand">Mötesstation</p>
                    <p class="truncate text-base font-bold text-zinc-900 dark:text-white">{{ $this->currentMeeting->title }}</p>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $this->currentMeeting->scheduled_at->isoFormat('D MMM YYYY, HH:mm') }}</p>
                </div>
                <button wire:click="clearMeeting" class="btn btn-ghost btn-sm ml-4 shrink-0 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200">
                    <x-bootstrap-icon name="x-lg" class="size-3.5" />
                    Byt
                </button>
            </div>

            @if($mstats)
                <div class="flex flex-wrap gap-x-6 gap-y-1 border-t border-brand/20 bg-white/40 px-5 py-2.5 text-sm dark:bg-black/20">
                    <span class="text-zinc-600 dark:text-zinc-400">
                        Närvarande: <strong class="text-zinc-900 dark:text-white">{{ $mstats['present'] }}</strong>
                        <span class="text-xs text-zinc-400 dark:text-zinc-500">/ {{ $mstats['total'] }} registrerade</span>
                    </span>
                    <span class="text-zinc-600 dark:text-zinc-400">
                        Röstberättigade: <strong class="text-zinc-900 dark:text-white">{{ $mstats['voting'] }}</strong>
                    </span>
                </div>
            @endif
        </div>
    @elseif(! $meetingId)
        {{-- Meeting picker --}}
        <div class="mb-5 overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-100 bg-zinc-50 px-6 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <p class="text-center text-xs font-semibold uppercase tracking-widest text-zinc-400 dark:text-zinc-500">
                    Välj möte (valfritt)
                </p>
            </div>
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse($this->selectableMeetings as $mt)
                    <button wire:click="setMeeting({{ $mt->id }})"
                            class="flex w-full items-center gap-4 px-5 py-3 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <x-bootstrap-icon name="people-fill" class="size-4 shrink-0 text-zinc-400" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $mt->title }}</p>
                            <p class="text-xs text-zinc-400">{{ $mt->scheduled_at->isoFormat('D MMM YYYY, HH:mm') }}</p>
                        </div>
                        <x-bootstrap-icon name="chevron-right" class="size-3.5 shrink-0 text-zinc-300" />
                    </button>
                @empty
                    <p class="px-5 py-3 text-sm text-zinc-400 dark:text-zinc-500">Inga möten de närmaste dagarna.</p>
                @endforelse
            </div>
            <div class="border-t border-zinc-100 bg-zinc-50 px-5 py-2.5 dark:border-zinc-800 dark:bg-zinc-800">
                <p class="text-xs text-zinc-400 dark:text-zinc-500">
                    Välj ett möte för att aktivera QR-incheckning med närvaro- och rösträktefunktion.
                </p>
            </div>
        </div>
    @endif

    {{-- ── Camera QR panel ────────────────────────────────────────────────────── --}}
    <div x-show="cameraOn" x-cloak class="mb-5 overflow-hidden rounded-2xl border border-zinc-200 shadow-sm dark:border-zinc-700">
        <div class="relative bg-black">
            {{-- Live camera feed --}}
            <video x-ref="camVideo"
                   autoplay playsinline muted
                   class="aspect-square w-full object-cover sm:aspect-video"></video>

            {{-- Scanning overlay --}}
            <div class="pointer-events-none absolute inset-0 flex items-center justify-center">
                <div class="relative size-52">
                    {{-- Corner brackets --}}
                    <span class="absolute left-0 top-0 h-8 w-8 rounded-tl-lg border-l-4 border-t-4 border-brand"></span>
                    <span class="absolute right-0 top-0 h-8 w-8 rounded-tr-lg border-r-4 border-t-4 border-brand"></span>
                    <span class="absolute bottom-0 left-0 h-8 w-8 rounded-bl-lg border-b-4 border-l-4 border-brand"></span>
                    <span class="absolute bottom-0 right-0 h-8 w-8 rounded-br-lg border-b-4 border-r-4 border-brand"></span>
                    {{-- Scan line animation --}}
                    <span class="absolute inset-x-2 h-0.5 animate-[scan_2s_ease-in-out_infinite] bg-brand/70"></span>
                </div>
            </div>

            {{-- Instruction text --}}
            <div class="absolute bottom-0 inset-x-0 bg-gradient-to-t from-black/70 to-transparent px-4 pb-3 pt-6 text-center">
                <p class="text-sm font-medium text-white/80">Rikta mot QR-kod på biljett…</p>
            </div>
        </div>

        {{-- Camera error --}}
        <div x-show="camError" x-text="camError"
             class="bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-400"></div>

        <div class="flex justify-end border-t border-zinc-100 bg-zinc-50 px-4 py-2.5 dark:border-zinc-800 dark:bg-zinc-800">
            <button @click="toggleCamera()" class="btn btn-ghost btn-sm text-zinc-500">
                <x-bootstrap-icon name="x-lg" class="size-3.5" />
                Stäng kamera
            </button>
        </div>
    </div>

    {{-- ── Remote scanner pairing panel ───────────────────────────────────────── --}}
    @if($pairingSessionId)
        <div class="mb-5 overflow-hidden rounded-2xl border border-zinc-200 shadow-sm dark:border-zinc-700">
            <div class="flex items-center justify-between bg-zinc-50 px-6 py-3 dark:bg-zinc-800">
                <p class="text-xs font-semibold uppercase tracking-widest text-zinc-400 dark:text-zinc-500">
                    Fjärrskanner
                </p>
                <span class="flex items-center gap-1.5 text-xs font-medium"
                      :class="remoteConnected ? 'text-green-600 dark:text-green-400' : 'text-zinc-400 dark:text-zinc-500'">
                    <span class="size-2 rounded-full" :class="remoteConnected ? 'bg-green-500' : 'bg-zinc-300 dark:bg-zinc-600'"></span>
                    <span x-text="remoteConnected ? 'Ansluten' : 'Väntar på enhet…'"></span>
                </span>
            </div>
            <div class="flex flex-col items-center gap-3 p-6">
                <img src="{{ route('scanner-relay.qr', $pairingSessionId) }}" alt="Parkopplingskod" class="size-40 rounded-lg border border-zinc-200 dark:border-zinc-700">
                <p class="text-center text-sm text-zinc-500 dark:text-zinc-400">
                    Skanna koden med enhetens läge <strong>Skannerstation</strong> för att koppla den till den här datorn.
                </p>
                <button wire:click="stopPairing" class="btn btn-ghost btn-sm text-zinc-500">
                    <x-bootstrap-icon name="x-lg" class="size-3.5" />
                    Avsluta parkoppling
                </button>
            </div>
        </div>
    @endif

    {{-- ── Scan input ─────────────────────────────────────────────────────────── --}}
    <div class="overflow-hidden rounded-2xl border border-zinc-200 shadow-sm dark:border-zinc-700">
        <div class="bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
            <p class="text-center text-xs font-semibold uppercase tracking-widest text-zinc-400 dark:text-zinc-500">
                @if($eventId)
                    Skanna biljett, tagg eller ange medlemsnr
                @else
                    Skanna eller skriv kod
                @endif
            </p>
        </div>
        <div class="p-6">
            <div class="flex gap-3">
                <input
                    x-ref="scanInput"
                    x-show="!cameraOn"
                    wire:model="scanInput"
                    wire:keydown.enter="lookup"
                    type="text"
                    placeholder="{{ $eventId ? 'Kod, tagg-UID eller medlemsnr…' : 'Skanna eller skriv kod…' }}"
                    autocomplete="off"
                    autocorrect="off"
                    spellcheck="false"
                    class="inp flex-1 text-center font-mono text-xl uppercase tracking-widest"
                />
                <button x-show="!cameraOn" wire:click="lookup" class="btn btn-primary">
                    <x-bootstrap-icon name="search" class="size-5" />
                </button>
                <button @click="toggleCamera()"
                        :class="cameraOn ? 'btn btn-primary flex-1 justify-center' : 'btn btn-ghost'"
                        class="btn">
                    <x-bootstrap-icon name="camera" class="size-5" />
                    <span x-show="cameraOn" class="ml-1.5">Kameraläge på</span>
                </button>
                @if(! $pairingSessionId)
                    <button x-show="!cameraOn" wire:click="startPairing" class="btn btn-ghost" title="Parkoppla fjärrskanner">
                        <x-bootstrap-icon name="wifi" class="size-5" />
                    </button>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Error / not found ──────────────────────────────────────────────────── --}}
    @if($resultStatus === 'already_used' || ($resultStatus === 'invalid' && $resultType !== 'member') || $resultType === 'not_found')
        <div class="mt-5 flex flex-col items-center gap-4 rounded-2xl border border-red-200 bg-red-50 px-8 py-8 text-center dark:border-red-900/40 dark:bg-red-900/20"
             x-init="setTimeout(() => $wire.resetResult(), 6000)">
            <x-bootstrap-icon name="x-circle-fill" class="size-14 text-red-500" />
            <div>
                <p class="text-2xl font-bold text-red-700 dark:text-red-400">
                    {{ $resultStatus === 'already_used' ? 'Redan använd' : 'Ogiltig kod' }}
                </p>
                @if($message)
                    <p class="mt-2 text-sm text-red-600 dark:text-red-300">{{ $message }}</p>
                @endif
            </div>
            <button wire:click="resetResult" class="mt-2 text-xs text-red-400 underline hover:text-red-600">
                Ny sökning
            </button>
        </div>
    @endif

    {{-- ── Success ────────────────────────────────────────────────────────────── --}}
    @if($justActioned)
        <div class="mt-5 flex flex-col items-center gap-4 rounded-2xl border border-green-200 bg-green-50 px-8 py-10 text-center dark:border-green-900/40 dark:bg-green-900/20"
             x-init="setTimeout(() => $wire.resetResult(), 6000)">
            <x-bootstrap-icon name="check-circle-fill" class="size-16 text-green-500" />
            <div>
                @if($resultType === 'discount_code')
                    <p class="text-3xl font-bold text-green-700 dark:text-green-400">Rabatt godkänd!</p>
                    @if($actionName)
                        <p class="mt-2 text-lg font-semibold text-green-600 dark:text-green-300">{{ $actionName }}</p>
                    @endif
                @else
                    <p class="text-3xl font-bold text-green-700 dark:text-green-400">Välkommen!</p>
                    @if($actionName)
                        <p class="mt-2 text-xl font-semibold text-green-600 dark:text-green-300">{{ $actionName }}</p>
                    @endif
                    @if($this->currentEvent)
                        <p class="mt-1 text-sm text-green-500">{{ $this->currentEvent->title }}</p>
                    @endif
                @endif
            </div>
            <p class="text-xs text-green-400">Återgår automatiskt…</p>
        </div>
    @endif

    {{-- ── Result: discount code — available ─────────────────────────────────── --}}
    @if($resultType === 'discount_code' && $resultStatus === 'available' && ! $justActioned)
        @php
            $c       = $this->discountCode;
            $scanner = app(ScannerService::class);
            $ctaUrl  = $c ? $scanner->ctaUrl($c->discount, $c->code) : null;
        @endphp
        <div class="mt-5 overflow-hidden rounded-2xl border border-zinc-200 shadow-sm dark:border-zinc-700">
            <div class="flex items-center justify-center gap-2 bg-brand/10 px-6 py-4 dark:bg-brand/10">
                <x-bootstrap-icon name="tag-fill" class="size-5 text-brand-orange" />
                <span class="font-semibold text-zinc-800 dark:text-zinc-200">Rabattkod</span>
            </div>

            @if($c)
                <div class="px-6 py-6">
                    <div class="mb-5 text-center">
                        <p class="font-mono text-3xl font-bold tracking-widest text-zinc-900 dark:text-white">{{ $c->code }}</p>
                        <p class="mt-1 text-base text-zinc-500 dark:text-zinc-400">{{ $c->discount->name }}</p>
                    </div>

                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <dt class="text-zinc-500 dark:text-zinc-400">Gäller för</dt>
                        <dd class="font-medium text-zinc-900 dark:text-white">{{ $scanner->targetLabel($c->discount) }}</dd>

                        <dt class="text-zinc-500 dark:text-zinc-400">Rabatt</dt>
                        <dd class="font-semibold text-zinc-900 dark:text-white">
                            @if($c->discount->type === 'percentage')
                                {{ $c->discount->value }} %
                            @else
                                {{ number_format($c->discount->value / 100, 2, ',', ' ') }} kr
                            @endif
                        </dd>

                        <dt class="text-zinc-500 dark:text-zinc-400">Typ</dt>
                        <dd class="text-zinc-900 dark:text-white">{{ $c->is_single_use ? 'Engångskod' : 'Delad kod' }}</dd>

                        @if($c->discount->valid_until)
                            <dt class="text-zinc-500 dark:text-zinc-400">Giltig till</dt>
                            <dd class="text-zinc-900 dark:text-white">{{ $c->discount->valid_until->format('d/m Y') }}</dd>
                        @endif
                    </dl>

                    @if($ctaUrl)
                        <div class="mt-5 rounded-xl bg-zinc-50 px-4 py-3 dark:bg-zinc-800">
                            <p class="mb-2 text-xs text-zinc-400">Koden fylls i automatiskt när kunden klickar på länken nedan.</p>
                            <a href="{{ $ctaUrl }}" target="_blank" class="btn btn-primary w-full justify-center text-base">
                                {{ $scanner->ctaLabel($c->discount) }}
                            </a>
                        </div>
                    @endif

                    <div class="mt-5 flex items-center justify-between border-t border-zinc-100 pt-4 dark:border-zinc-700">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
                            <x-bootstrap-icon name="circle-fill" class="size-2" />
                            Tillgänglig
                        </span>
                        <div class="flex gap-2">
                            <button wire:click="resetResult" class="btn text-sm">Avbryt</button>
                            <button wire:click="redeemDiscount" class="btn btn-primary">
                                <x-bootstrap-icon name="check2" class="size-4" />
                                {{ $c->is_single_use ? 'Lös in' : 'OK – giltig' }}
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- ── Result: event registration — confirmed ─────────────────────────────── --}}
    @if($resultType === 'event_registration' && $resultStatus === 'available' && ! $justActioned)
        @php $r = $this->registration; @endphp
        <div class="mt-5 overflow-hidden rounded-2xl border border-zinc-200 shadow-sm dark:border-zinc-700">
            <div class="flex items-center justify-center gap-2 bg-green-50 px-6 py-4 dark:bg-green-900/20">
                <x-bootstrap-icon name="ticket-fill" class="size-5 text-green-600 dark:text-green-400" />
                <span class="font-semibold text-zinc-800 dark:text-zinc-200">Föranmäld</span>
            </div>
            @if($r)
                <div class="px-6 py-6">
                    <div class="mb-5 text-center">
                        <p class="text-3xl font-bold text-zinc-900 dark:text-white">
                            {{ $r->user?->name ?? $r->guest_name ?? '–' }}
                        </p>
                        <p class="mt-1 text-base text-zinc-500 dark:text-zinc-400">{{ $r->event->title }}</p>
                        @if($r->priceTier)
                            <span class="mt-2 inline-block rounded-full bg-zinc-100 px-3 py-0.5 text-sm text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                {{ $r->priceTier->label }}
                            </span>
                        @endif
                    </div>
                    @php $firstOcc = $r->event->occurrences->first(); @endphp
                    @if($firstOcc)
                        <p class="text-center text-sm text-zinc-400 dark:text-zinc-500">
                            {{ $firstOcc->starts_at->isoFormat('D MMM YYYY, HH:mm') }}
                        </p>
                    @endif
                    <div class="mt-5 flex items-center justify-between border-t border-zinc-100 pt-4 dark:border-zinc-700">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
                            <x-bootstrap-icon name="circle-fill" class="size-2" />
                            Bekräftad
                        </span>
                        <div class="flex gap-2">
                            <button wire:click="resetResult" class="btn text-sm">Avbryt</button>
                            <button wire:click="checkInRegistration" class="btn btn-primary">
                                <x-bootstrap-icon name="check2" class="size-4" />
                                Checka in
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- ── Result: member found — not registered (walk-in candidate) ─────────── --}}
    @if($resultType === 'member' && $resultStatus === 'not_registered' && ! $justActioned)
        @php $m = $this->member; @endphp
        <div class="mt-5 overflow-hidden rounded-2xl border border-amber-200 shadow-sm dark:border-amber-700/40">
            <div class="flex items-center justify-center gap-2 bg-amber-50 px-6 py-4 dark:bg-amber-900/20">
                <x-bootstrap-icon name="person-fill-exclamation" class="size-5 text-amber-600 dark:text-amber-400" />
                <span class="font-semibold text-zinc-800 dark:text-zinc-200">Ej föranmäld</span>
            </div>
            @if($m)
                <div class="px-6 py-6">
                    <div class="mb-5 text-center">
                        <p class="text-3xl font-bold text-zinc-900 dark:text-white">{{ $m->full_name }}</p>
                        <p class="mt-1 text-sm text-zinc-400 dark:text-zinc-500">Membre #{{ $m->membership_number }}</p>
                    </div>
                    <p class="text-center text-sm text-amber-700 dark:text-amber-400">
                        {{ $m->full_name }} har ingen föranmälan till {{ $this->currentEvent?->title }}.
                        Registrera som oanmäld deltagare?
                    </p>
                    <div class="mt-5 flex items-center justify-between border-t border-zinc-100 pt-4 dark:border-zinc-700">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                            <x-bootstrap-icon name="circle-fill" class="size-2" />
                            Oanmäld
                        </span>
                        <div class="flex gap-2">
                            <button wire:click="resetResult" class="btn text-sm">Avbryt</button>
                            <button wire:click="registerWalkin" class="btn btn-primary">
                                <x-bootstrap-icon name="person-fill-add" class="size-4" />
                                Registrera &amp; checka in
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- ── Result: member — verified (valid QR, no event selected) ─────────── --}}
    @if($resultType === 'member' && $resultStatus === 'available' && ! $justActioned)
        @php $m = $this->member; @endphp
        <div class="mt-5 overflow-hidden rounded-2xl border border-green-200 shadow-sm dark:border-green-700/40"
             x-init="setTimeout(() => $wire.resetResult(), 8000)">
            <div class="flex items-center justify-center gap-2 bg-green-50 px-6 py-4 dark:bg-green-900/20">
                <x-bootstrap-icon name="person-check-fill" class="size-5 text-green-600 dark:text-green-400" />
                <span class="font-semibold text-zinc-800 dark:text-zinc-200">Verifierad medlem</span>
            </div>
            @if($m)
                <div class="px-6 py-6">
                    <div class="mb-5 text-center">
                        <p class="text-3xl font-bold text-zinc-900 dark:text-white">{{ $m->full_name }}</p>
                        <p class="mt-1 text-sm text-zinc-400 dark:text-zinc-500">Medlem #{{ $m->membership_number }}</p>
                        @if(! $m->isActive())
                            <span class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                <x-bootstrap-icon name="exclamation-triangle-fill" class="size-3" />
                                Ej aktiv — ingen rösträtt
                            </span>
                        @endif
                    </div>
                    <div class="mt-5 flex items-center justify-between border-t border-zinc-100 pt-4 dark:border-zinc-700">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
                            <x-bootstrap-icon name="circle-fill" class="size-2" />
                            Giltig QR-kod
                        </span>
                        <div class="flex gap-2">
                            <button wire:click="resetResult" class="btn text-sm">Ny sökning</button>
                            @if($meetingId)
                                <button wire:click="checkInToMeeting" class="btn btn-primary">
                                    <x-bootstrap-icon name="people-fill" class="size-4" />
                                    Checka in på mötet
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- ── Result: member card QR — expired ────────────────────────────────── --}}
    @if($resultType === 'member' && $resultStatus === 'expired' && ! $justActioned)
        @php $m = $this->member; @endphp
        <div class="mt-5 overflow-hidden rounded-2xl border border-amber-200 shadow-sm dark:border-amber-700/40"
             x-init="setTimeout(() => $wire.resetResult(), 10000)">
            {{-- Expired banner --}}
            <div class="flex items-center justify-center gap-2 bg-amber-50 px-6 py-4 dark:bg-amber-900/20">
                <x-bootstrap-icon name="clock-history" class="size-5 text-amber-600 dark:text-amber-400" />
                <span class="font-semibold text-amber-800 dark:text-amber-300">QR-koden har gått ut</span>
            </div>
            <div class="bg-amber-50/50 px-6 py-3 dark:bg-amber-900/10">
                <p class="text-center text-sm text-amber-700 dark:text-amber-400">
                    Be medlemmen öppna appen igen och visa en ny QR-kod.
                </p>
            </div>
            @if($m)
                <div class="px-6 py-6">
                    <div class="mb-5 text-center">
                        <p class="text-3xl font-bold text-zinc-900 dark:text-white">{{ $m->full_name }}</p>
                        <p class="mt-1 text-sm text-zinc-400 dark:text-zinc-500">Medlem #{{ $m->membership_number }}</p>
                    </div>
                    <div class="mt-5 flex items-center justify-between border-t border-zinc-100 pt-4 dark:border-zinc-700">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                            <x-bootstrap-icon name="clock" class="size-3" />
                            Utgången kod
                        </span>
                        <button wire:click="resetResult" class="btn text-sm">Ny sökning</button>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- ── Result: event add-on — available ──────────────────────────────────── --}}
    @if($resultType === ScanResult::TYPE_ITEM_ADDON && $resultStatus === ScanResult::STATUS_AVAILABLE && ! $justActioned)
        @php $a = $this->itemAddon; @endphp
        <div class="mt-5 overflow-hidden rounded-2xl border border-zinc-200 shadow-sm dark:border-zinc-700">
            <div class="flex items-center justify-center gap-2 bg-complement/10 px-6 py-4 dark:bg-complement/10">
                <x-bootstrap-icon name="bag-check-fill" class="size-5 text-complement dark:text-complement-light" />
                <span class="font-semibold text-zinc-800 dark:text-zinc-200">Tillval</span>
            </div>
            @if($a)
                <div class="px-6 py-6">
                    <div class="mb-4 text-center">
                        <p class="text-3xl font-bold text-zinc-900 dark:text-white">
                            {{ $a->registration->user?->name ?? '–' }}
                        </p>
                        <p class="mt-2 text-xl font-semibold text-complement dark:text-complement-light">
                            @if($a->quantity > 1) {{ $a->quantity }}× @endif
                            {{ $a->addon->name }}
                            @if($a->option_value)
                                <span class="text-base font-normal text-zinc-400">({{ $a->option_value }})</span>
                            @endif
                        </p>
                        <p class="mt-1 text-sm text-zinc-400 dark:text-zinc-500">
                            {{ $a->registration->event->title }}
                        </p>
                    </div>
                    <div class="mt-5 flex items-center justify-between border-t border-zinc-100 pt-4 dark:border-zinc-700">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
                            <x-bootstrap-icon name="circle-fill" class="size-2" />
                            Ej inlöst
                        </span>
                        <div class="flex gap-2">
                            <button wire:click="resetResult" class="btn text-sm">Avbryt</button>
                            <button wire:click="redeemItem" class="btn btn-primary">
                                <x-bootstrap-icon name="check2" class="size-4" />
                                Lämna ut
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- ── Result: webshop fulfillment — available ────────────────────────────── --}}
    @if($resultType === ScanResult::TYPE_ITEM_FULFILLMENT && $resultStatus === ScanResult::STATUS_AVAILABLE && ! $justActioned)
        @php $f = $this->itemFulfillment; @endphp
        <div class="mt-5 overflow-hidden rounded-2xl border border-zinc-200 shadow-sm dark:border-zinc-700">
            <div class="flex items-center justify-center gap-2 bg-complement/10 px-6 py-4 dark:bg-complement/10">
                <x-bootstrap-icon name="box-seam-fill" class="size-5 text-complement dark:text-complement-light" />
                <span class="font-semibold text-zinc-800 dark:text-zinc-200">Webbshop</span>
            </div>
            @if($f)
                <div class="px-6 py-6">
                    <div class="mb-4 text-center">
                        <p class="text-3xl font-bold text-zinc-900 dark:text-white">
                            {{ $f->order->user?->name ?? '–' }}
                        </p>
                        <p class="mt-2 text-lg font-semibold text-complement dark:text-complement-light">
                            {{ $f->orderLine?->description ?? 'Vara' }}
                        </p>
                        @if($f->orderLine?->quantity > 1)
                            <p class="text-sm text-zinc-400">Antal: {{ $f->orderLine->quantity }}</p>
                        @endif
                    </div>
                    <div class="mt-5 flex items-center justify-between border-t border-zinc-100 pt-4 dark:border-zinc-700">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
                            <x-bootstrap-icon name="circle-fill" class="size-2" />
                            Ej hämtad
                        </span>
                        <div class="flex gap-2">
                            <button wire:click="resetResult" class="btn text-sm">Avbryt</button>
                            <button wire:click="redeemItem" class="btn btn-primary">
                                <x-bootstrap-icon name="check2" class="size-4" />
                                Lämna ut
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif

</div>
