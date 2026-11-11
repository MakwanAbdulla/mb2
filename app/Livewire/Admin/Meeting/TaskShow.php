<?php

namespace App\Livewire\Admin\Meeting;

use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\Meeting\MeetingTask;
use App\Models\Membership\Member;
use App\Models\Organization\Section;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class TaskShow extends Component
{
    public MeetingTask $task;

    public string $newComment         = '';
    public ?int   $editingCommentId   = null;
    public string $editingCommentBody = '';
    public string $searchAssignee     = '';

    public function mount(MeetingTask $task): void
    {
        abort_unless(Auth::check() && Auth::user()->hasAnyPermission(['tasks.view', 'tasks.manage']), 403);
        $this->task = $task;
    }

    public function saveField(string $field, mixed $value): void
    {
        abort_unless(Auth::user()->hasPermissionTo('tasks.manage'), 403);
        abort_unless(\in_array($field, ['title', 'description', 'due_date', 'section_id']), 422);

        $this->task->update([$field => ($value === '' || $value === null) ? null : $value]);
        AuditLog::record('meeting_task_updated', 'meeting_tasks', $this->task->title, $this->task);
        $this->task->refresh();
    }

    public function setStatus(string $status): void
    {
        abort_unless(Auth::user()->hasPermissionTo('tasks.manage'), 403);
        abort_unless(\array_key_exists($status, MeetingTask::STATUSES), 422);

        $prev = $this->task->status;
        $this->task->update([
            'status'       => $status,
            'completed_at' => $status === 'done' ? now() : null,
        ]);
        $this->task->refresh();
        AuditLog::record('meeting_task_status_changed', 'meeting_tasks', $this->task->title, $this->task, ['from' => $prev, 'to' => $status]);
    }

    public function addComment(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasAnyPermission(['tasks.view', 'tasks.manage']), 403);

        $this->validate(['newComment' => ['required', 'string', 'max:5000']], [], ['newComment' => 'kommentar']);

        $this->task->comments()->create([
            'author_id' => Auth::id(),
            'body'      => $this->newComment,
        ]);

        AuditLog::record('meeting_task_comment_added', 'meeting_tasks', $this->task->title, $this->task);
        $this->newComment = '';
        $this->task->refresh();
    }

    public function editComment(int $id): void
    {
        $comment = Comment::findOrFail($id);
        abort_unless(
            $comment->author_id === Auth::id() || Auth::user()->hasPermissionTo('tasks.manage'),
            403
        );

        $this->editingCommentId   = $id;
        $this->editingCommentBody = $comment->body;
    }

    public function updateComment(): void
    {
        $comment = Comment::findOrFail($this->editingCommentId);
        abort_unless(
            $comment->author_id === Auth::id() || Auth::user()->hasPermissionTo('tasks.manage'),
            403
        );

        $this->validate(['editingCommentBody' => ['required', 'string', 'max:5000']], [], ['editingCommentBody' => 'kommentar']);

        $comment->update(['body' => $this->editingCommentBody, 'edited_at' => now()]);
        AuditLog::record('meeting_task_comment_updated', 'meeting_tasks', $this->task->title, $this->task);
        $this->editingCommentId   = null;
        $this->editingCommentBody = '';
        $this->task->refresh();
    }

    public function cancelEditComment(): void
    {
        $this->editingCommentId = null;
    }

    public function deleteComment(int $id): void
    {
        $comment = Comment::findOrFail($id);
        abort_unless(
            $comment->author_id === Auth::id() || Auth::user()->hasPermissionTo('tasks.manage'),
            403
        );

        AuditLog::record('meeting_task_comment_deleted', 'meeting_tasks', $this->task->title, $this->task);
        $comment->delete();
        $this->task->refresh();
    }

    public function addAssignee(int $memberId): void
    {
        abort_unless(Auth::user()->hasPermissionTo('tasks.manage'), 403);

        $member = Member::findOrFail($memberId);
        $this->task->assignees()->syncWithoutDetaching([$memberId]);
        AuditLog::record('meeting_task_assignee_added', 'meeting_tasks', $this->task->title . ' — ' . $member->full_name, $this->task);
        $this->searchAssignee = '';
        $this->task->refresh();
    }

    public function removeAssignee(int $memberId): void
    {
        abort_unless(Auth::user()->hasPermissionTo('tasks.manage'), 403);

        $this->task->assignees()->detach($memberId);
        AuditLog::record('meeting_task_assignee_removed', 'meeting_tasks', $this->task->title, $this->task);
        $this->task->refresh();
    }

    public function render()
    {
        $this->task->load('assignees', 'sourceable', 'section', 'createdBy');

        $comments = $this->task->comments()->with('author')->get();

        $searchResults = [];
        if (\strlen($this->searchAssignee) >= 2) {
            $existing = $this->task->assignees->pluck('id')->toArray();
            $term     = mb_strtolower($this->searchAssignee);
            $searchResults = Member::where('status', 'active')
                ->whereNotIn('id', $existing)
                ->get()
                ->filter(fn ($m) =>
                    str_contains(mb_strtolower($m->first_name . ' ' . $m->last_name), $term)
                    || str_contains(mb_strtolower($m->email ?? ''), $term)
                )
                ->take(8)
                ->values();
        }

        return view('livewire.admin.meeting.task-show', [
            'comments'      => $comments,
            'searchResults' => $searchResults,
            'sections'      => Section::where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}
