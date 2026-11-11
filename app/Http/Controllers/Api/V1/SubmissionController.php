<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubmissionResource;
use App\Models\AuditLog;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Auth;

class SubmissionController extends Controller
{
    public function index(Request $request): ResourceCollection
    {
        $user = Auth::user();

        $submissions = Submission::query()
            ->when(
                ! $user->hasAnyPermission(['submissions.view', 'submissions.manage']),
                fn ($q) => $q->where('created_by', $user->id)
            )
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->with('createdBy', 'section')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return SubmissionResource::collection($submissions);
    }

    public function show(Submission $submission): SubmissionResource
    {
        $user = Auth::user();

        abort_unless(
            $submission->created_by === $user->id
            || $user->hasAnyPermission(['submissions.view', 'submissions.manage']),
            403
        );

        $submission->load('createdBy', 'section', 'agendaItem');

        return new SubmissionResource($submission);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'       => 'required|in:issue,motion',
            'title'      => 'required|string|max:255',
            'body'       => 'nullable|string|max:10000',
            'section_id' => 'nullable|exists:sections,id',
        ]);

        $submission = Submission::create([
            ...$data,
            'status'     => 'submitted',
            'created_by' => Auth::id(),
        ]);

        AuditLog::record('submission_created', 'meetings', $submission->title, $submission);

        return (new SubmissionResource($submission))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Submission $submission): SubmissionResource
    {
        abort_unless($request->user()->hasPermissionTo('submissions.manage'), 403);

        $data = $request->validate([
            'status'         => 'sometimes|in:' . implode(',', array_keys(Submission::STATUSES)),
            'board_response' => 'nullable|string|max:10000',
            'agenda_item_id' => 'nullable|exists:meeting_agenda_items,id',
            'section_id'     => 'nullable|exists:sections,id',
        ]);

        $previousStatus = $submission->status;

        if (isset($data['status']) && $data['status'] === 'resolved' && ! $submission->resolved_at) {
            $data['resolved_at'] = now();
        }

        $submission->update($data);

        $action = isset($data['status']) && $data['status'] !== $previousStatus
            ? 'submission_' . $data['status']
            : 'submission_updated';

        AuditLog::record($action, 'meetings', $submission->title, $submission);

        return new SubmissionResource($submission->fresh(['createdBy', 'section', 'agendaItem']));
    }
}
