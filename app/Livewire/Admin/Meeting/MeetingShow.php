<?php

namespace App\Livewire\Admin\Meeting;

use App\Models\AuditLog;
use App\Services\GoogleWorkspaceService;
use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingAgendaItem;
use App\Models\Meeting\MeetingAttendee;
use App\Models\Meeting\MeetingDocument;
use App\Models\Meeting\MeetingMinutesItem;
use App\Models\Meeting\MeetingTask;
use App\Models\Submission;
use App\Models\Meeting\MeetingTemplate;
use App\Models\Membership\Member;
use App\Support\StoredUpload;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class MeetingShow extends Component
{
    use WithFileUploads;

    public Meeting $meeting;

    // Minutes editing
    public ?int    $editingMinutesId = null;
    public string  $editingContent  = '';
    public string  $editingDecision = '';
    public bool    $editingIsDecision = false;

    // Attendee check-in
    public string $searchAttendee = '';

    // Agenda file uploads
    /** @var array<int, TemporaryUploadedFile|null> */
    public array $agendaFiles = [];

    // Add agenda item on the fly
    public string $newAgendaItemTitle = '';
    public string $newAgendaItemType  = 'standard';

    // Save as template
    public string $newTemplateName     = '';
    public bool   $showTemplateSaveForm = false;

    public function mount(Meeting $meeting): void
    {
        abort_unless(Auth::check() && Auth::user()->hasAnyPermission(['meetings.view', 'meetings.manage']), 403);
        $this->meeting = $meeting;
    }

    public function editMinutes(int $agendaItemId): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $this->editingMinutesId = $agendaItemId;
        $existing = MeetingMinutesItem::where('agenda_item_id', $agendaItemId)->first();

        if ($existing) {
            $this->editingContent    = $existing->content ?? '';
            $this->editingDecision   = $existing->decision ?? '';
            $this->editingIsDecision = $existing->is_decision;
        } else {
            $agendaItem = MeetingAgendaItem::find($agendaItemId);
            $template   = $agendaItem?->minutes_template ?? '';
            $this->editingContent    = $template !== ''
                ? $template
                : ($agendaItem?->item_type === 'election' ? $this->generateElectionText($agendaItem->title) : '');
            $this->editingDecision   = '';
            $this->editingIsDecision = \in_array($agendaItem?->item_type, ['vote', 'election']);
        }
    }

    public function saveMinutes(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $this->validate([
            'editingContent'  => ['nullable', 'string'],
            'editingDecision' => ['nullable', 'string', 'max:2000'],
        ]);

        MeetingMinutesItem::updateOrCreate(
            ['meeting_id' => $this->meeting->id, 'agenda_item_id' => $this->editingMinutesId],
            [
                'content'    => $this->editingContent ?: null,
                'decision'   => $this->editingIsDecision ? ($this->editingDecision ?: null) : null,
                'is_decision' => $this->editingIsDecision,
            ]
        );

        AuditLog::record('meeting_minutes_saved', 'meetings', $this->meeting->title, $this->meeting);

        $this->editingMinutesId = null;
        $this->editingContent   = '';
        $this->editingDecision  = '';
        $this->editingIsDecision = false;
        $this->meeting->refresh();
    }

    public function cancelMinutes(): void
    {
        $this->editingMinutesId = null;
    }

    /** Bjud in relevanta medlemmar (sektionsmedlemmar eller alla aktiva) */
    public function inviteMembers(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);
        $this->meeting->inviteMembers();
        $this->meeting->refresh();
    }

    public function togglePresent(int $attendeeId): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $attendee = MeetingAttendee::findOrFail($attendeeId);
        abort_unless($attendee->meeting_id === $this->meeting->id, 403);

        if ($attendee->present === null) {
            $attendee->update([
                'present'                => true,
                'membership_verified_at' => now(),
                'membership_valid'       => $attendee->member?->status === 'active',
            ]);
        } elseif ($attendee->present === true) {
            $attendee->update(['present' => false]);
        } else {
            $attendee->update(['present' => null]);
        }
    }

    public function setAttendeeRole(int $attendeeId, string $role): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        abort_unless(\array_key_exists($role, MeetingAttendee::ROLES), 422);

        $attendee = MeetingAttendee::findOrFail($attendeeId);
        abort_unless($attendee->meeting_id === $this->meeting->id, 403);
        $attendee->update(['role' => $role]);
    }

    public function removeAttendee(int $attendeeId): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $attendee = MeetingAttendee::findOrFail($attendeeId);
        abort_unless($attendee->meeting_id === $this->meeting->id, 403);
        $attendee->delete();
        AuditLog::record('meeting_attendee_removed', 'meetings', $this->meeting->title, $this->meeting);
    }

    public function advanceStatus(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $next = $this->meeting->nextStatus();
        if (! $next) {
            return;
        }

        $extra = match($next) {
            'approved'  => ['approved_at'  => now()],
            'published' => ['published_at' => now()],
            default     => [],
        };

        $prev = $this->meeting->status;
        $this->meeting->update(array_merge(['status' => $next], $extra));
        $this->meeting->refresh();
        AuditLog::record('meeting_status_changed', 'meetings', $this->meeting->title, $this->meeting, ['from' => $prev, 'to' => $next]);
    }

    public function generateMeetLink(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $link = app(GoogleWorkspaceService::class)->createMeetSpace();
        if ($link) {
            $this->meeting->update(['meet_link' => $link]);
            $this->meeting->refresh();
            AuditLog::record('meeting_updated', 'meetings', $this->meeting->title, $this->meeting, ['meet_link' => $link]);
        } else {
            session()->flash('meet_error', 'Kunde inte skapa Meet-länk. Kontrollera att Meet API är aktiverat i Google Cloud Console.');
        }
    }

    public function removeMeetLink(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $this->meeting->update(['meet_link' => null]);
        $this->meeting->refresh();
        AuditLog::record('meeting_updated', 'meetings', $this->meeting->title, $this->meeting, ['meet_link' => null]);
    }

    public function revertStatus(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $prev = match($this->meeting->status) {
            'notice_sent'      => 'draft',
            'ongoing'          => 'notice_sent',
            'pending_approval' => 'ongoing',
            'approved'         => ['status' => 'pending_approval', 'approved_at'  => null],
            'published'        => ['status' => 'approved',         'published_at' => null],
            default            => null,
        };

        if (! $prev) {
            return;
        }

        $currentStatus = $this->meeting->status;
        $this->meeting->update(\is_array($prev) ? $prev : ['status' => $prev]);
        $this->meeting->refresh();
        AuditLog::record('meeting_status_changed', 'meetings', $this->meeting->title, $this->meeting, ['from' => $currentStatus, 'to' => $this->meeting->status]);
    }

    public function render()
    {
        $agendaItems = $this->meeting->agendaItems()
            ->with('minutesItem', 'documents')
            ->get();

        $minutesMap = $agendaItems->mapWithKeys(fn ($item) => [
            $item->id => $item->minutesItem,
        ]);

        $attendees = $this->meeting->attendees()
            ->with('member', 'proxyFor')
            ->orderByRaw("CASE role WHEN 'chair' THEN 0 WHEN 'secretary' THEN 1 WHEN 'adjuster' THEN 2 ELSE 3 END")
            ->orderBy('invited', 'desc')
            ->get();

        $searchResults = [];
        if (\strlen($this->searchAttendee) >= 2) {
            $existing = $attendees->pluck('member_id')->filter()->toArray();
            $term = mb_strtolower($this->searchAttendee);
            $searchResults = Member::whereNotIn('id', $existing)
                ->get()
                ->filter(fn ($m) =>
                    str_contains(mb_strtolower($m->first_name . ' ' . $m->last_name), $term)
                    || str_contains(mb_strtolower($m->email ?? ''), $term)
                )
                ->take(8)
                ->values();
        }

        $meetingSectionId   = $this->meeting->section_id;
        $acceptsMotions     = $this->meeting->acceptsMotions();
        $pendingSubmissions = Submission::query()
            ->whereIn('status', ['submitted', 'under_review'])
            ->where(function ($q) use ($meetingSectionId, $acceptsMotions) {
                $q->where(function ($inner) use ($meetingSectionId) {
                    if ($meetingSectionId) {
                        $inner->where('section_id', $meetingSectionId)
                              ->orWhereNull('section_id');
                    } else {
                        $inner->whereNull('section_id');
                    }
                });
                if ($acceptsMotions) {
                    $q->orWhere('type', 'motion');
                }
            })
            ->with('createdBy')
            ->orderBy('created_at')
            ->get();

        return view('livewire.admin.meeting.meeting-show', [
            'agendaItems'          => $agendaItems,
            'minutesMap'           => $minutesMap,
            'attendees'            => $attendees,
            'searchResults'        => $searchResults,
            'pendingSubmissions'   => $pendingSubmissions,
            'workspaceConfigured'  => app(GoogleWorkspaceService::class)->isConfigured(),
        ]);
    }

    public function uploadAgendaFile(int $agendaItemId): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $key = "agendaFiles.{$agendaItemId}";
        $this->validateOnly($key, [
            $key => ['required', 'file', 'max:20480'],
        ]);

        $upload = StoredUpload::store($this->agendaFiles[$agendaItemId], 'local', "meetings/{$this->meeting->id}/agenda/{$agendaItemId}");

        $upload->persist(fn (StoredUpload $upload) => MeetingDocument::create([
            'meeting_id'     => $this->meeting->id,
            'agenda_item_id' => $agendaItemId,
            'type'           => 'attachment',
            'label'          => pathinfo($upload->originalName, PATHINFO_FILENAME),
            'disk'           => $upload->disk,
            'path'           => $upload->path,
            'original_name'  => $upload->originalName,
            'mime_type'      => $upload->mimeType,
            'size_bytes'     => $upload->sizeBytes,
            'uploaded_by'    => Auth::id(),
        ]));

        AuditLog::record('meeting_document_uploaded', 'meetings', $this->meeting->title . ' — ' . $upload->originalName, $this->meeting);
        unset($this->agendaFiles[$agendaItemId]);
        $this->meeting->refresh();
    }

    public function toggleProtocolAttachment(int $documentId): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $doc = MeetingDocument::findOrFail($documentId);
        abort_unless($doc->meeting_id === $this->meeting->id, 403);
        $doc->update(['is_protocol_attachment' => ! $doc->is_protocol_attachment]);
    }

    public function deleteDocument(int $documentId): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $doc = MeetingDocument::findOrFail($documentId);
        abort_unless($doc->meeting_id === $this->meeting->id, 403);

        Storage::disk($doc->disk)->delete($doc->path);
        AuditLog::record('meeting_document_deleted', 'meetings', $this->meeting->title . ' — ' . $doc->original_name, $this->meeting);
        $doc->delete();
        $this->meeting->refresh();
    }

    public function moveAgendaItemUp(int $itemId): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $items = $this->meeting->agendaItems()->orderBy('sort_order')->get();
        $index = $items->search(fn ($i) => $i->id === $itemId);

        if ($index < 1) {
            return;
        }

        $prev = $items[$index - 1];
        $curr = $items[$index];
        [$prev->sort_order, $curr->sort_order] = [$curr->sort_order, $prev->sort_order];
        $prev->save();
        $curr->save();
    }

    public function moveAgendaItemDown(int $itemId): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $items = $this->meeting->agendaItems()->orderBy('sort_order')->get();
        $index = $items->search(fn ($i) => $i->id === $itemId);

        if ($index === false || $index >= $items->count() - 1) {
            return;
        }

        $next = $items[$index + 1];
        $curr = $items[$index];
        [$curr->sort_order, $next->sort_order] = [$next->sort_order, $curr->sort_order];
        $curr->save();
        $next->save();
    }

    public function saveAgendaField(int $itemId, string $field, string $value): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        abort_unless(\in_array($field, ['title', 'description', 'minutes_template']), 422);

        $item = MeetingAgendaItem::findOrFail($itemId);
        abort_unless($item->meeting_id === $this->meeting->id, 403);

        $item->update([$field => $value ?: null]);
    }

    public function addAgendaItem(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $this->validate([
            'newAgendaItemTitle' => ['required', 'string', 'max:255'],
            'newAgendaItemType'  => ['required', 'in:standard,vote,election,info'],
        ], [], [
            'newAgendaItemTitle' => 'rubrik',
        ]);

        $lastOrder = $this->meeting->agendaItems()->max('sort_order') ?? -1;

        $this->meeting->agendaItems()->create([
            'sort_order'  => $lastOrder + 1,
            'title'       => $this->newAgendaItemTitle,
            'item_type'   => $this->newAgendaItemType,
            'is_required' => false,
        ]);

        AuditLog::record('meeting_agenda_item_added', 'meetings', $this->meeting->title . ' — ' . $this->newAgendaItemTitle, $this->meeting);
        $this->newAgendaItemTitle = '';
        $this->newAgendaItemType  = 'standard';
        $this->meeting->refresh();
    }

    private function generateElectionText(string $title): string
    {
        $lower     = mb_strtolower($title);
        $attendees = $this->meeting->attendees()->with('member')->get();

        $role = match(true) {
            str_contains($lower, 'ordförande')  => 'chair',
            str_contains($lower, 'sekreterare') => 'secretary',
            str_contains($lower, 'justering')   => 'adjuster',
            default                             => null,
        };

        if ($role === null) {
            return '';
        }

        $matched = $attendees->where('role', $role);
        if ($matched->isEmpty()) {
            return '';
        }

        $names = $matched->map(fn ($a) => $a->displayName())->join(' och ');

        return match($role) {
            'chair'     => "Till mötesordförande valdes {$names}.",
            'secretary' => "Till mötessekreterare valdes {$names}.",
            'adjuster'  => "Till justeringspersoner valdes {$names}.",
        };
    }

    public function saveAsNewTemplate(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $this->validate(['newTemplateName' => ['required', 'string', 'max:255']], [], ['newTemplateName' => 'mallnamn']);

        $template = MeetingTemplate::create([
            'name'                        => $this->newTemplateName,
            'section_id'                  => $this->meeting->section_id,
            'meeting_type'                => $this->meeting->meeting_type,
            'default_notice_days'         => 14,
            'include_absences_in_minutes' => $this->meeting->include_absences_in_minutes,
            'created_by'                  => Auth::id(),
        ]);

        $this->syncTemplateItems($template);

        AuditLog::record('meeting_template_saved', 'meeting_templates', $template->name, $template);
        $this->showTemplateSaveForm = false;
        $this->newTemplateName      = '';

        $this->redirect(route('admin.meetings.templates.show', $template), navigate: true);
    }

    public function updateExistingTemplate(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);
        abort_unless($this->meeting->template_id, 422);

        $template = MeetingTemplate::findOrFail($this->meeting->template_id);
        $template->items()->delete();
        $this->syncTemplateItems($template);

        AuditLog::record('meeting_template_saved', 'meeting_templates', $template->name, $template);
        $this->redirect(route('admin.meetings.templates.show', $template), navigate: true);
    }

    public function addSubmissionToAgenda(int $submissionId): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $submission = Submission::findOrFail($submissionId);
        $lastOrder  = $this->meeting->agendaItems()->max('sort_order') ?? -1;

        $item = $this->meeting->agendaItems()->create([
            'sort_order'  => $lastOrder + 1,
            'title'       => $submission->title,
            'description' => $submission->body,
            'item_type'   => $submission->type === 'motion' ? 'vote' : 'standard',
            'is_required' => false,
        ]);

        $submission->update(['agenda_item_id' => $item->id, 'status' => 'on_agenda']);
        AuditLog::record('meeting_submission_added_to_agenda', 'meetings', $this->meeting->title . ' — ' . $submission->title, $this->meeting);
        $this->meeting->refresh();
    }

    public function createTaskFromMinutes(int $agendaItemId): void
    {
        abort_unless(Auth::user()->hasPermissionTo('tasks.manage'), 403);

        $item = MeetingAgendaItem::findOrFail($agendaItemId);
        abort_unless($item->meeting_id === $this->meeting->id, 403);

        $task = MeetingTask::create([
            'title'           => $item->title,
            'sourceable_type' => MeetingAgendaItem::class,
            'sourceable_id'   => $agendaItemId,
            'section_id'      => $this->meeting->section_id,
            'status'          => 'open',
            'created_by'      => Auth::id(),
        ]);

        AuditLog::record('meeting_task_created', 'meeting_tasks', $task->title, $task);
        $this->redirect(route('admin.tasks.show', $task), navigate: true);
    }

    private function syncTemplateItems(MeetingTemplate $template): void
    {
        foreach ($this->meeting->agendaItems()->orderBy('sort_order')->get() as $item) {
            $template->items()->create([
                'sort_order'       => $item->sort_order,
                'title'            => $item->title,
                'description'      => $item->description,
                'minutes_template' => $item->minutes_template,
                'item_type'        => $item->item_type,
                'is_required'      => $item->is_required,
            ]);
        }
    }

    public function addAttendee(int $memberId): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $member = Member::findOrFail($memberId);

        $this->meeting->attendees()->firstOrCreate(
            ['member_id' => $memberId],
            [
                'invited'       => false,
                'present'       => true,
                'voting_rights' => $member->status === 'active',
                'membership_verified_at' => now(),
                'membership_valid'       => $member->status === 'active',
            ]
        );

        AuditLog::record('meeting_attendee_added', 'meetings', $this->meeting->title . ' — ' . $member->full_name, $this->meeting);
        $this->searchAttendee = '';
        $this->meeting->refresh();
    }

    public function addExternalAttendee(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $name = trim($this->searchAttendee);
        if (! $name) {
            return;
        }

        $this->meeting->attendees()->create([
            'member_id' => null,
            'name'      => $name,
            'invited'   => false,
            'present'   => true,
            'voting_rights' => false,
        ]);

        AuditLog::record('meeting_attendee_added', 'meetings', $this->meeting->title . ' — ' . $name, $this->meeting);
        $this->searchAttendee = '';
        $this->meeting->refresh();
    }
}
