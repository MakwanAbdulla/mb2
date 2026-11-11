<?php

namespace App\Livewire\Admin\Meeting;

use App\Models\AuditLog;
use App\Models\Meeting\MeetingTask;
use App\Models\Organization\Section;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class TaskIndex extends Component
{
    public string $statusFilter  = 'open';
    public string $sectionFilter = '';
    public string $search        = '';

    public bool   $showNewForm  = false;
    public string $newTitle     = '';
    public string $newSectionId = '';
    public string $newDueDate   = '';

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasAnyPermission(['tasks.view', 'tasks.manage']), 403);
    }

    public function createTask(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('tasks.manage'), 403);

        $this->validate([
            'newTitle'     => ['required', 'string', 'max:255'],
            'newSectionId' => ['nullable', 'exists:sections,id'],
            'newDueDate'   => ['nullable', 'date'],
        ], [], ['newTitle' => 'titel']);

        $task = MeetingTask::create([
            'title'      => $this->newTitle,
            'section_id' => $this->newSectionId ?: null,
            'due_date'   => $this->newDueDate ?: null,
            'status'     => 'open',
            'created_by' => Auth::id(),
        ]);

        AuditLog::record('meeting_task_created', 'meetings', $task->title, $task);
        $this->showNewForm  = false;
        $this->newTitle     = '';
        $this->newSectionId = '';
        $this->newDueDate   = '';

        $this->redirect(route('admin.tasks.show', $task), navigate: true);
    }

    public function render()
    {
        $query = MeetingTask::with('assignees', 'sourceable', 'section')
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'open' THEN 1 WHEN 'done' THEN 2 ELSE 3 END")
            ->orderBy('due_date');

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->sectionFilter) {
            $query->where('section_id', $this->sectionFilter);
        }

        $tasks = $query->get();

        if ($this->search !== '') {
            $term  = mb_strtolower($this->search);
            $tasks = $tasks->filter(
                fn ($t) => str_contains(mb_strtolower($t->title), $term)
            );
        }

        return view('livewire.admin.meeting.task-index', [
            'tasks'    => $tasks,
            'sections' => Section::where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}
