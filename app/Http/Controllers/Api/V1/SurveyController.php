<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SurveyStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SurveyResource;
use App\Models\AuditLog;
use App\Models\Form\FormAnswer;
use App\Models\Form\FormSubmission;
use App\Models\Survey\Survey;
use App\Models\Survey\SurveyInvitation;
use App\Notifications\SurveyNewResponseNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SurveyController extends Controller
{
    /**
     * GET /api/v1/surveys/{token}
     *
     * Resolve via invitation token (UUID) or open-{id} shorthand.
     */
    public function show(string $token): SurveyResource|JsonResponse
    {
        $survey = $this->resolveSurvey($token, $invitation);

        if (! $survey) {
            return response()->json(['message' => 'Survey not found.'], 404);
        }

        $survey->load('questions.options');

        return new SurveyResource($survey);
    }

    /**
     * POST /api/v1/surveys/{token}/responses
     */
    public function store(Request $request, string $token): JsonResponse
    {
        $survey = $this->resolveSurvey($token, $invitation);

        if (! $survey) {
            return response()->json(['message' => 'Survey not found.'], 404);
        }

        if (! $survey->form_id) {
            return response()->json(['message' => 'Survey has no form configured.'], 422);
        }

        if ($invitation && $invitation->hasResponded()) {
            return response()->json(['message' => 'Already responded.'], 422);
        }

        $survey->loadMissing('questions.options');

        // Build validation rules from questions
        $rules = [];
        foreach ($survey->questions as $question) {
            if ($question->is_required) {
                $rules["answers.{$question->id}"] = $question->type->hasOptions()
                    ? 'required|array|min:1'
                    : 'required|string|min:1';
            } else {
                $rules["answers.{$question->id}"] = 'nullable';
            }
        }

        $validated = $request->validate(['answers' => 'array'] + $rules);
        $answers   = $validated['answers'] ?? [];

        $submission = FormSubmission::create([
            'form_id'          => $survey->form_id,
            'submittable_type' => Survey::class,
            'submittable_id'   => $survey->id,
            'invitation_id'    => $invitation?->id,
            'user_id'          => auth()->id(),
            'ip_address'       => $request->ip(),
        ]);

        foreach ($survey->questions as $question) {
            $value = $answers[$question->id] ?? null;

            if ($question->type->hasOptions() && is_array($value)) {
                foreach ($value as $optionId) {
                    FormAnswer::create([
                        'submission_id' => $submission->id,
                        'question_id'   => $question->id,
                        'option_id'     => $optionId,
                        'answer_text'   => null,
                    ]);
                }
            } elseif (! empty($value)) {
                FormAnswer::create([
                    'submission_id' => $submission->id,
                    'question_id'   => $question->id,
                    'option_id'     => null,
                    'answer_text'   => (string) $value,
                ]);
            }
        }

        if ($invitation) {
            $invitation->update(['responded_at' => now()]);
        }

        if ($survey->notify_on_response && $survey->creator) {
            $survey->creator->notify(new SurveyNewResponseNotification($survey, $submission));
        }

        AuditLog::record('survey_response_submitted', 'members', $survey->title, $submission);

        return response()->json(['message' => 'Response recorded.'], 201);
    }

    /**
     * Resolve a survey from a token string.
     * Sets $invitation by reference when resolved via invitation token.
     */
    private function resolveSurvey(string $token, ?SurveyInvitation &$invitation = null): ?Survey
    {
        $invitation = null;

        if (str_starts_with($token, 'open-')) {
            $surveyId = (int) substr($token, 5);
            $survey   = Survey::find($surveyId);

            if (! $survey || ! $survey->is_open || $survey->status !== SurveyStatus::Active) {
                return null;
            }

            return $survey;
        }

        $invitation = SurveyInvitation::with('survey')
            ->where('token', $token)
            ->first();

        if (! $invitation) {
            return null;
        }

        $survey = $invitation->survey;

        if (! $survey || $survey->status !== SurveyStatus::Active) {
            return null;
        }

        return $survey;
    }
}
