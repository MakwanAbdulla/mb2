<?php

namespace App\Livewire\Admin\Meeting;

use App\Models\AuditLog;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingResource;
use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingTemplate;
use App\Models\Organization\Section;
use App\Services\GoogleWorkspaceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class MeetingForm extends Component
{
    public ?Meeting $meeting = null;

    public string $templateId    = '';
    public string $sectionId    = '';
    public string $title        = '';
    public string $meetingType     = 'board_meeting';
    public bool   $isExtraordinary = false;
    public string $status       = 'draft';
    public string $scheduledAt    = '';
    public string $endsAt         = '';
    public string $motionDeadline = '';
    public string $location       = '';
    public ?int   $bookingResourceId = null;
    public bool   $includeAbsencesInMinutes = true;

    public bool $importFromTemplate = true;
    public bool $autoInviteMembers  = true;
    public bool $createMeetLink     = false;

    public function mount(?Meeting $meeting = null): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('meetings.manage'), 403);

        if ($meeting?->exists) {
            $this->meeting                  = $meeting->load('booking');
            $this->templateId               = (string) ($meeting->template_id ?? '');
            $this->sectionId                = (string) ($meeting->section_id ?? '');
            $this->title                    = $meeting->title;
            $this->meetingType              = $meeting->meeting_type;
            $this->isExtraordinary          = $meeting->is_extraordinary;
            $this->status                   = $meeting->status;
            $this->scheduledAt              = $meeting->scheduled_at->format('Y-m-d\TH:i');
            $this->endsAt                   = $meeting->ends_at?->format('Y-m-d\TH:i') ?? '';
            $this->motionDeadline           = $meeting->motion_deadline?->format('Y-m-d') ?? '';
            $this->location                 = $meeting->location ?? '';
            $this->bookingResourceId        = $meeting->booking?->resource_id;
            $this->includeAbsencesInMinutes = $meeting->include_absences_in_minutes;
        }
    }

    public function updatedScheduledAt(): void
    {
        if ($this->scheduledAt && ! $this->endsAt) {
            try {
                $this->endsAt = Carbon::parse($this->scheduledAt)->addHours(2)->format('Y-m-d\TH:i');
            } catch (\Throwable) {
                // invalid input — leave endsAt empty
            }
        }
    }

    public function updatedTemplateId(): void
    {
        if (! $this->templateId || $this->meeting) {
            return;
        }
        $template = MeetingTemplate::find($this->templateId);
        if (! $template) {
            return;
        }
        if (! $this->title) {
            $this->title = $template->name;
        }
        $this->meetingType             = $template->meeting_type;
        $this->isExtraordinary         = $template->is_extraordinary;
        $this->includeAbsencesInMinutes = $template->include_absences_in_minutes;
        if (! $this->sectionId && $template->section_id) {
            $this->sectionId = (string) $template->section_id;
        }
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $this->validate([
            'title'           => ['required', 'string', 'max:255'],
            'meetingType'     => ['required', 'in:board_meeting,members_meeting,annual_general,section,other'],
            'isExtraordinary' => ['boolean'],
            'status'          => ['required', 'in:draft,notice_sent,ongoing,pending_approval,approved,published,cancelled'],
            'scheduledAt'     => ['required', 'date'],
            'endsAt'          => ['required', 'date', 'after:scheduledAt'],
            'motionDeadline'  => ['nullable', 'date', 'before:scheduledAt'],
            'location'        => ['nullable', 'string', 'max:255'],
            'sectionId'       => ['nullable', 'exists:sections,id'],
            'templateId'      => ['nullable', 'exists:meeting_templates,id'],
            'bookingResourceId' => ['nullable', 'exists:booking_resources,id'],
        ], [
            'endsAt.after'    => 'Sluttiden måste vara efter starttiden.',
        ], [
            'title'       => 'rubrik',
            'scheduledAt' => 'datum och tid',
            'endsAt'      => 'sluttid',
        ]);

        // Conflict check before touching DB
        if ($this->bookingResourceId && $this->endsAt) {
            $resource          = BookingResource::find($this->bookingResourceId);
            $existingBookingId = $this->meeting?->booking_id;
            if ($resource && ! $resource->isAvailable(Carbon::parse($this->scheduledAt), Carbon::parse($this->endsAt), $existingBookingId)) {
                $this->addError('bookingResourceId', "\"{$resource->name}\" är redan bokad under den valda tiden.");
                return;
            }
        }

        $attributes = [
            'template_id'                 => $this->templateId ?: null,
            'section_id'                  => $this->sectionId ?: null,
            'title'                       => $this->title,
            'meeting_type'                => $this->meetingType,
            'is_extraordinary'            => $this->isExtraordinary,
            'status'                      => $this->status,
            'scheduled_at'                => $this->scheduledAt,
            'ends_at'                     => $this->endsAt ?: null,
            'motion_deadline'             => $this->motionDeadline ?: null,
            'location'                    => $this->location ?: null,
            'include_absences_in_minutes' => $this->includeAbsencesInMinutes,
            'created_by'                  => Auth::id(),
        ];

        if ($this->createMeetLink) {
            $link = app(GoogleWorkspaceService::class)->createMeetSpace();
            if ($link) {
                $attributes['meet_link'] = $link;
            } else {
                $this->addError('createMeetLink', 'Kunde inte skapa Meet-länk. Kontrollera att Meet API är aktiverat.');
                return;
            }
        }

        if ($this->meeting) {
            $this->meeting->update($attributes);
            $this->syncMeetingBooking($this->meeting->fresh(), $this->bookingResourceId);
            $id = $this->meeting->id;
            AuditLog::record('meeting_updated', 'meetings', $this->meeting->title, $this->meeting);
        } else {
            $meeting = Meeting::create($attributes);
            $id      = $meeting->id;

            if ($this->importFromTemplate && $this->templateId) {
                $template = MeetingTemplate::find($this->templateId);
                $meeting->importFromTemplate($template);
            }

            if ($this->autoInviteMembers && $this->sectionId) {
                $meeting->inviteMembers();
            }

            $this->syncMeetingBooking($meeting, $this->bookingResourceId);
            AuditLog::record('meeting_created', 'meetings', $meeting->title, $meeting);
        }

        $this->redirect(route('admin.meetings.show', $id), navigate: true);
    }

    private function syncMeetingBooking(Meeting $meeting, ?int $resourceId): void
    {
        $existing = $meeting->booking;

        if (! $resourceId || ! $this->endsAt) {
            if ($existing) {
                $existing->delete();
                $meeting->update(['booking_id' => null]);
            }
            return;
        }

        $resource = BookingResource::find($resourceId);
        if (! $resource) return;

        $startsAt = $meeting->scheduled_at;
        $endsAt   = Carbon::parse($this->endsAt);

        if ($existing) {
            $existing->update([
                'resource_id' => $resourceId,
                'starts_at'   => $startsAt,
                'ends_at'     => $endsAt,
                'title'       => $meeting->title,
            ]);
        } else {
            $booking = Booking::create([
                'source_type'       => Meeting::class,
                'source_id'         => $meeting->id,
                'resource_id'       => $resourceId,
                'user_id'           => Auth::id(),
                'section_id'        => $meeting->section_id,
                'title'             => $meeting->title,
                'activity'          => 'Möte',
                'booking_type'      => 'group',
                'starts_at'         => $startsAt,
                'ends_at'           => $endsAt,
                'status'            => $resource->requires_approval ? 'pending' : 'confirmed',
                'exempt_from_limit' => true,
            ]);
            $meeting->update(['booking_id' => $booking->id]);
        }
    }

    public function render()
    {
        return view('livewire.admin.meeting.meeting-form', [
            'templates'           => MeetingTemplate::orderBy('name')->get(),
            'sections'            => Section::where('is_active', true)->orderBy('name')->get(),
            'meetingTypes'        => MeetingTemplate::TYPES,
            'statuses'            => Meeting::STATUSES,
            'bookingResources'    => BookingResource::where('active', true)->orderBy('name')->get(['id', 'name', 'location']),
            'workspaceConfigured' => app(GoogleWorkspaceService::class)->isConfigured(),
        ]);
    }
}
