<?php

namespace App\Livewire\Admin\Meeting;

use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\InboxMailbox;
use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingAgendaItem;
use App\Models\Meeting\MeetingTemplate;
use App\Models\Organization\Section;
use App\Models\Submission;
use App\Services\InboxReaderService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class SubmissionShow extends Component
{
    public Submission $submission;

    public string $newComment         = '';
    public ?int   $editingCommentId   = null;
    public string $editingCommentBody = '';

    public string $addToMeetingId = '';

    public bool   $replyOpen = false;
    public string $replyBody = '';

    public function mount(Submission $submission): void
    {
        abort_unless(Auth::check() && Auth::user()->hasAnyPermission(['submissions.view', 'submissions.manage']), 403);
        $this->submission = $submission;
    }

    public function setStatus(string $status): void
    {
        abort_unless(Auth::user()->hasPermissionTo('submissions.manage'), 403);
        abort_unless(\array_key_exists($status, Submission::STATUSES), 422);

        $prev = $this->submission->status;
        $extra = $status === 'resolved' ? ['resolved_at' => now()] : [];
        if ($status !== 'resolved') {
            $extra['resolved_at'] = null;
        }

        $this->submission->update(['status' => $status, ...$extra]);
        $this->submission->refresh();
        AuditLog::record('meeting_submission_status_changed', 'meeting_submissions', $this->submission->title, $this->submission, ['from' => $prev, 'to' => $status]);
    }

    public function saveBoardResponse(string $value): void
    {
        abort_unless(Auth::user()->hasPermissionTo('submissions.manage'), 403);
        $this->submission->update(['board_response' => $value ?: null]);
        $this->submission->refresh();
    }

    public function addToAgenda(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('submissions.manage'), 403);
        $this->validate(['addToMeetingId' => ['required', 'exists:meetings,id']], [], ['addToMeetingId' => 'möte']);

        $meeting   = Meeting::findOrFail($this->addToMeetingId);
        $lastOrder = $meeting->agendaItems()->max('sort_order') ?? -1;

        $item = $meeting->agendaItems()->create([
            'sort_order'  => $lastOrder + 1,
            'title'       => $this->submission->title,
            'description' => $this->submission->body,
            'item_type'   => $this->submission->type === 'motion' ? 'vote' : 'standard',
            'is_required' => false,
        ]);

        $this->submission->update([
            'agenda_item_id' => $item->id,
            'status'         => 'on_agenda',
        ]);

        AuditLog::record('meeting_submission_added_to_agenda', 'meeting_submissions', $this->submission->title, $this->submission, ['meeting_id' => $meeting->id, 'meeting_title' => $meeting->title]);

        $this->addToMeetingId = '';
        $this->submission->refresh();
    }

    public function openReply(): void
    {
        $this->replyBody = '';
        $this->replyOpen = true;
    }

    public function closeReply(): void
    {
        $this->replyOpen = false;
    }

    public function sendReply(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('submissions.manage'), 403);
        abort_unless($this->submission->source_mailbox_id && $this->submission->source_gmail_id, 422);

        $this->validate(['replyBody' => 'required|string|min:1'], [], ['replyBody' => 'svar']);

        $mailbox = InboxMailbox::findOrFail($this->submission->source_mailbox_id);
        $reader  = new InboxReaderService($mailbox);
        $original = $reader->getMessage($this->submission->source_gmail_id);

        $ok = $reader->replyExtended(
            threadId:  $original['thread_id'] ?? '',
            to:        $this->submission->source_gmail_from,
            cc:        null,
            subject:   'Re: ' . ($this->submission->source_gmail_subject ?? $this->submission->title),
            body:      $this->replyBody,
            isHtml:    false,
            inReplyTo: $original['message_id'] ?? null,
        );

        if ($ok) {
            $this->replyBody = '';
            $this->replyOpen = false;
            $this->dispatch('notify', type: 'success', message: 'Svar skickat.');
        } else {
            $this->addError('replyBody', 'Kunde inte skicka svaret. Försök igen.');
        }
    }

    public function addComment(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasAnyPermission(['submissions.view', 'submissions.manage']), 403);
        $this->validate(['newComment' => ['required', 'string', 'max:5000']], [], ['newComment' => 'kommentar']);

        $this->submission->comments()->create([
            'author_id' => Auth::id(),
            'body'      => $this->newComment,
        ]);

        $this->newComment = '';
        $this->submission->refresh();
    }

    public function editComment(int $id): void
    {
        $comment = Comment::findOrFail($id);
        abort_unless($comment->author_id === Auth::id() || Auth::user()->hasPermissionTo('submissions.manage'), 403);
        $this->editingCommentId   = $id;
        $this->editingCommentBody = $comment->body;
    }

    public function updateComment(): void
    {
        $comment = Comment::findOrFail($this->editingCommentId);
        abort_unless($comment->author_id === Auth::id() || Auth::user()->hasPermissionTo('submissions.manage'), 403);
        $this->validate(['editingCommentBody' => ['required', 'string', 'max:5000']], [], ['editingCommentBody' => 'kommentar']);

        $comment->update(['body' => $this->editingCommentBody, 'edited_at' => now()]);
        $this->editingCommentId   = null;
        $this->editingCommentBody = '';
        $this->submission->refresh();
    }

    public function cancelEditComment(): void
    {
        $this->editingCommentId = null;
    }

    public function deleteComment(int $id): void
    {
        $comment = Comment::findOrFail($id);
        abort_unless($comment->author_id === Auth::id() || Auth::user()->hasPermissionTo('submissions.manage'), 403);
        $comment->delete();
        $this->submission->refresh();
    }

    public function render()
    {
        $this->submission->load('section', 'agendaItem.meeting', 'createdBy', 'sourceMailbox');
        $comments = $this->submission->comments()->with('author')->get();

        $upcomingMeetings = Meeting::whereIn('status', ['draft', 'notice_sent'])
            ->where('scheduled_at', '>=', now())
            ->when($this->submission->type === 'motion', fn ($q) =>
                $q->whereIn('meeting_type', MeetingTemplate::MOTION_TYPES)
            )
            ->orderBy('scheduled_at')
            ->get();

        return view('livewire.admin.meeting.submission-show', [
            'comments'         => $comments,
            'upcomingMeetings' => $upcomingMeetings,
        ]);
    }
}
