<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SurveyStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SurveyResource;
use App\Models\AuditLog;
use App\Models\Form\Form;
use App\Models\Survey\Survey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class SurveyAdminController extends Controller
{
    public function index(Request $request): ResourceCollection
    {
        abort_unless($request->user()->hasAnyPermission(['surveys.view', 'surveys.manage']), 403);

        $surveys = Survey::query()
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->withCount('submissions')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return SurveyResource::collection($surveys);
    }

    public function show(Request $request, Survey $survey): SurveyResource
    {
        abort_unless($request->user()->hasAnyPermission(['surveys.view', 'surveys.manage']), 403);

        $survey->load('questions.options');

        return new SurveyResource($survey);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('surveys.manage'), 403);

        $data = $request->validate([
            'title'               => 'required|string|max:255',
            'description'         => 'nullable|string',
            'is_anonymous'        => 'boolean',
            'is_open'             => 'boolean',
            'notify_on_response'  => 'boolean',
            'opens_at'            => 'nullable|date',
            'closes_at'           => 'nullable|date',
            'send_invitations_at' => 'nullable|date',
        ]);

        $form = Form::create([
            'title'      => $data['title'],
            'created_by' => $request->user()->id,
        ]);

        $survey = Survey::create([
            ...$data,
            'form_id'    => $form->id,
            'status'     => SurveyStatus::Draft,
            'created_by' => $request->user()->id,
        ]);

        AuditLog::record('survey_created', 'members', $survey->title, $survey);

        return (new SurveyResource($survey))->response()->setStatusCode(201);
    }

    public function update(Request $request, Survey $survey): SurveyResource
    {
        abort_unless($request->user()->hasPermissionTo('surveys.manage'), 403);

        $data = $request->validate([
            'title'               => 'sometimes|string|max:255',
            'description'         => 'nullable|string',
            'status'              => 'sometimes|string',
            'is_anonymous'        => 'boolean',
            'is_open'             => 'boolean',
            'notify_on_response'  => 'boolean',
            'opens_at'            => 'nullable|date',
            'closes_at'           => 'nullable|date',
            'send_invitations_at' => 'nullable|date',
        ]);

        $survey->update($data);

        AuditLog::record('survey_updated', 'members', $survey->title, $survey);

        return new SurveyResource($survey);
    }

    public function destroy(Request $request, Survey $survey): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('surveys.manage'), 403);
        abort_unless($survey->status === SurveyStatus::Draft, 422, 'Only draft surveys can be deleted.');

        $title = $survey->title;
        $survey->delete();

        AuditLog::record('survey_deleted', 'members', $title, null);

        return response()->json(['message' => 'Survey deleted.']);
    }

    public function responses(Request $request, Survey $survey): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['surveys.view', 'surveys.manage']), 403);

        $submissions = $survey->submissions()
            ->with('answers.question', 'answers.option', 'user')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($submissions);
    }
}
