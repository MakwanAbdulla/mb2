<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\AuditLog;
use App\Models\Meeting\MeetingTask;
use App\Models\Membership\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class TaskController extends Controller
{
    // GET /api/v1/tasks  — own tasks (via member assignee); tasks.view sees all
    public function index(Request $request): ResourceCollection
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['tasks.view', 'tasks.manage']), 403);

        $tasks = MeetingTask::query()
            ->when(
                ! $user->hasAnyPermission(['tasks.view', 'tasks.manage']),
                fn ($q) => $q->whereHas('assignees', fn ($q) =>
                    $q->whereIn('members.id', Member::where('user_id', $user->id)->pluck('id'))
                )
            )
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('section_id'), fn ($q, $v) => $q->where('section_id', $v))
            ->with(['assignees', 'section'])
            ->orderByRaw("FIELD(status,'open','in_progress','done','cancelled')")
            ->orderBy('due_date')
            ->paginate($request->integer('per_page', 50));

        return TaskResource::collection($tasks);
    }

    // GET /api/v1/tasks/{task}
    public function show(Request $request, MeetingTask $task): TaskResource
    {
        $user = $request->user();
        abort_unless(
            $user->hasAnyPermission(['tasks.view', 'tasks.manage'])
            || $task->assignees()->whereIn('members.id', Member::where('user_id', $user->id)->pluck('id'))->exists(),
            403
        );

        return new TaskResource($task->load(['assignees', 'section']));
    }

    // PATCH /api/v1/tasks/{task}
    public function update(Request $request, MeetingTask $task): TaskResource
    {
        $user = $request->user();
        $isAssigned = $task->assignees()
            ->whereIn('members.id', Member::where('user_id', $user->id)->pluck('id'))
            ->exists();

        abort_unless(
            $user->hasPermissionTo('tasks.manage') || $isAssigned,
            403
        );

        $data = $request->validate([
            'status'       => 'sometimes|in:open,in_progress,done,cancelled',
            'title'        => 'sometimes|string|max:255',
            'description'  => 'nullable|string|max:5000',
            'due_date'     => 'nullable|date',
            'section_id'   => 'nullable|exists:sections,id',
            'assignee_ids' => 'sometimes|array',
            'assignee_ids.*' => 'integer|exists:members,id',
        ]);

        if (isset($data['status']) && $data['status'] === 'done' && ! $task->completed_at) {
            $data['completed_at'] = now();
        }

        if (isset($data['assignee_ids'])) {
            $task->assignees()->sync($data['assignee_ids']);
            unset($data['assignee_ids']);
        }

        $task->update($data);

        AuditLog::record('meeting_task_updated', 'meetings', $task->title, $task);

        return new TaskResource($task->load(['assignees', 'section']));
    }

    // POST /api/v1/tasks
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('tasks.manage'), 403);

        $data = $request->validate([
            'title'          => 'required|string|max:255',
            'description'    => 'nullable|string|max:5000',
            'status'         => 'in:open,in_progress,done,cancelled',
            'due_date'       => 'nullable|date',
            'section_id'     => 'nullable|exists:sections,id',
            'assignee_ids'   => 'nullable|array',
            'assignee_ids.*' => 'integer|exists:members,id',
        ]);

        $task = MeetingTask::create([
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'status'      => $data['status'] ?? 'open',
            'due_date'    => $data['due_date'] ?? null,
            'section_id'  => $data['section_id'] ?? null,
            'created_by'  => $request->user()->id,
        ]);

        if (! empty($data['assignee_ids'])) {
            $task->assignees()->sync($data['assignee_ids']);
        }

        AuditLog::record('meeting_task_created', 'meetings', $task->title, $task);

        return (new TaskResource($task->load(['assignees', 'section'])))->response()->setStatusCode(201);
    }
}
