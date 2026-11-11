<?php

use App\Enums\QuestionType;
use App\Enums\SurveyStatus;
use App\Models\Form\Form;
use App\Models\Form\FormAnswer;
use App\Models\Form\FormQuestion;
use App\Models\Form\FormQuestionOption;
use App\Models\Form\FormSubmission;
use App\Models\Survey\Survey;
use App\Models\Survey\SurveyInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function responsesSurvey(): Survey
{
    $user = User::factory()->create();
    $form = Form::create(['title' => 'Form', 'created_by' => $user->id]);
    return Survey::create([
        'title'      => 'Svar-enkät',
        'form_id'    => $form->id,
        'status'     => SurveyStatus::Active,
        'created_by' => $user->id,
    ]);
}

function responsesAdmin(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('surveys.manage');
    return $user;
}

function addTextQuestion(Survey $survey, string $label = 'Textfråga'): FormQuestion
{
    return FormQuestion::create([
        'form_id'    => $survey->form_id,
        'type'       => QuestionType::Text,
        'label'      => $label,
        'sort_order' => 0,
    ]);
}

function addRadioQuestion(Survey $survey, array $optionLabels = ['Ja', 'Nej']): FormQuestion
{
    $q = FormQuestion::create([
        'form_id'    => $survey->form_id,
        'type'       => QuestionType::Radio,
        'label'      => 'Radiofråga',
        'sort_order' => 1,
    ]);
    foreach ($optionLabels as $i => $label) {
        FormQuestionOption::create(['question_id' => $q->id, 'text' => $label, 'sort_order' => $i]);
    }
    return $q;
}

function addScaleQuestion(Survey $survey, int $min = 1, int $max = 5): FormQuestion
{
    return FormQuestion::create([
        'form_id'    => $survey->form_id,
        'type'       => QuestionType::Scale,
        'label'      => 'Skalafråga',
        'sort_order' => 2,
        'scale_min'  => $min,
        'scale_max'  => $max,
        'scale_icon' => 'star',
    ]);
}

function submitSurveyResponse(Survey $survey, array $answers): FormSubmission
{
    $submission = FormSubmission::create([
        'form_id'          => $survey->form_id,
        'submittable_type' => Survey::class,
        'submittable_id'   => $survey->id,
    ]);
    foreach ($answers as $questionId => $value) {
        if (is_array($value)) {
            foreach ($value as $optionId) {
                FormAnswer::create(['submission_id' => $submission->id, 'question_id' => $questionId, 'option_id' => $optionId]);
            }
        } elseif (is_int($value)) {
            FormAnswer::create(['submission_id' => $submission->id, 'question_id' => $questionId, 'option_id' => $value]);
        } else {
            FormAnswer::create(['submission_id' => $submission->id, 'question_id' => $questionId, 'answer_text' => (string) $value]);
        }
    }
    return $submission;
}

// ── totalResponses computed ───────────────────────────────────────────────────

test('totalResponses returns zero when no submissions', function () {
    $this->createPermission('surveys.manage');
    $survey = responsesSurvey();

    $this->actingAs(responsesAdmin());

    Volt::test('admin.surveys.responses', ['survey' => $survey])
        ->assertSet('totalResponses', 0);
});

test('totalResponses counts submissions', function () {
    $this->createPermission('surveys.manage');
    $survey = responsesSurvey();
    submitSurveyResponse($survey, []);
    submitSurveyResponse($survey, []);

    $this->actingAs(responsesAdmin());

    $component = Volt::test('admin.surveys.responses', ['survey' => $survey]);
    expect($component->totalResponses)->toBe(2);
});

// ── questionsWithData — text ──────────────────────────────────────────────────

test('questionsWithData aggregates text answers', function () {
    $this->createPermission('surveys.manage');
    $survey   = responsesSurvey();
    $question = addTextQuestion($survey, 'Kommentar');
    submitSurveyResponse($survey, [$question->id => 'Bra jobbat']);
    submitSurveyResponse($survey, [$question->id => 'Kunde vara bättre']);

    $this->actingAs(responsesAdmin());

    $component = Volt::test('admin.surveys.responses', ['survey' => $survey]);
    $item      = collect($component->questionsWithData)->first();

    expect($item['question']->id)->toBe($question->id)
        ->and($item['data']['texts'])->toHaveCount(2);
});

// ── questionsWithData — radio/checkbox ───────────────────────────────────────

test('questionsWithData counts option selections and calculates percent', function () {
    $this->createPermission('surveys.manage');
    $survey   = responsesSurvey();
    $question = addRadioQuestion($survey, ['Ja', 'Nej']);
    $optJa    = $question->options->firstWhere('text', 'Ja');
    $optNej   = $question->options->firstWhere('text', 'Nej');

    submitSurveyResponse($survey, [$question->id => $optJa->id]);
    submitSurveyResponse($survey, [$question->id => $optJa->id]);
    submitSurveyResponse($survey, [$question->id => $optNej->id]);

    $this->actingAs(responsesAdmin());

    $component = Volt::test('admin.surveys.responses', ['survey' => $survey]);
    $item      = collect($component->questionsWithData)->first();
    $opts      = $item['data']['options'];
    $jaOpt     = $opts->firstWhere('text', 'Ja');
    $nejOpt    = $opts->firstWhere('text', 'Nej');

    expect($jaOpt['count'])->toBe(2)
        ->and($jaOpt['percent'])->toBe(67)
        ->and($nejOpt['count'])->toBe(1)
        ->and($nejOpt['percent'])->toBe(33)
        ->and($item['data']['total'])->toBe(3);
});

// ── questionsWithData — scale ─────────────────────────────────────────────────

test('questionsWithData calculates scale average and distribution', function () {
    $this->createPermission('surveys.manage');
    $survey   = responsesSurvey();
    $question = addScaleQuestion($survey, 1, 5);

    submitSurveyResponse($survey, [$question->id => '4']);
    submitSurveyResponse($survey, [$question->id => '4']);
    submitSurveyResponse($survey, [$question->id => '2']);

    $this->actingAs(responsesAdmin());

    $component = Volt::test('admin.surveys.responses', ['survey' => $survey]);
    $item      = collect($component->questionsWithData)->first();
    $data      = $item['data'];

    expect($data['total'])->toBe(3)
        ->and($data['average'])->toBe(3.3) // (4+4+2)/3 = 3.33 → rounded to 1 decimal
        ->and($data['distribution'])->toHaveCount(5); // values 1–5
});

test('questionsWithData scale distribution zero-fills unused values', function () {
    $this->createPermission('surveys.manage');
    $survey   = responsesSurvey();
    $question = addScaleQuestion($survey, 1, 3);

    submitSurveyResponse($survey, [$question->id => '2']);

    $this->actingAs(responsesAdmin());

    $component = Volt::test('admin.surveys.responses', ['survey' => $survey]);
    $item      = collect($component->questionsWithData)->first();
    $dist      = collect($item['data']['distribution']);

    $val1 = $dist->firstWhere('value', 1);
    $val2 = $dist->firstWhere('value', 2);

    expect($val1['count'])->toBe(0)
        ->and($val2['count'])->toBe(1)
        ->and($val2['percent'])->toBe(100);
});

// ── individualResponses ───────────────────────────────────────────────────────

test('individualResponses eager loads answers and user', function () {
    $this->createPermission('surveys.manage');
    $survey   = responsesSurvey();
    $question = addTextQuestion($survey);
    $user     = User::factory()->create();

    $submission = FormSubmission::create([
        'form_id'          => $survey->form_id,
        'submittable_type' => Survey::class,
        'submittable_id'   => $survey->id,
        'user_id'          => $user->id,
    ]);
    FormAnswer::create(['submission_id' => $submission->id, 'question_id' => $question->id, 'answer_text' => 'Svaret']);

    $this->actingAs(responsesAdmin());

    $component = Volt::test('admin.surveys.responses', ['survey' => $survey]);
    $responses = $component->individualResponses;

    expect($responses)->toHaveCount(1)
        ->and($responses->first()->answers)->toHaveCount(1)
        ->and($responses->first()->user->id)->toBe($user->id);
});

test('individualResponses eager loads invitation', function () {
    $this->createPermission('surveys.manage');
    $survey     = responsesSurvey();
    $invitation = SurveyInvitation::create([
        'survey_id' => $survey->id,
        'email'     => 'a@b.com',
        'token'     => 'tok-xyz',
    ]);

    FormSubmission::create([
        'form_id'          => $survey->form_id,
        'submittable_type' => Survey::class,
        'submittable_id'   => $survey->id,
        'invitation_id'    => $invitation->id,
    ]);

    $this->actingAs(responsesAdmin());

    $component = Volt::test('admin.surveys.responses', ['survey' => $survey]);
    $response  = $component->individualResponses->first();

    expect($response->invitation->id)->toBe($invitation->id);
});

// ── questionsWithData only counts own survey submissions ─────────────────────

test('questionsWithData does not count submissions from other surveys', function () {
    $this->createPermission('surveys.manage');
    $user    = User::factory()->create();
    $form1   = Form::create(['title' => 'F1', 'created_by' => $user->id]);
    $form2   = Form::create(['title' => 'F2', 'created_by' => $user->id]);
    $survey1 = Survey::create(['title' => 'S1', 'form_id' => $form1->id, 'status' => SurveyStatus::Active, 'created_by' => $user->id]);
    $survey2 = Survey::create(['title' => 'S2', 'form_id' => $form2->id, 'status' => SurveyStatus::Active, 'created_by' => $user->id]);

    $q1 = FormQuestion::create(['form_id' => $form1->id, 'type' => QuestionType::Text, 'label' => 'Q1', 'sort_order' => 0]);
    $q2 = FormQuestion::create(['form_id' => $form2->id, 'type' => QuestionType::Text, 'label' => 'Q2', 'sort_order' => 0]);

    submitSurveyResponse($survey1, [$q1->id => 'Svar 1']);
    submitSurveyResponse($survey2, [$q2->id => 'Svar 2']);

    $this->actingAs(responsesAdmin());

    $component = Volt::test('admin.surveys.responses', ['survey' => $survey1]);
    expect($component->totalResponses)->toBe(1);
});
