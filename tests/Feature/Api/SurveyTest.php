<?php

use App\Enums\QuestionType;
use App\Enums\SurveyStatus;
use App\Models\Form\Form;
use App\Models\Form\FormQuestion;
use App\Models\Form\FormQuestionOption;
use App\Models\Form\FormSubmission;
use App\Models\Survey\Survey;
use App\Models\Survey\SurveyInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function apiSurvey(array $attrs = []): Survey
{
    $user = User::factory()->create();
    $form = Form::create(['title' => 'API-enkät', 'created_by' => $user->id]);
    return Survey::create(array_merge([
        'title'      => 'API-enkät',
        'form_id'    => $form->id,
        'status'     => SurveyStatus::Active,
        'created_by' => $user->id,
    ], $attrs));
}

function apiInvitation(Survey $survey): SurveyInvitation
{
    return SurveyInvitation::create([
        'survey_id' => $survey->id,
        'email'     => 'api@example.com',
        'token'     => \Str::uuid(),
    ]);
}

function apiQuestion(Survey $survey, QuestionType $type = QuestionType::Text, bool $required = false): FormQuestion
{
    return FormQuestion::create([
        'form_id'     => $survey->form_id,
        'type'        => $type,
        'label'       => 'Fråga',
        'is_required' => $required,
        'sort_order'  => 0,
    ]);
}

function apiOption(FormQuestion $question, string $text): FormQuestionOption
{
    static $n = 0;
    return FormQuestionOption::create([
        'question_id' => $question->id,
        'text'        => $text,
        'sort_order'  => ++$n,
    ]);
}

// ── GET /api/v1/surveys/{token} ───────────────────────────────────────────────

test('GET survey returns survey data for valid invitation token', function () {
    $survey = apiSurvey();
    $inv    = apiInvitation($survey);

    $this->getJson("/api/v1/surveys/{$inv->token}")
        ->assertOk()
        ->assertJsonPath('data.id', $survey->id)
        ->assertJsonPath('data.title', 'API-enkät');
});

test('GET survey returns questions with options', function () {
    $survey   = apiSurvey();
    $question = apiQuestion($survey, QuestionType::Radio);
    apiOption($question, 'Ja');
    apiOption($question, 'Nej');
    $inv = apiInvitation($survey);

    $response = $this->getJson("/api/v1/surveys/{$inv->token}");

    $response->assertOk()
        ->assertJsonCount(1, 'data.questions')
        ->assertJsonCount(2, 'data.questions.0.options');
});

test('GET survey via open-{id} token when is_open is true', function () {
    $survey = apiSurvey(['is_open' => true]);

    $this->getJson("/api/v1/surveys/open-{$survey->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $survey->id);
});

test('GET survey returns 404 for unknown token', function () {
    $this->getJson('/api/v1/surveys/no-such-token')->assertNotFound();
});

test('GET survey returns 404 when survey is not active', function () {
    $survey = apiSurvey(['status' => SurveyStatus::Closed]);
    $inv    = apiInvitation($survey);

    $this->getJson("/api/v1/surveys/{$inv->token}")->assertNotFound();
});

// ── POST /api/v1/surveys/{token}/responses ────────────────────────────────────

test('POST response creates FormSubmission', function () {
    $survey   = apiSurvey();
    $question = apiQuestion($survey, QuestionType::Text);
    $inv      = apiInvitation($survey);

    $this->postJson("/api/v1/surveys/{$inv->token}/responses", [
        'answers' => [$question->id => 'Mitt API-svar'],
    ])->assertCreated();

    expect(FormSubmission::count())->toBe(1);
});

test('POST response marks invitation as responded', function () {
    $survey   = apiSurvey();
    $question = apiQuestion($survey, QuestionType::Text);
    $inv      = apiInvitation($survey);

    $this->postJson("/api/v1/surveys/{$inv->token}/responses", [
        'answers' => [$question->id => 'Svar'],
    ])->assertCreated();

    expect($inv->fresh()->responded_at)->not->toBeNull();
});

test('POST response returns 422 when required question is empty', function () {
    $survey   = apiSurvey();
    apiQuestion($survey, QuestionType::Text, required: true);
    $inv = apiInvitation($survey);

    $this->postJson("/api/v1/surveys/{$inv->token}/responses", ['answers' => []])
        ->assertUnprocessable();

    expect(FormSubmission::count())->toBe(0);
});

test('POST response returns 422 when already responded', function () {
    $survey   = apiSurvey();
    $question = apiQuestion($survey, QuestionType::Text);
    $inv      = apiInvitation($survey);
    $inv->update(['responded_at' => now()]);

    $this->postJson("/api/v1/surveys/{$inv->token}/responses", [
        'answers' => [$question->id => 'Försök igen'],
    ])->assertUnprocessable();
});

test('POST response via open survey works without invitation', function () {
    $survey   = apiSurvey(['is_open' => true]);
    $question = apiQuestion($survey, QuestionType::Text);

    $this->postJson("/api/v1/surveys/open-{$survey->id}/responses", [
        'answers' => [$question->id => 'Öppet svar'],
    ])->assertCreated();
});

test('POST response notifies creator when notify_on_response is true', function () {
    \Illuminate\Support\Facades\Notification::fake();

    $creator = User::factory()->create();
    $form    = Form::create(['title' => 'Notif-enkät', 'created_by' => $creator->id]);
    $survey  = Survey::create([
        'title'              => 'Notif-enkät',
        'form_id'            => $form->id,
        'status'             => SurveyStatus::Active,
        'created_by'         => $creator->id,
        'notify_on_response' => true,
    ]);
    $question = apiQuestion($survey, QuestionType::Text);
    $inv      = apiInvitation($survey);

    $this->postJson("/api/v1/surveys/{$inv->token}/responses", [
        'answers' => [$question->id => 'Svar'],
    ])->assertCreated();

    \Illuminate\Support\Facades\Notification::assertSentTo(
        $creator,
        \App\Notifications\SurveyNewResponseNotification::class
    );
});

test('POST response does not notify creator when notify_on_response is false', function () {
    \Illuminate\Support\Facades\Notification::fake();

    $survey   = apiSurvey(['notify_on_response' => false]);
    $question = apiQuestion($survey, QuestionType::Text);
    $inv      = apiInvitation($survey);

    $this->postJson("/api/v1/surveys/{$inv->token}/responses", [
        'answers' => [$question->id => 'Svar'],
    ])->assertCreated();

    \Illuminate\Support\Facades\Notification::assertNothingSent();
});
