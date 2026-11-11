<?php

use App\Mail\EventInvitationMail;
use App\Mail\EventMessageMail;
use App\Mail\EventRegistrationMail;
use App\Models\AuditLog;
use App\Models\Events\Event;
use App\Models\Events\EventInterest;
use App\Models\Events\EventInvitation;
use App\Models\Events\EventRegistration;
use App\Models\Events\EventRegistrationOccurrence;
use App\Models\Membership\Member;
use App\Models\Organization\Section;
use App\Models\Survey\Survey;
use App\Models\Survey\SurveyInvitation;
use App\Enums\SurveyStatus;
use App\Notifications\EventInvitationNotification;
use App\Notifications\SurveyInvitationNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Evenemang');

new class extends Component {

    public Event $event;
    public string $tab = 'overview';

    // Check-in search
    public string $checkinSearch = '';

    // Confirmation
    public ?int $confirmCancelId = null;

    // Settlement
    public string $settlementInput = '';
    public ?string $settlementFlash = null;

    public function mount(Event $event): void
    {
        $user   = Auth::user();
        $member = $user->member;

        $canView = $user->hasAnyPermission(['events.view', 'events.manage-all'])
            || ($member && $event->isManagedBy($member))
            || ($member && $event->isOrganizedBy($member));

        abort_unless($canView, 403);

        $this->event = $event;

        // Pre-fill settlement input with existing value or calculated amount
        if ($event->external_revenue_type && $event->external_revenue_type !== Event::REVENUE_NONE) {
            $ore = $event->external_settlement_ore ?? $event->calculateExternalSettlement();
            $this->settlementInput = $ore > 0 ? number_format($ore / 100, 2, ',', '.') : '';
        }
    }

    #[Computed]
    public function registrations()
    {
        return $this->event->registrations()
            ->with(['user', 'priceTier'])
            ->confirmed()
            ->orderBy('created_at')
            ->get();
    }

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
            ->with(['user'])
            ->waitlisted()
            ->orderBy('created_at')
            ->get();
    }

    #[Computed]
    public function checkinList()
    {
        return $this->event->registrations()
            ->with(['user', 'occurrenceAttendances'])
            ->confirmed()
            ->when($this->checkinSearch, function ($q) {
                $q->where(function ($q2) {
                    $q2->whereHas('user', function ($q3) {
                        $q3->where('name', 'like', "%{$this->checkinSearch}%")
                           ->orWhere('email', 'like', "%{$this->checkinSearch}%");
                    })
                    ->orWhere('guest_name', 'like', "%{$this->checkinSearch}%")
                    ->orWhere('guest_email', 'like', "%{$this->checkinSearch}%");
                });
            })
            ->orderBy('created_at')
            ->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        $user   = Auth::user();
        $member = $user->member;
        return $user->hasPermissionTo('events.manage-all')
            || ($member && $this->event->isManagedBy($member));
    }

    #[Computed]
    public function canCheckIn(): bool
    {
        $user   = Auth::user();
        $member = $user->member;
        return $user->hasAnyPermission(['events.check-in', 'events.manage-all'])
            || ($member && $this->event->isOrganizedBy($member));
    }

    #[Computed]
    public function canViewEconomy(): bool
    {
        return Auth::user()->hasPermissionTo('events.economy')
            || Auth::user()->hasPermissionTo('events.manage-all');
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            Event::STATUS_DRAFT     => 'Utkast',
            Event::STATUS_PUBLISHED => 'Publicerat',
            Event::STATUS_CANCELLED => 'Inställt',
            Event::STATUS_COMPLETED => 'Avslutat',
            default                 => $status,
        };
    }

    public function statusClasses(string $status): string
    {
        return match ($status) {
            Event::STATUS_DRAFT     => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
            Event::STATUS_PUBLISHED => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
            Event::STATUS_CANCELLED => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
            Event::STATUS_COMPLETED => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
            default                 => 'bg-zinc-100 text-zinc-600',
        };
    }

    public function registrationStatusLabel(string $status): string
    {
        return match ($status) {
            EventRegistration::STATUS_PENDING_PAYMENT  => 'Väntar betalning',
            EventRegistration::STATUS_PENDING_APPROVAL => 'Väntar godkännande',
            EventRegistration::STATUS_CONFIRMED        => 'Bekräftad',
            EventRegistration::STATUS_WAITLISTED       => 'Väntelista',
            EventRegistration::STATUS_CANCELLED        => 'Avbokad',
            default                                    => $status,
        };
    }

    public function formatPrice(int $ore): string
    {
        return number_format($ore / 100, 2, ',', ' ') . ' kr';
    }

    public function regDisplayName(EventRegistration $reg): string
    {
        return $reg->user?->name ?? $reg->guest_name ?? '–';
    }

    public function regDisplayEmail(EventRegistration $reg): string
    {
        return $reg->user?->email ?? $reg->guest_email ?? '';
    }

    public function approveRegistration(int $id): void
    {
        abort_unless($this->canManage, 403);

        $registration = $this->event->registrations()->findOrFail($id);
        $registration->load('order');
        $registration->approve();
        EventRegistrationMail::sendFor($registration->fresh());
        AuditLog::record('event_registration_approved', 'events', $this->event->title, $this->event);
    }

    public function rejectRegistration(int $id): void
    {
        abort_unless($this->canManage, 403);

        $registration = $this->event->registrations()->findOrFail($id);
        $registration->reject();
        EventRegistrationMail::sendFor($registration->fresh());
        $registration->promoteNextWaitlisted();
        AuditLog::record('event_registration_rejected', 'events', $this->event->title, $this->event);
    }

    public function cancelRegistration(int $id): void
    {
        abort_unless($this->canManage, 403);

        $registration = $this->event->registrations()->findOrFail($id);
        $registration->cancel();
        EventRegistrationMail::sendFor($registration->fresh());
        $registration->promoteNextWaitlisted();
        AuditLog::record('event_registration_cancelled', 'events', $this->event->title, $this->event);

        $this->confirmCancelId = null;
    }

    #[Computed]
    public function occurrences()
    {
        return $this->event->occurrences;
    }

    public function checkIn(int $registrationId, int $occurrenceId): void
    {
        abort_unless($this->canCheckIn || $this->canManage, 403);

        $registration = $this->event->registrations()
            ->where('status', EventRegistration::STATUS_CONFIRMED)
            ->findOrFail($registrationId);

        $attendance = EventRegistrationOccurrence::firstOrNew([
            'registration_id' => $registration->id,
            'occurrence_id'   => $occurrenceId,
        ]);

        $attendance->attended_at      = now();
        $attendance->checked_in_by_id = Auth::id();
        $attendance->save();
        AuditLog::record('event_check_in', 'events', $this->event->title, $this->event);

        unset($this->checkinList);
    }

    public function uncheckIn(int $registrationId, int $occurrenceId): void
    {
        abort_unless($this->canCheckIn || $this->canManage, 403);

        EventRegistrationOccurrence::where('registration_id', $registrationId)
            ->where('occurrence_id', $occurrenceId)
            ->update(['attended_at' => null, 'checked_in_by_id' => null]);
        AuditLog::record('event_check_in_removed', 'events', $this->event->title, $this->event);

        unset($this->checkinList);
    }

    public function registerSettlement(): void
    {
        abort_unless($this->canManage, 403);

        $ore = (int) round((float) str_replace(',', '.', $this->settlementInput) * 100);

        $this->event->update([
            'external_settlement_ore' => $ore,
            'external_settled_at'     => now(),
        ]);
        AuditLog::record('event_settlement_registered', 'events', $this->event->title, $this->event);

        $this->settlementFlash = 'Utbetalning registrerad: ' . $this->formatPrice($ore);
    }

    public function cancelEvent(): void
    {
        abort_unless($this->canManage, 403);

        $cancellable = $this->event->registrations()
            ->whereNotIn('status', [EventRegistration::STATUS_CANCELLED])
            ->get();

        foreach ($cancellable as $registration) {
            $registration->cancel();
            EventRegistrationMail::sendFor($registration->fresh());
        }

        $this->event->update(['status' => Event::STATUS_CANCELLED]);
        AuditLog::record('event_cancelled', 'events', $this->event->title, $this->event);
        $this->settlementFlash = 'Evenemanget inställt. ' . $cancellable->count() . ' anmälda har meddelats.';
    }

    public function markCompleted(): void
    {
        abort_unless($this->canManage, 403);

        $this->event->update(['status' => Event::STATUS_COMPLETED]);
        AuditLog::record('event_completed', 'events', $this->event->title, $this->event);

        // Send followup survey invitations to all confirmed participants
        $followup = $this->event->followupSurvey;
        if ($followup) {
            $followup->update(['status' => SurveyStatus::Active]);

            $registrations = $this->event->registrations()
                ->confirmed()
                ->with('user')
                ->get();

            $sent = 0;
            foreach ($registrations as $registration) {
                $email = $registration->user?->email ?? $registration->guest_email;
                if (! $email) {
                    continue;
                }

                // Skip if already invited
                if ($followup->invitations()->where('email', $email)->exists()) {
                    continue;
                }

                $invitation = SurveyInvitation::create([
                    'survey_id' => $followup->id,
                    'email'     => $email,
                    'token'     => Str::random(64),
                    'sent_at'   => now(),
                ]);

                $notifiable = $registration->user
                    ?? (new \Illuminate\Notifications\AnonymousNotifiable)->route('mail', $email);

                Notification::send([$notifiable], new SurveyInvitationNotification($invitation));
                $sent++;
            }

            $this->settlementFlash = "Evenemanget avslutat. Uppföljning skickad till {$sent} deltagare.";
        } else {
            $this->settlementFlash = 'Evenemanget markerat som avslutat.';
        }
    }

    // ── Kommunikation: intresse, inbjudningar, meddelande ─────────────────────

    public string $inviteSearch       = '';
    public ?int   $inviteSectionId    = null;
    public string $messageBody        = '';
    public array  $messageTargets     = ['confirmed'];
    public ?string $commFlash         = null;

    #[Computed]
    public function interests()
    {
        return $this->event->interests()->with('member')->latest()->get();
    }

    #[Computed]
    public function pendingInvitations()
    {
        return $this->event->invitations()
            ->whereNull('accepted_at')
            ->with('member', 'invitedBy')
            ->latest()
            ->get();
    }

    #[Computed]
    public function inviteSearchResults()
    {
        if (strlen($this->inviteSearch) < 2) {
            return collect();
        }
        $alreadyInvited = $this->event->invitations()->pluck('member_id');
        return Member::where(function ($q) {
            $q->where('name', 'like', '%' . $this->inviteSearch . '%')
              ->orWhere('email', 'like', '%' . $this->inviteSearch . '%');
        })
        ->whereNotIn('id', $alreadyInvited)
        ->limit(8)
        ->get();
    }

    #[Computed]
    public function sections()
    {
        return Section::orderBy('name')->get(['id', 'name']);
    }

    public function inviteMember(int $memberId): void
    {
        abort_unless($this->canManage, 403);

        $member = Member::findOrFail($memberId);

        if ($this->event->invitations()->where('member_id', $memberId)->exists()) {
            return;
        }

        $invitation = EventInvitation::create([
            'event_id'             => $this->event->id,
            'member_id'            => $memberId,
            'email'                => $member->email,
            'invited_by_member_id' => Auth::user()->member?->id,
            'expires_at'           => now()->addDays(30),
        ]);

        $invitation->load('event.occurrences');

        if ($member->user) {
            $member->user->notify(new EventInvitationNotification($invitation));
        } else {
            Mail::to($member->email)->queue(new EventInvitationMail($invitation));
        }

        $this->inviteSearch = '';
        unset($this->pendingInvitations, $this->inviteSearchResults);
        AuditLog::record('event_invitation_sent', 'events', $this->event->title . ' → ' . $member->name, $this->event);
    }

    public function inviteSection(): void
    {
        abort_unless($this->canManage, 403);

        $section = Section::findOrFail($this->inviteSectionId);
        $alreadyInvited = $this->event->invitations()->pluck('member_id')->toArray();

        $members = $section->members()->whereNotIn('members.id', $alreadyInvited)->get();
        $inviterMemberId = Auth::user()->member?->id;

        foreach ($members as $member) {
            $invitation = EventInvitation::create([
                'event_id'             => $this->event->id,
                'member_id'            => $member->id,
                'email'                => $member->email,
                'invited_by_member_id' => $inviterMemberId,
                'expires_at'           => now()->addDays(30),
            ]);

            $invitation->load('event.occurrences');

            if ($member->user) {
                $member->user->notify(new EventInvitationNotification($invitation));
            } else {
                Mail::to($member->email)->queue(new EventInvitationMail($invitation));
            }
        }

        $this->commFlash = "Inbjudningar skickade till {$members->count()} medlemmar i {$section->name}.";
        $this->inviteSectionId = null;
        unset($this->pendingInvitations);
        AuditLog::record('event_invitations_section', 'events', $this->event->title . ' → ' . $section->name, $this->event);
    }

    public function revokeInvitation(int $id): void
    {
        abort_unless($this->canManage, 403);
        EventInvitation::where('event_id', $this->event->id)->findOrFail($id)->delete();
        unset($this->pendingInvitations);
    }

    public function sendMessage(): void
    {
        abort_unless($this->canManage, 403);

        $this->validate(['messageBody' => 'required|min:5']);

        $recipients = collect();

        if (in_array('confirmed', $this->messageTargets)) {
            $recipients = $recipients->merge(
                $this->event->registrations()->confirmed()->with('user')->get()
                    ->map(fn ($r) => ['email' => $r->user?->email ?? $r->guest_email, 'name' => $r->user?->name ?? $r->guest_name])
            );
        }
        if (in_array('waitlisted', $this->messageTargets)) {
            $recipients = $recipients->merge(
                $this->event->registrations()->waitlisted()->with('user')->get()
                    ->map(fn ($r) => ['email' => $r->user?->email ?? $r->guest_email, 'name' => $r->user?->name ?? $r->guest_name])
            );
        }
        if (in_array('invited', $this->messageTargets)) {
            $recipients = $recipients->merge(
                $this->event->invitations()->whereNull('accepted_at')->with('member')->get()
                    ->map(fn ($i) => ['email' => $i->email, 'name' => $i->member?->name ?? $i->email])
            );
        }
        if (in_array('interested', $this->messageTargets)) {
            $recipients = $recipients->merge(
                $this->event->interests()->with('member')->get()
                    ->map(fn ($i) => ['email' => $i->member->email, 'name' => $i->member->name])
            );
        }

        $unique = $recipients->filter(fn ($r) => ! empty($r['email']))->unique('email');
        $event  = $this->event;
        $body   = $this->messageBody;

        foreach ($unique as $r) {
            $fornamn = explode(' ', trim($r['name']))[0] ?: 'Hej';
            Mail::to($r['email'])->queue(new EventMessageMail($event, $fornamn, $body));
        }

        $this->commFlash  = "Meddelande skickat till {$unique->count()} mottagare.";
        $this->messageBody = '';
        AuditLog::record('event_message_sent', 'events', $this->event->title, $this->event);
    }

    public function createSurvey(string $role): void
    {
        abort_unless($this->canManage, 403);
        abort_unless(in_array($role, [Survey::ROLE_REGISTRATION, Survey::ROLE_FOLLOWUP]), 422);

        $label = $role === Survey::ROLE_REGISTRATION ? 'anmälningsformulär' : 'uppföljning';

        $survey = Survey::create([
            'title'              => $this->event->title . ' — ' . $label,
            'status'             => SurveyStatus::Draft,
            'is_anonymous'       => $role === Survey::ROLE_FOLLOWUP,
            'is_open'            => false,
            'notify_on_response' => false,
            'created_by'         => Auth::id(),
            'surveyable_type'    => Event::class,
            'surveyable_id'      => $this->event->id,
            'role'               => $role,
        ]);

        AuditLog::record('event_survey_created', 'events', $this->event->title, $this->event);

        $this->redirect(route('admin.surveys.edit', $survey), navigate: true);
    }

    public function detachSurvey(string $role): void
    {
        abort_unless($this->canManage, 403);
        abort_unless(in_array($role, [Survey::ROLE_REGISTRATION, Survey::ROLE_FOLLOWUP]), 422);

        $relation = $role === Survey::ROLE_REGISTRATION ? 'registrationSurvey' : 'followupSurvey';

        $this->event->{$relation}?->update([
            'surveyable_type' => null,
            'surveyable_id'   => null,
            'role'            => null,
        ]);
        AuditLog::record('event_survey_detached', 'events', $this->event->title, $this->event);

        $this->event->refresh();
    }
}; ?>

<x-slot:header>
    <x-page-header permission="events.view" title="{{ $event->title }}" subtitle="">
        @if($this->canManage)
            <a href="{{ route('admin.events.edit', $event) }}" wire:navigate class="btn btn-secondary btn-sm">
                <x-bootstrap-icon name="pencil" class="size-3.5" /> Redigera
            </a>
        @endif
    </x-page-header>
</x-slot:header>

<div>
    <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">

        {{-- Status badge --}}
        <div class="flex flex-wrap items-center gap-3">
            <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium {{ $this->statusClasses($event->status) }}">
                {{ $this->statusLabel($event->status) }}
            </span>
            @if($event->visibility === Event::VISIBILITY_MEMBERS_ONLY)
                <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-3 py-1 text-sm text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                    <x-bootstrap-icon name="lock" class="size-3.5" /> Enbart medlemmar
                </span>
            @elseif($event->visibility === Event::VISIBILITY_INVITE_ONLY)
                <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-3 py-1 text-sm text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                    <x-bootstrap-icon name="envelope" class="size-3.5" /> Inbjudan
                </span>
            @endif
        </div>

        {{-- Tabs --}}
        <div class="border-b border-zinc-200 dark:border-zinc-700">
            <nav class="-mb-px flex gap-6">
                <button wire:click="$set('tab', 'overview')"
                        @class(['border-b-2 pb-3 text-sm font-medium transition-colors',
                            'border-brand text-zinc-900 dark:text-white'   => $tab === 'overview',
                            'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' => $tab !== 'overview'])>
                    Översikt
                </button>
                @if($this->canManage || $this->canCheckIn)
                    <button wire:click="$set('tab', 'registrations')"
                            @class(['border-b-2 pb-3 text-sm font-medium transition-colors',
                                'border-brand text-zinc-900 dark:text-white'   => $tab === 'registrations',
                                'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' => $tab !== 'registrations'])>
                        Anmälningar
                        @php
                            $confirmedCount = $event->registrations()->confirmed()->count();
                            $pendingApprovalCount = $event->registrations()->pendingApproval()->count();
                            $waitlistedCount = $event->registrations()->waitlisted()->count();
                        @endphp
                        <span class="ml-1 rounded-full bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                            {{ $confirmedCount }}
                        </span>
                        @if($pendingApprovalCount > 0 || $waitlistedCount > 0)
                            <span class="ml-0.5 rounded-full bg-amber-100 px-1.5 py-0.5 text-xs text-amber-700 dark:bg-amber-900/40 dark:text-amber-400">
                                {{ $pendingApprovalCount + $waitlistedCount }} väntar
                            </span>
                        @endif
                    </button>
                    <button wire:click="$set('tab', 'checkin')"
                            @class(['border-b-2 pb-3 text-sm font-medium transition-colors',
                                'border-brand text-zinc-900 dark:text-white'   => $tab === 'checkin',
                                'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' => $tab !== 'checkin'])>
                        Incheckning
                    </button>
                @endif
                @if($this->canManage)
                    <button wire:click="$set('tab', 'form')"
                            @class(['border-b-2 pb-3 text-sm font-medium transition-colors',
                                'border-brand text-zinc-900 dark:text-white'   => $tab === 'form',
                                'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' => $tab !== 'form'])>
                        Formulär
                        @if($event->registrationSurvey)
                            @php $n = $event->registrationSurvey->submissions()->count(); @endphp
                            <span class="ml-1 rounded-full bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                Anmälan: {{ $n }}
                            </span>
                        @endif
                        @if($event->followupSurvey)
                            @php $n = $event->followupSurvey->submissions()->count(); @endphp
                            <span class="ml-0.5 rounded-full bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                Uppföljning: {{ $n }}
                            </span>
                        @endif
                    </button>
                @endif
                @if($this->canManage)
                    <button wire:click="$set('tab', 'communication')"
                            @class(['border-b-2 pb-3 text-sm font-medium transition-colors',
                                'border-brand text-zinc-900 dark:text-white'   => $tab === 'communication',
                                'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' => $tab !== 'communication'])>
                        Kommunikation
                        @php $interestCount = $event->interests()->count(); @endphp
                        @if($interestCount > 0)
                            <span class="ml-1 rounded-full bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                {{ $interestCount }}
                            </span>
                        @endif
                    </button>
                @endif
                @if($this->canViewEconomy && $event->external_revenue_type !== Event::REVENUE_NONE)
                    <button wire:click="$set('tab', 'economy')"
                            @class(['border-b-2 pb-3 text-sm font-medium transition-colors',
                                'border-brand text-zinc-900 dark:text-white'   => $tab === 'economy',
                                'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' => $tab !== 'economy'])>
                        Ekonomi
                    </button>
                @endif
            </nav>
        </div>

        {{-- TAB: Overview --}}
        @if($tab === 'overview')
            <div class="grid gap-6 lg:grid-cols-2">

                {{-- Description --}}
                @if($event->description)
                    <div class="lg:col-span-2 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Beskrivning</span>
                        </div>
                        <div class="p-5 text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $event->description }}</div>
                    </div>
                @endif

                {{-- Occurrences --}}
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Tillfällen</span>
                    </div>
                    <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse($event->occurrences as $occ)
                            <li class="px-5 py-3">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="text-sm font-medium text-zinc-900 dark:text-white">
                                            {{ $occ->starts_at->isoFormat('ddd D MMM YYYY, HH:mm') }}
                                            –
                                            {{ $occ->ends_at->isoFormat($occ->starts_at->isSameDay($occ->ends_at) ? 'HH:mm' : 'D MMM HH:mm') }}
                                        </div>
                                        @if($occ->location_override)
                                            <div class="text-xs text-zinc-400 dark:text-zinc-500">{{ $occ->location_override }}</div>
                                        @endif
                                    </div>
                                    <div class="text-right">
                                        @if($occ->isCancelled())
                                            <span class="text-xs text-red-500">Inställt</span>
                                        @elseif($occ->capacity)
                                            <span class="text-xs text-zinc-500">{{ $occ->confirmedCount() }}/{{ $occ->capacity }}</span>
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @empty
                            <li class="px-5 py-3 text-sm text-zinc-400 dark:text-zinc-500">Inga tillfällen tillagda.</li>
                        @endforelse
                    </ul>
                </div>

                {{-- Price tiers --}}
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Biljettyper</span>
                    </div>
                    <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse($event->priceTiers as $tier)
                            <li class="flex items-center justify-between px-5 py-3">
                                <div>
                                    <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $tier->label }}</span>
                                    @if($tier->description)
                                        <div class="text-xs text-zinc-400 dark:text-zinc-500">{{ $tier->description }}</div>
                                    @endif
                                </div>
                                <div class="text-right">
                                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                        {{ $tier->isFree() ? 'Gratis' : $this->formatPrice($tier->price_ore) }}
                                    </span>
                                    @if($tier->capacity)
                                        <div class="text-xs text-zinc-400 dark:text-zinc-500">max {{ $tier->capacity }}</div>
                                    @endif
                                </div>
                            </li>
                        @empty
                            <li class="px-5 py-3 text-sm text-zinc-400 dark:text-zinc-500">Inga biljettyper tillagda.</li>
                        @endforelse
                    </ul>
                </div>

                {{-- Organizers --}}
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Arrangörer</span>
                    </div>
                    <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse($event->organizers as $organizer)
                            <li class="flex items-center justify-between px-5 py-3">
                                <span class="text-sm text-zinc-900 dark:text-white">{{ $organizer->full_name }}</span>
                                <span class="text-xs text-zinc-400 dark:text-zinc-500">
                                    {{ $organizer->pivot->role === Event::ORGANIZER_ADMIN ? 'Admin' : 'Hjälpare' }}
                                </span>
                            </li>
                        @empty
                            <li class="px-5 py-3 text-sm text-zinc-400 dark:text-zinc-500">Inga arrangörer kopplade.</li>
                        @endforelse
                    </ul>
                </div>

                {{-- Registration settings summary --}}
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Anmälan</span>
                    </div>
                    <dl class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <div class="flex justify-between px-5 py-2.5 text-sm">
                            <dt class="text-zinc-500 dark:text-zinc-400">Läge</dt>
                            <dd class="text-zinc-900 dark:text-white">
                                {{ match($event->registration_mode) {
                                    Event::REGISTRATION_STANDARD => 'Föranmälan',
                                    Event::REGISTRATION_DROPIN   => 'Drop-in',
                                    Event::REGISTRATION_BOTH     => 'Båda',
                                    default                      => $event->registration_mode,
                                } }}
                            </dd>
                        </div>
                        <div class="flex justify-between px-5 py-2.5 text-sm">
                            <dt class="text-zinc-500 dark:text-zinc-400">Antagning</dt>
                            <dd class="text-zinc-900 dark:text-white">
                                {{ match($event->admission_type) {
                                    Event::ADMISSION_ON_PAYMENT => 'Vid betalning',
                                    Event::ADMISSION_AUTOMATIC  => 'Automatisk',
                                    Event::ADMISSION_MANUAL     => 'Manuell granskning',
                                    default                     => $event->admission_type,
                                } }}
                            </dd>
                        </div>
                        @if($event->registration_opens_at)
                            <div class="flex justify-between px-5 py-2.5 text-sm">
                                <dt class="text-zinc-500 dark:text-zinc-400">Öppnar</dt>
                                <dd class="text-zinc-900 dark:text-white">{{ $event->registration_opens_at->isoFormat('D MMM YYYY HH:mm') }}</dd>
                            </div>
                        @endif
                        @if($event->registration_closes_at)
                            <div class="flex justify-between px-5 py-2.5 text-sm">
                                <dt class="text-zinc-500 dark:text-zinc-400">Stänger</dt>
                                <dd class="text-zinc-900 dark:text-white">{{ $event->registration_closes_at->isoFormat('D MMM YYYY HH:mm') }}</dd>
                            </div>
                        @endif
                        <div class="flex justify-between px-5 py-2.5 text-sm">
                            <dt class="text-zinc-500 dark:text-zinc-400">Kräver medlemskap</dt>
                            <dd class="text-zinc-900 dark:text-white">{{ $event->requires_membership ? 'Ja' : 'Nej' }}</dd>
                        </div>
                        <div class="flex justify-between px-5 py-2.5 text-sm">
                            <dt class="text-zinc-500 dark:text-zinc-400">Biljettöverlåtelse</dt>
                            <dd class="text-zinc-900 dark:text-white">{{ $event->allow_ticket_transfer ? 'Tillåten' : 'Ej tillåten' }}</dd>
                        </div>
                    </dl>
                </div>

            </div>
        @endif

        {{-- TAB: Registrations --}}
        @if($tab === 'registrations' && ($this->canManage || $this->canCheckIn))
            <div class="space-y-6">

                {{-- Pending approval --}}
                @if($this->pendingApproval->isNotEmpty())
                    <div class="overflow-hidden rounded-xl border border-amber-200 dark:border-amber-800/40">
                        <div class="flex items-center gap-2 border-b border-amber-100 bg-amber-50 px-5 py-3 dark:border-amber-800/40 dark:bg-amber-900/10">
                            <x-bootstrap-icon name="hourglass-split" class="size-4 text-amber-600 dark:text-amber-400" />
                            <span class="text-xs font-semibold uppercase tracking-wider text-amber-700 dark:text-amber-400">
                                Väntar godkännande ({{ $this->pendingApproval->count() }})
                            </span>
                        </div>
                        <table class="min-w-full divide-y divide-amber-100 text-sm dark:divide-amber-900/20">
                            <tbody class="divide-y divide-amber-100 bg-white dark:divide-amber-900/20 dark:bg-zinc-900">
                                @foreach($this->pendingApproval as $reg)
                                    <tr>
                                        <td class="px-4 py-3">
                                            <div class="font-medium text-zinc-900 dark:text-white">{{ $this->regDisplayName($reg) }}</div>
                                            <div class="text-xs text-zinc-400 dark:text-zinc-500">{{ $this->regDisplayEmail($reg) }}</div>
                                        </td>
                                        <td class="hidden px-4 py-3 text-zinc-600 dark:text-zinc-400 sm:table-cell">
                                            {{ $reg->priceTier?->label ?? '–' }}
                                        </td>
                                        <td class="hidden px-4 py-3 text-xs text-zinc-400 dark:text-zinc-500 md:table-cell">
                                            {{ $reg->created_at->isoFormat('D MMM YYYY') }}
                                        </td>
                                        @if($this->canManage)
                                            <td class="px-4 py-3 text-right">
                                                <div class="flex items-center justify-end gap-3">
                                                    <button wire:click="approveRegistration({{ $reg->id }})"
                                                            class="text-xs font-medium text-green-700 hover:text-green-900 dark:text-green-400 dark:hover:text-green-300">
                                                        Godkänn
                                                    </button>
                                                    <button wire:click="rejectRegistration({{ $reg->id }})"
                                                            class="text-xs text-red-500 hover:text-red-700 dark:hover:text-red-400">
                                                        Avvisa
                                                    </button>
                                                </div>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                {{-- Admission shortcut --}}
                @if($this->canManage && ($this->pendingApproval->isNotEmpty() || $this->waitlisted->isNotEmpty()))
                    <div class="flex items-center justify-between rounded-xl border border-amber-200 bg-amber-50 px-5 py-3 dark:border-amber-800/40 dark:bg-amber-900/20">
                        <div class="text-sm text-amber-800 dark:text-amber-400">
                            @if($this->pendingApproval->isNotEmpty())
                                <strong>{{ $this->pendingApproval->count() }}</strong> {{ $this->pendingApproval->count() === 1 ? 'anmälan' : 'anmälningar' }} väntar godkännande.
                            @endif
                            @if($this->waitlisted->isNotEmpty())
                                <strong>{{ $this->waitlisted->count() }}</strong> i väntelista.
                            @endif
                        </div>
                        <div class="ml-4 flex shrink-0 gap-2">
                            <a href="{{ route('admin.scanner.kiosk') }}?event={{ $event->id }}" target="_blank"
                               class="btn btn-sm btn-ghost">
                                <x-bootstrap-icon name="display" class="size-3.5" />
                                Dörrstation
                            </a>
                            <a href="{{ route('admin.events.admission', $event) }}" wire:navigate
                               class="btn btn-sm btn-secondary">
                                <x-bootstrap-icon name="person-check" class="size-3.5" />
                                Hantera antagning
                            </a>
                        </div>
                    </div>
                @endif

                {{-- Confirmed --}}
                <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <div class="flex items-center justify-between border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            Bekräftade ({{ $this->registrations->count() }})
                        </span>
                    </div>
                    @if($this->registrations->isEmpty())
                        <p class="px-5 py-4 text-sm text-zinc-400 dark:text-zinc-500">Inga bekräftade anmälningar.</p>
                    @else
                        <table class="min-w-full divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                            <thead class="bg-white dark:bg-zinc-900">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Deltagare</th>
                                    <th class="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 sm:table-cell">Biljett</th>
                                    <th class="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 md:table-cell">Anmäld</th>
                                    @if($this->canManage)
                                        <th class="relative px-4 py-3"><span class="sr-only">Åtgärder</span></th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                                @foreach($this->registrations as $reg)
                                    <tr>
                                        <td class="px-4 py-3">
                                            <div class="font-medium text-zinc-900 dark:text-white">{{ $this->regDisplayName($reg) }}</div>
                                            <div class="text-xs text-zinc-400 dark:text-zinc-500">{{ $this->regDisplayEmail($reg) }}</div>
                                        </td>
                                        <td class="hidden px-4 py-3 text-zinc-600 dark:text-zinc-400 sm:table-cell">
                                            {{ $reg->priceTier?->label ?? '–' }}
                                        </td>
                                        <td class="hidden px-4 py-3 text-zinc-600 dark:text-zinc-400 md:table-cell">
                                            {{ $reg->created_at->isoFormat('D MMM YYYY') }}
                                        </td>
                                        @if($this->canManage)
                                            <td class="px-4 py-3 text-right" x-data="{ confirming: false }">
                                                <button @click="confirming = true" x-show="!confirming"
                                                        class="text-xs text-red-500 hover:text-red-700 dark:hover:text-red-400">
                                                    Avboka
                                                </button>
                                                <div x-show="confirming" x-cloak class="flex items-center justify-end gap-2">
                                                    <span class="text-xs text-red-600 dark:text-red-400">Säker?</span>
                                                    <button wire:click="cancelRegistration({{ $reg->id }})"
                                                            class="text-xs text-red-600 font-medium hover:text-red-800">Ja</button>
                                                    <button @click="confirming = false"
                                                            class="text-xs text-zinc-500 hover:text-zinc-700">Nej</button>
                                                </div>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>

                {{-- Waitlisted --}}
                @if($this->waitlisted->isNotEmpty())
                    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                Väntelista ({{ $this->waitlisted->count() }})
                            </span>
                        </div>
                        <table class="min-w-full divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                            <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                                @foreach($this->waitlisted as $i => $reg)
                                    <tr>
                                        <td class="px-4 py-3">
                                            <span class="mr-2 text-xs text-zinc-400">#{{ $i + 1 }}</span>
                                            <span class="font-medium text-zinc-900 dark:text-white">{{ $this->regDisplayName($reg) }}</span>
                                            <span class="ml-1 text-xs text-zinc-400 dark:text-zinc-500">{{ $this->regDisplayEmail($reg) }}</span>
                                        </td>
                                        <td class="hidden px-4 py-3 text-xs text-zinc-400 dark:text-zinc-500 sm:table-cell">
                                            {{ $reg->priceTier?->label ?? '–' }}
                                        </td>
                                        <td class="px-4 py-3 text-xs text-zinc-400 dark:text-zinc-500 text-right">
                                            {{ $reg->created_at->isoFormat('D MMM YYYY') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

            </div>
        @endif

        {{-- TAB: Check-in --}}
        @if($tab === 'checkin' && ($this->canManage || $this->canCheckIn))
            @php $occurrences = $this->occurrences; $multiOcc = $occurrences->count() > 1; @endphp
            <div class="space-y-4">

                {{-- Occurrence selector label (multi-occurrence events) --}}
                @if($multiOcc)
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-2.5 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">
                        Evenemanget har {{ $occurrences->count() }} tillfällen — incheckning visas per tillfälle.
                    </div>
                @endif

                <div class="relative">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                        <x-bootstrap-icon name="search" class="size-4 text-zinc-400" />
                    </div>
                    <input wire:model.live.debounce.200ms="checkinSearch"
                           type="search"
                           placeholder="Sök deltagare…"
                           class="w-full rounded-lg border border-zinc-300 bg-white py-2 pl-9 pr-3 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
                </div>

                @if($this->checkinList->isEmpty())
                    <p class="py-4 text-center text-sm text-zinc-400 dark:text-zinc-500">
                        {{ $checkinSearch ? 'Ingen matchning.' : 'Inga bekräftade anmälningar.' }}
                    </p>
                @elseif($occurrences->isEmpty())
                    <p class="py-4 text-center text-sm text-zinc-400 dark:text-zinc-500">
                        Evenemanget har inga tillfällen — lägg till ett tillfälle för att aktivera incheckning.
                    </p>
                @else
                    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <table class="min-w-full divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                            <thead class="bg-zinc-50 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Deltagare</th>
                                    @if($multiOcc)
                                        @foreach($occurrences as $occ)
                                            <th class="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                                                {{ $occ->starts_at->isoFormat('D MMM') }}
                                                @if($occ->title) <span class="block font-normal normal-case">{{ $occ->title }}</span> @endif
                                            </th>
                                        @endforeach
                                    @else
                                        <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Status</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                                @foreach($this->checkinList as $reg)
                                    <tr wire:key="ci-{{ $reg->id }}">
                                        <td class="px-5 py-3">
                                            <div class="font-medium text-zinc-900 dark:text-white">{{ $this->regDisplayName($reg) }}</div>
                                            <div class="text-xs text-zinc-400 dark:text-zinc-500">{{ $reg->priceTier?->label ?? '–' }}</div>
                                        </td>
                                        @foreach($occurrences as $occ)
                                            @php
                                                $att      = $reg->occurrenceAttendances->firstWhere('occurrence_id', $occ->id);
                                                $attended = $att?->attended_at !== null;
                                            @endphp
                                            <td class="{{ $multiOcc ? 'px-3 text-center' : 'px-5 text-right' }} py-3">
                                                @if($attended)
                                                    <button wire:click="uncheckIn({{ $reg->id }}, {{ $occ->id }})"
                                                            title="Ångra incheckning"
                                                            class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-1 text-xs font-medium text-green-800 transition-colors hover:bg-green-200 dark:bg-green-900/30 dark:text-green-400 dark:hover:bg-green-900/50">
                                                        <x-bootstrap-icon name="check-circle-fill" class="size-3" />
                                                        @if(!$multiOcc) Incheckad @endif
                                                    </button>
                                                @else
                                                    <button wire:click="checkIn({{ $reg->id }}, {{ $occ->id }})"
                                                            title="Checka in"
                                                            class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-500 transition-colors hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700">
                                                        <x-bootstrap-icon name="circle" class="size-3" />
                                                        @if(!$multiOcc) Checka in @endif
                                                    </button>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Summary --}}
                    @php
                        $totalCheckedIn = $this->checkinList->filter(fn($r) =>
                            $r->occurrenceAttendances->first()?->attended_at !== null
                        )->count();
                    @endphp
                    <p class="text-right text-xs text-zinc-400 dark:text-zinc-500">
                        {{ $totalCheckedIn }} / {{ $this->checkinList->count() }} incheckade
                    </p>
                @endif
            </div>
        @endif

        {{-- TAB: Form --}}
        @if($tab === 'form' && $this->canManage)
            <div class="space-y-6">

                @php
                    $regSurvey     = $event->registrationSurvey;
                    $followupSurvey = $event->followupSurvey;
                @endphp

                {{-- Registration survey --}}
                @if($regSurvey)
                    <x-survey-card :survey="$regSurvey"
                                   heading="Anmälningsformulär"
                                   description="Visas inline när deltagare anmäler sig."
                                   detach-action="detachSurvey('registration')" />
                @else
                    <x-survey-empty-state
                        heading="Inget anmälningsformulär"
                        description="Samla in extra information när deltagare anmäler sig."
                        create-action="createSurvey('registration')"
                        create-label="Skapa anmälningsformulär" />
                @endif

                {{-- Followup survey --}}
                @if($followupSurvey)
                    <x-survey-card :survey="$followupSurvey"
                                   heading="Uppföljning"
                                   description="Skickas som inbjudan till alla bekräftade deltagare när evenemanget avslutas."
                                   detach-action="detachSurvey('followup')" />
                @else
                    <x-survey-empty-state
                        heading="Ingen uppföljning"
                        description="Skicka en enkät till deltagarna efter evenemanget."
                        create-action="createSurvey('followup')"
                        create-label="Skapa uppföljning" />
                @endif

            </div>
        @endif

        {{-- TAB: Kommunikation --}}
        @if($tab === 'communication' && $this->canManage)
            <div class="space-y-6">

                @if($commFlash)
                    <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                        {{ $commFlash }}
                    </div>
                @endif

                {{-- Intresserade --}}
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            Intresserade — {{ $this->interests->count() }}
                        </span>
                    </div>
                    @if($this->interests->isEmpty())
                        <p class="px-5 py-6 text-center text-sm text-zinc-400 dark:text-zinc-500">Ingen har markerat intresse ännu.</p>
                    @else
                        <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach($this->interests as $interest)
                                <li class="flex items-center gap-3 px-5 py-3 text-sm">
                                    <x-bootstrap-icon name="star-fill" class="size-3.5 shrink-0 text-brand-saffron" />
                                    <span class="flex-1 font-medium text-zinc-900 dark:text-white">{{ $interest->member->name }}</span>
                                    <span class="text-xs text-zinc-400">{{ $interest->created_at->isoFormat('D MMM YYYY') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- Inbjudningar --}}
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            Väntande inbjudningar — {{ $this->pendingInvitations->count() }}
                        </span>
                    </div>
                    <div class="p-5 space-y-4">

                        {{-- Bjud in enskild --}}
                        <div class="relative">
                            <input wire:model.live.debounce.300ms="inviteSearch"
                                   type="text"
                                   placeholder="Sök medlem att bjuda in…"
                                   class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
                            @if($this->inviteSearchResults->isNotEmpty())
                                <div class="absolute z-10 mt-1 w-full rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @foreach($this->inviteSearchResults as $member)
                                        <button wire:click="inviteMember({{ $member->id }})"
                                                class="flex w-full items-center gap-2 px-4 py-2.5 text-sm text-left hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                            <span class="font-medium text-zinc-900 dark:text-white">{{ $member->name }}</span>
                                            <span class="text-xs text-zinc-400">{{ $member->email }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        {{-- Bjud in hel sektion --}}
                        <div class="flex items-center gap-2">
                            <select wire:model.live="inviteSectionId"
                                    class="flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron dark:border-zinc-600 dark:bg-zinc-800 dark:text-white">
                                <option value="">Bjud in hel sektion…</option>
                                @foreach($this->sections as $section)
                                    <option value="{{ $section->id }}">{{ $section->name }}</option>
                                @endforeach
                            </select>
                            <button wire:click="inviteSection"
                                    @disabled(! $inviteSectionId)
                                    wire:confirm="Bjud in alla aktiva i sektionen?"
                                    class="rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors disabled:opacity-40">
                                Bjud in
                            </button>
                        </div>

                        {{-- Lista väntande --}}
                        @if($this->pendingInvitations->isNotEmpty())
                            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                                @foreach($this->pendingInvitations as $inv)
                                    <li class="flex items-center gap-3 px-4 py-2.5 text-sm bg-white dark:bg-zinc-900">
                                        <x-bootstrap-icon name="envelope" class="size-3.5 shrink-0 text-zinc-400" />
                                        <span class="flex-1 font-medium text-zinc-900 dark:text-white">{{ $inv->member?->name ?? $inv->email }}</span>
                                        @if($inv->invitedBy)
                                            <span class="text-xs text-zinc-400">via {{ $inv->invitedBy->name }}</span>
                                        @endif
                                        <span class="text-xs text-zinc-400">{{ $inv->created_at->isoFormat('D MMM') }}</span>
                                        <button wire:click="revokeInvitation({{ $inv->id }})"
                                                wire:confirm="Återkalla inbjudan till {{ $inv->member?->name ?? $inv->email }}?"
                                                class="text-zinc-300 hover:text-red-400 dark:text-zinc-600 dark:hover:text-red-400 transition-colors">
                                            <x-bootstrap-icon name="x-lg" class="size-3.5" />
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                {{-- Skicka meddelande --}}
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Skicka meddelande</span>
                    </div>
                    <div class="p-5 space-y-4">

                        {{-- Målgrupper --}}
                        <div>
                            <p class="mb-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">Skicka till</p>
                            <div class="flex flex-wrap gap-3">
                                @foreach([
                                    'confirmed'  => 'Anmälda (' . $event->registrations()->confirmed()->count() . ')',
                                    'waitlisted' => 'Väntelista (' . $event->registrations()->waitlisted()->count() . ')',
                                    'invited'    => 'Inbjudna ej anmälda (' . $event->invitations()->whereNull('accepted_at')->count() . ')',
                                    'interested' => 'Intresserade (' . $event->interests()->count() . ')',
                                ] as $key => $label)
                                    <label class="flex items-center gap-2 cursor-pointer text-sm text-zinc-700 dark:text-zinc-300">
                                        <input type="checkbox"
                                               wire:model="messageTargets"
                                               value="{{ $key }}"
                                               class="rounded border-zinc-300 text-brand focus:ring-brand dark:border-zinc-600" />
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        {{-- Meddelande --}}
                        <div>
                            <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">Meddelande</label>
                            <textarea wire:model="messageBody"
                                      rows="5"
                                      placeholder="Skriv ditt meddelande…"
                                      class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron dark:border-zinc-600 dark:bg-zinc-800 dark:text-white resize-none"></textarea>
                            @error('messageBody') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>

                        <button wire:click="sendMessage"
                                wire:confirm="Skicka meddelande till valda mottagare?"
                                class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                            <x-bootstrap-icon name="send" class="inline size-3.5 mr-1" />
                            Skicka
                        </button>
                    </div>
                </div>

            </div>
        @endif

        {{-- TAB: Economy --}}
        @if($tab === 'economy' && $this->canViewEconomy)
            <div class="space-y-6">

                {{-- Flash --}}
                @if($settlementFlash)
                    <div class="flex items-center gap-3 rounded-xl border border-green-200 bg-green-50 px-5 py-3 dark:border-green-900/40 dark:bg-green-900/20"
                         x-data x-init="setTimeout(() => $wire.set('settlementFlash', null), 4000)">
                        <x-bootstrap-icon name="check-circle-fill" class="size-4 shrink-0 text-green-600 dark:text-green-400" />
                        <span class="text-sm text-green-800 dark:text-green-300">{{ $settlementFlash }}</span>
                    </div>
                @endif

                {{-- Arrangör & modell --}}
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Extern arrangör</span>
                    </div>
                    <dl class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <div class="flex justify-between px-5 py-3 text-sm">
                            <dt class="text-zinc-500 dark:text-zinc-400">Arrangör</dt>
                            <dd class="text-zinc-900 dark:text-white">{{ $event->external_organizer_name ?? '–' }}</dd>
                        </div>
                        <div class="flex justify-between px-5 py-3 text-sm">
                            <dt class="text-zinc-500 dark:text-zinc-400">Kontakt</dt>
                            <dd class="text-zinc-900 dark:text-white">{{ $event->external_organizer_contact ?? '–' }}</dd>
                        </div>
                        <div class="flex justify-between px-5 py-3 text-sm">
                            <dt class="text-zinc-500 dark:text-zinc-400">Avgift (arrangören betalar)</dt>
                            <dd class="text-zinc-900 dark:text-white">
                                {{ $event->external_fee_ore ? $this->formatPrice($event->external_fee_ore) : '–' }}
                            </dd>
                        </div>
                        <div class="flex justify-between px-5 py-3 text-sm">
                            <dt class="text-zinc-500 dark:text-zinc-400">Intäktsmodell</dt>
                            <dd class="text-zinc-900 dark:text-white">
                                {{ match($event->external_revenue_type) {
                                    Event::REVENUE_NONE            => 'Ingen',
                                    Event::REVENUE_PERCENTAGE      => "Andel ({$event->external_revenue_pct} %)",
                                    Event::REVENUE_PER_PARTICIPANT => 'Per deltagare',
                                    Event::REVENUE_PER_EVENT       => 'Per evenemang',
                                    Event::REVENUE_PER_OCCURRENCE  => 'Per tillfälle',
                                    default                        => $event->external_revenue_type,
                                } }}
                            </dd>
                        </div>
                        <div class="flex justify-between px-5 py-3 text-sm">
                            <dt class="text-zinc-500 dark:text-zinc-400">Bekräftade deltagare</dt>
                            <dd class="text-zinc-900 dark:text-white">{{ $event->registrations()->confirmed()->count() }}</dd>
                        </div>
                        <div class="flex justify-between px-5 py-3 text-sm">
                            <dt class="text-zinc-500 dark:text-zinc-400">Beräknad utbetalning</dt>
                            <dd class="font-semibold text-zinc-900 dark:text-white">
                                {{ $this->formatPrice($event->calculateExternalSettlement()) }}
                            </dd>
                        </div>
                    </dl>
                </div>

                {{-- Avräkning --}}
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Avräkning</span>
                    </div>

                    @if($event->external_settled_at)
                        {{-- Already settled --}}
                        <div class="px-5 py-4 space-y-3">
                            <div class="flex items-center gap-3 rounded-lg bg-green-50 px-4 py-3 dark:bg-green-900/20">
                                <x-bootstrap-icon name="check-circle-fill" class="size-4 shrink-0 text-green-600 dark:text-green-400" />
                                <div>
                                    <p class="text-sm font-medium text-green-800 dark:text-green-300">
                                        Utbetald {{ $event->external_settled_at->isoFormat('D MMMM YYYY') }}
                                    </p>
                                    <p class="text-sm text-green-700 dark:text-green-400">
                                        {{ $this->formatPrice($event->external_settlement_ore) }}
                                    </p>
                                </div>
                            </div>
                            @if($this->canManage)
                                <button wire:click="registerSettlement"
                                        class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                    Registrera på nytt med nytt belopp ({{ $settlementInput }} kr)
                                </button>
                            @endif
                        </div>
                    @elseif($this->canManage)
                        {{-- Settlement form --}}
                        <div class="px-5 py-4 space-y-4">
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                Ange det faktiska beloppet som betalas ut till arrangören och bekräfta.
                            </p>
                            <div class="flex items-center gap-3">
                                <div class="relative flex-1 max-w-xs">
                                    <input wire:model="settlementInput"
                                           type="text"
                                           inputmode="decimal"
                                           placeholder="0,00"
                                           class="inp w-full pr-8" />
                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-zinc-400">kr</span>
                                </div>
                                <button wire:click="registerSettlement"
                                        wire:confirm="Registrera utbetalning på {{ $settlementInput }} kr?"
                                        class="btn btn-primary">
                                    <x-bootstrap-icon name="cash-stack" class="size-4" />
                                    Registrera utbetalning
                                </button>
                            </div>
                            <p class="text-xs text-zinc-400 dark:text-zinc-500">
                                Beräknat belopp: <strong>{{ $this->formatPrice($event->calculateExternalSettlement()) }}</strong>
                            </p>
                        </div>
                    @else
                        <p class="px-5 py-4 text-sm text-zinc-400 dark:text-zinc-500">Ingen utbetalning registrerad.</p>
                    @endif
                </div>

                {{-- Ställ in evenemang --}}
                @if($this->canManage && $event->status === Event::STATUS_PUBLISHED)
                    <div class="flex items-center justify-between rounded-xl border border-red-200 bg-red-50 px-5 py-4 dark:border-red-900/40 dark:bg-red-900/10">
                        <div>
                            <p class="text-sm font-medium text-red-700 dark:text-red-400">Ställ in evenemang</p>
                            <p class="text-xs text-red-600/70 dark:text-red-400/60">Avbokar alla anmälda och meddelar dem via e-post. Går inte att ångra.</p>
                        </div>
                        <button wire:click="cancelEvent"
                                wire:confirm="Ställ in {{ $event->title }}? Alla anmälda meddelas via e-post."
                                class="btn btn-danger btn-sm shrink-0 ml-4">
                            <x-bootstrap-icon name="x-circle-fill" class="size-3.5" />
                            Ställ in
                        </button>
                    </div>
                @endif

                {{-- Markera avslutat --}}
                @if($this->canManage && $event->status === Event::STATUS_PUBLISHED)
                    <div class="flex items-center justify-between rounded-xl border border-zinc-200 bg-zinc-50 px-5 py-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                        <div>
                            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Avsluta evenemang</p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Markerar evenemanget som avslutat. Det döljs från publika listor.</p>
                        </div>
                        <button wire:click="markCompleted"
                                wire:confirm="Markera {{ $event->title }} som avslutat?"
                                class="btn btn-secondary btn-sm shrink-0 ml-4">
                            <x-bootstrap-icon name="flag-fill" class="size-3.5" />
                            Markera avslutat
                        </button>
                    </div>
                @endif

            </div>
        @endif

    </div>
</div>
