<?php

use App\Mail\EventRegistrationMail;
use App\Models\AuditLog;
use App\Models\Events\Event;
use App\Models\Events\EventPriceTier;
use App\Models\Events\EventRegistration;
use App\Models\Events\EventRegistrationOccurrence;
use App\Models\Membership\Member;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Antagning');

new class extends Component {

    public Event $event;

    /** Selected registration IDs in the pending-approval list. */
    public array $selectedPending = [];

    /** How many from the waitlist to admit in one batch. */
    public int $admitCount = 1;

    /** Flash message after bulk action. */
    public ?string $flash = null;

    /** Walk-in member search */
    public string  $walkinQuery  = '';
    public ?int    $walkinMemberId = null;
    public ?string $walkinFlash  = null;

    public function mount(Event $event): void
    {
        abort_unless(Auth::user()->hasPermissionTo('events.manage-all')
            || (Auth::user()->member && $event->isManagedBy(Auth::user()->member)), 403);

        $this->event = $event;
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    #[Computed]
    public function pendingApproval()
    {
        return $this->event->registrations()
            ->with(['user', 'priceTier'])
            ->pendingApproval()
            ->orderBy('created_at')
            ->get();
    }

    #[Computed]
    public function waitlisted()
    {
        return $this->event->registrations()
            ->with(['user', 'priceTier'])
            ->waitlisted()
            ->orderBy('created_at')
            ->get();
    }

    #[Computed]
    public function confirmedCount(): int
    {
        return $this->event->registrations()->confirmed()->count();
    }

    /** Total capacity across all price tiers. null = unlimited. */
    #[Computed]
    public function totalCapacity(): ?int
    {
        $tiers = $this->event->priceTiers;
        if ($tiers->whereNull('max_count')->isNotEmpty()) {
            return null;
        }
        return $tiers->sum('max_count');
    }

    /** Remaining spots. null = unlimited. */
    #[Computed]
    public function spotsRemaining(): ?int
    {
        if ($this->totalCapacity === null) {
            return null;
        }
        return max(0, $this->totalCapacity - $this->confirmedCount);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    public function approveSelected(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('events.manage-all')
            || (Auth::user()->member && $this->event->isManagedBy(Auth::user()->member)), 403);

        if (empty($this->selectedPending)) {
            return;
        }

        $registrations = $this->event->registrations()
            ->pendingApproval()
            ->whereIn('id', $this->selectedPending)
            ->with('order')
            ->get();

        foreach ($registrations as $reg) {
            $reg->approve();
            EventRegistrationMail::sendFor($reg->fresh());
        }

        $count = $registrations->count();
        $this->selectedPending = [];
        $this->flash = "{$count} " . ($count === 1 ? 'anmälan godkänd.' : 'anmälningar godkända.');

        unset($this->pendingApproval);
    }

    public function rejectSelected(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('events.manage-all')
            || (Auth::user()->member && $this->event->isManagedBy(Auth::user()->member)), 403);

        if (empty($this->selectedPending)) {
            return;
        }

        $registrations = $this->event->registrations()
            ->pendingApproval()
            ->whereIn('id', $this->selectedPending)
            ->get();

        foreach ($registrations as $reg) {
            $reg->reject();
            EventRegistrationMail::sendFor($reg->fresh());
        }

        $count = $registrations->count();
        $this->selectedPending = [];
        $this->flash = "{$count} " . ($count === 1 ? 'anmälan avvisad.' : 'anmälningar avvisade.');

        unset($this->pendingApproval);
    }

    public function admitFromWaitlist(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('events.manage-all')
            || (Auth::user()->member && $this->event->isManagedBy(Auth::user()->member)), 403);

        $toAdmit = max(1, (int) $this->admitCount);

        $waitlisted = $this->event->registrations()
            ->waitlisted()
            ->with('order')
            ->orderBy('created_at')
            ->limit($toAdmit)
            ->get();

        $admitted = 0;

        foreach ($waitlisted as $reg) {
            $newStatus = match ($this->event->admission_type) {
                Event::ADMISSION_AUTOMATIC => EventRegistration::STATUS_CONFIRMED,
                Event::ADMISSION_MANUAL    => EventRegistration::STATUS_PENDING_APPROVAL,
                default                    => ($reg->order?->total_ore ?? 0) > 0
                    ? EventRegistration::STATUS_PENDING_PAYMENT
                    : EventRegistration::STATUS_CONFIRMED,
            };

            $reg->update(['status' => $newStatus]);
            EventRegistrationMail::sendFor($reg->fresh());
            $admitted++;
        }

        $this->admitCount = 1;
        $this->flash = $admitted > 0
            ? "{$admitted} " . ($admitted === 1 ? 'person antagen från väntelistan.' : 'personer antagna från väntelistan.')
            : 'Inga väntande i kön.';

        unset($this->waitlisted, $this->confirmedCount, $this->spotsRemaining);
    }

    public function selectAll(): void
    {
        $this->selectedPending = $this->pendingApproval->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function deselectAll(): void
    {
        $this->selectedPending = [];
    }

    // ── Walk-in ───────────────────────────────────────────────────────────────

    #[Computed]
    public function walkinMember(): ?Member
    {
        return $this->walkinMemberId
            ? Member::with(['user'])->find($this->walkinMemberId)
            : null;
    }

    public function searchWalkin(): void
    {
        $this->walkinMemberId = null;
        $this->walkinFlash    = null;

        $q = trim($this->walkinQuery);
        if ($q === '') {
            return;
        }

        $member = Member::with(['user'])
            ->where(function ($query) use ($q) {
                $query->where('membership_number', $q)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%"));
            })
            ->first();

        if (! $member) {
            $this->walkinFlash = "Ingen medlem hittades för {$q}.";
            return;
        }

        $alreadyRegistered = $this->event->registrations()
            ->where('user_id', $member->user_id)
            ->whereNotIn('status', [EventRegistration::STATUS_CANCELLED, EventRegistration::STATUS_TRANSFERRED])
            ->exists();

        if ($alreadyRegistered) {
            $this->walkinFlash = "{$member->full_name} är redan anmäld till detta evenemang.";
            return;
        }

        $this->walkinMemberId = $member->id;
    }

    public function addWalkin(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('events.manage-all')
            || (Auth::user()->member && $this->event->isManagedBy(Auth::user()->member)), 403);

        $member = $this->walkinMember;
        if (! $member) {
            return;
        }

        $reg = EventRegistration::create([
            'event_id'       => $this->event->id,
            'user_id'        => $member->user_id,
            'status'         => EventRegistration::STATUS_CONFIRMED,
            'is_walkin'      => true,
            'check_in_token' => strtolower(Str::random(32)),
        ]);

        $occurrence = $this->event->occurrences()
            ->orderBy('starts_at')
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
            "Oanmäld: {$member->full_name} registrerades på {$this->event->title}"
        );

        $this->walkinQuery    = '';
        $this->walkinMemberId = null;
        $this->walkinFlash    = null;
        $this->flash          = "{$member->full_name} registrerades som oanmäld deltagare.";

        unset($this->walkinMember, $this->confirmedWithAttendance, $this->occurrencesWithAttendance);
    }

    public function clearWalkin(): void
    {
        $this->walkinQuery    = '';
        $this->walkinMemberId = null;
        $this->walkinFlash    = null;
        unset($this->walkinMember);
    }

    #[Computed]
    public function occurrencesWithAttendance()
    {
        return $this->event->occurrences()
            ->withCount([
                'attendances as confirmed_count' => fn ($q) => $q->whereHas('registration', fn ($r) => $r->confirmed()),
                'attendances as attended_count'  => fn ($q) => $q->whereNotNull('attended_at'),
            ])
            ->orderBy('starts_at')
            ->get();
    }

    #[Computed]
    public function confirmedWithAttendance()
    {
        return $this->event->registrations()
            ->confirmed()
            ->with(['user', 'priceTier', 'occurrenceAttendances'])
            ->orderBy('created_at')
            ->get();
    }
}; ?>

<x-slot:header>
    <x-page-header
        title="Antagning — {{ $event->title }}"
        subtitle=""
    >
        <a href="{{ route('admin.events.show', $event) }}" wire:navigate class="btn btn-ghost btn-sm">
            ← Tillbaka
        </a>
        <a href="{{ route('admin.scanner.kiosk') }}?event={{ $event->id }}" target="_blank" class="btn btn-sm">
            <x-bootstrap-icon name="display" class="size-3.5" />
            Dörrstation
        </a>
    </x-page-header>
</x-slot:header>

<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8 space-y-8">

    {{-- Flash --}}
    @if($flash)
        <div class="flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800/40 dark:bg-green-900/20 dark:text-green-400">
            <x-bootstrap-icon name="check-circle" class="size-4 shrink-0" />
            {{ $flash }}
        </div>
    @endif

    {{-- Capacity overview --}}
    <div class="flex flex-wrap items-center gap-4 rounded-xl border border-zinc-200 bg-zinc-50 px-5 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-800">
        <span class="text-zinc-500 dark:text-zinc-400">Bekräftade:</span>
        <span class="font-semibold text-zinc-900 dark:text-white">{{ $this->confirmedCount }}</span>
        @if($this->totalCapacity !== null)
            <span class="text-zinc-400">|</span>
            <span class="text-zinc-500 dark:text-zinc-400">Kapacitet:</span>
            <span class="font-semibold text-zinc-900 dark:text-white">{{ $this->totalCapacity }}</span>
            <span class="text-zinc-400">|</span>
            <span class="text-zinc-500 dark:text-zinc-400">Lediga platser:</span>
            <span class="font-semibold {{ $this->spotsRemaining > 0 ? 'text-green-700 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                {{ $this->spotsRemaining }}
            </span>
        @else
            <span class="text-zinc-400">|</span>
            <span class="text-zinc-500 dark:text-zinc-400">Obegränsad kapacitet</span>
        @endif
    </div>

    {{-- Section 1: Pending approval --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div class="flex items-center justify-between border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                Väntar godkännande ({{ $this->pendingApproval->count() }})
            </span>
            @if($this->pendingApproval->isNotEmpty())
                <div class="flex items-center gap-3 text-xs">
                    <button wire:click="selectAll" class="text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
                        Markera alla
                    </button>
                    <button wire:click="deselectAll" class="text-zinc-400 hover:text-zinc-600">
                        Rensa
                    </button>
                </div>
            @endif
        </div>

        @if($this->pendingApproval->isEmpty())
            <p class="px-5 py-4 text-sm text-zinc-400 dark:text-zinc-500">Inga anmälningar väntar godkännande.</p>
        @else
            <div class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                @foreach($this->pendingApproval as $reg)
                    <label wire:key="pa-{{ $reg->id }}"
                           class="flex cursor-pointer items-center gap-4 px-5 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <input type="checkbox"
                               wire:model="selectedPending"
                               value="{{ $reg->id }}"
                               class="size-4 rounded border-zinc-300 text-brand-dark accent-brand dark:border-zinc-600" />
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium text-zinc-900 dark:text-white">
                                {{ $reg->user?->name ?? $reg->guest_name ?? '–' }}
                            </div>
                            <div class="text-xs text-zinc-400 dark:text-zinc-500">
                                {{ $reg->user?->email ?? $reg->guest_email ?? '' }}
                                @if($reg->priceTier)
                                    · {{ $reg->priceTier->label }}
                                    @if($reg->priceTier->price_ore > 0)
                                        · {{ number_format($reg->priceTier->price_ore / 100, 0, ',', ' ') }} kr
                                    @endif
                                @endif
                            </div>
                            @if($reg->notes)
                                <div class="mt-0.5 text-xs text-amber-600 dark:text-amber-400">
                                    <x-bootstrap-icon name="chat-left-text" class="size-3 inline" />
                                    {{ $reg->notes }}
                                </div>
                            @endif
                        </div>
                        <div class="shrink-0 text-xs text-zinc-400 dark:text-zinc-500">
                            {{ $reg->created_at->isoFormat('D MMM, HH:mm') }}
                        </div>
                    </label>
                @endforeach
            </div>

            <div class="flex items-center gap-3 border-t border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <span class="mr-auto text-xs text-zinc-500 dark:text-zinc-400">
                    {{ count($selectedPending) }} markerade
                </span>
                <button wire:click="approveSelected"
                        @disabled(empty($selectedPending))
                        class="btn btn-primary btn-sm disabled:opacity-40">
                    <x-bootstrap-icon name="check-lg" class="size-3.5" />
                    Godkänn valda
                </button>
                <button wire:click="rejectSelected"
                        @disabled(empty($selectedPending))
                        class="btn btn-danger btn-sm disabled:opacity-40">
                    <x-bootstrap-icon name="x-lg" class="size-3.5" />
                    Avvisa valda
                </button>
            </div>
        @endif
    </div>

    {{-- Section 2: Waitlist --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                Väntelista ({{ $this->waitlisted->count() }})
            </span>
        </div>

        @if($this->waitlisted->isEmpty())
            <p class="px-5 py-4 text-sm text-zinc-400 dark:text-zinc-500">Ingen väntelista.</p>
        @else
            <ul class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                @foreach($this->waitlisted as $i => $reg)
                    <li wire:key="wl-{{ $reg->id }}" class="flex items-center gap-4 px-5 py-3">
                        <span class="w-5 shrink-0 text-center text-xs font-semibold text-zinc-400 dark:text-zinc-500">#{{ $i + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium text-zinc-900 dark:text-white">
                                {{ $reg->user?->name ?? $reg->guest_name ?? '–' }}
                            </div>
                            <div class="text-xs text-zinc-400 dark:text-zinc-500">
                                {{ $reg->user?->email ?? $reg->guest_email ?? '' }}
                                @if($reg->priceTier) · {{ $reg->priceTier->label }} @endif
                            </div>
                        </div>
                        <div class="shrink-0 text-xs text-zinc-400 dark:text-zinc-500">
                            {{ $reg->created_at->isoFormat('D MMM, HH:mm') }}
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="flex flex-wrap items-center gap-3 border-t border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <span class="mr-auto text-xs text-zinc-500 dark:text-zinc-400">
                    Anta de första från kön (i turordning):
                </span>
                <div class="flex items-center gap-2">
                    <button wire:click="$set('admitCount', max(1, admitCount - 1))"
                            class="flex size-7 items-center justify-center rounded border border-zinc-300 text-zinc-600 hover:bg-zinc-100 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-700">
                        <x-bootstrap-icon name="dash" class="size-3.5" />
                    </button>
                    <input wire:model="admitCount"
                           type="number" min="1" max="{{ $this->waitlisted->count() }}"
                           class="w-14 rounded border border-zinc-300 bg-white px-2 py-1 text-center text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                    <button wire:click="$set('admitCount', min(waitlisted|length, admitCount + 1))"
                            class="flex size-7 items-center justify-center rounded border border-zinc-300 text-zinc-600 hover:bg-zinc-100 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-700">
                        <x-bootstrap-icon name="plus" class="size-3.5" />
                    </button>
                </div>
                <button wire:click="admitFromWaitlist"
                        class="btn btn-primary btn-sm">
                    <x-bootstrap-icon name="person-check" class="size-3.5" />
                    Anta {{ $admitCount }} {{ $admitCount === 1 ? 'person' : 'personer' }}
                </button>
            </div>
        @endif
    </div>

    {{-- Section 3: Lägg till oanmäld --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                Lägg till oanmäld
            </span>
        </div>
        <div class="bg-white px-5 py-4 dark:bg-zinc-900">
            <div class="flex gap-2">
                <input wire:model="walkinQuery"
                       wire:keydown.enter="searchWalkin"
                       type="text"
                       placeholder="Medlemsnummer eller namn…"
                       class="inp flex-1 text-sm" />
                <button wire:click="searchWalkin" class="btn btn-sm">
                    <x-bootstrap-icon name="search" class="size-3.5" />
                    Sök
                </button>
            </div>

            @if($walkinFlash)
                <p class="mt-3 text-sm text-amber-600 dark:text-amber-400">{{ $walkinFlash }}</p>
            @endif

            @if($this->walkinMember)
                @php $wm = $this->walkinMember; @endphp
                <div class="mt-3 flex items-center justify-between rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                    <div>
                        <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ $wm->full_name }}</p>
                        <p class="text-xs text-zinc-400 dark:text-zinc-500">
                            Membre #{{ $wm->membership_number }}
                            @if($wm->user?->email) · {{ $wm->user->email }} @endif
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <button wire:click="clearWalkin" class="btn btn-ghost btn-sm">Avbryt</button>
                        <button wire:click="addWalkin" class="btn btn-primary btn-sm">
                            <x-bootstrap-icon name="person-fill-add" class="size-3.5" />
                            Registrera oanmäld
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Section 5: Närvaro --}}
    @php
        $occurrences = $this->occurrencesWithAttendance;
        $confirmed   = $this->confirmedWithAttendance;
        $multiOcc    = $occurrences->count() > 1;
    @endphp
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                Närvaro
            </span>
        </div>

        {{-- Per-occurrence summary (only shown when multiple occurrences) --}}
        @if($multiOcc)
            <div class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                @foreach($occurrences as $occ)
                    @php $pct = $occ->confirmed_count > 0 ? round($occ->attended_count / $occ->confirmed_count * 100) : 0; @endphp
                    <div class="flex items-center gap-4 px-5 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200">
                                {{ $occ->title ?? $occ->starts_at->isoFormat('D MMM, HH:mm') }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="h-1.5 w-24 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                <div class="h-full rounded-full bg-green-500" style="width: {{ $pct }}%"></div>
                            </div>
                            <span class="w-20 text-right text-sm text-zinc-600 dark:text-zinc-400">
                                {{ $occ->attended_count }}/{{ $occ->confirmed_count }}
                                <span class="text-xs text-zinc-400">({{ $pct }}%)</span>
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Registrations table --}}
        @if($confirmed->isEmpty())
            <p class="px-5 py-4 text-sm text-zinc-400 dark:text-zinc-500">Inga bekräftade anmälningar.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-5 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400">Deltagare</th>
                            @foreach($occurrences as $occ)
                                <th class="px-4 py-2.5 text-center text-xs font-semibold uppercase tracking-wider text-zinc-400">
                                    {{ $multiOcc ? ($occ->title ?? $occ->starts_at->isoFormat('D MMM')) : 'Incheckad' }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                        @foreach($confirmed as $reg)
                            @php
                                $attendanceByOcc = $reg->occurrenceAttendances->keyBy('occurrence_id');
                            @endphp
                            <tr wire:key="att-{{ $reg->id }}">
                                <td class="px-5 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <p class="font-medium text-zinc-900 dark:text-white">{{ $reg->user?->name ?? $reg->guest_name ?? '–' }}</p>
                                        @if($reg->is_walkin)
                                            <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                                Oanmäld
                                            </span>
                                        @endif
                                    </div>
                                    @if($reg->priceTier)
                                        <p class="text-xs text-zinc-400">{{ $reg->priceTier->label }}</p>
                                    @endif
                                </td>
                                @foreach($occurrences as $occ)
                                    @php $att = $attendanceByOcc->get($occ->id); @endphp
                                    <td class="px-4 py-2.5 text-center">
                                        @if($att?->attended_at)
                                            <span class="inline-flex items-center gap-1 text-green-700 dark:text-green-400" title="{{ $att->attended_at->isoFormat('D MMM HH:mm') }}">
                                                <x-bootstrap-icon name="check-circle-fill" class="size-4" />
                                                <span class="text-xs">{{ $att->attended_at->format('H:i') }}</span>
                                            </span>
                                        @else
                                            <span class="text-zinc-300 dark:text-zinc-600">–</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Total summary footer --}}
            @php
                $totalAttended = $occurrences->sum('attended_count');
                $totalConfirmed = $confirmed->count();
            @endphp
            @if(! $multiOcc)
            <div class="border-t border-zinc-100 bg-zinc-50 px-5 py-2.5 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800 dark:text-zinc-400">
                {{ $totalAttended }} av {{ $totalConfirmed }} incheckade
                @if($totalConfirmed > 0)
                    ({{ round($totalAttended / $totalConfirmed * 100) }}%)
                @endif
            </div>
            @endif
        @endif
    </div>

</div>
