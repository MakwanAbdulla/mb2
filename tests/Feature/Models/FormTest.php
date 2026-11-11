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

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeForm(array $attrs = []): Form
{
    $user = User::factory()->create();
    return Form::create(array_merge(['title' => 'Testformulär', 'created_by' => $user->id], $attrs));
}

function makeFormSurvey(array $attrs = []): Survey
{
    $user = User::factory()->create();
    $form = makeForm();
    return Survey::create(array_merge([
        'title'      => 'Testenkät',
        'form_id'    => $form->id,
        'status'     => SurveyStatus::Active,
        'created_by' => $user->id,
    ], $attrs));
}

// ── QuestionType enum ─────────────────────────────────────────────────────────

test('QuestionType hasOptions is true only for radio and checkbox', function () {
    expect(QuestionType::Radio->hasOptions())->toBeTrue()
        ->and(QuestionType::Checkbox->hasOptions())->toBeTrue()
        ->and(QuestionType::Text->hasOptions())->toBeFalse()
        ->and(QuestionType::Textarea->hasOptions())->toBeFalse()
        ->and(QuestionType::Scale->hasOptions())->toBeFalse()
        ->and(QuestionType::Datetime->hasOptions())->toBeFalse();
});

test('QuestionType isScale and isDatetime return correctly', function () {
    expect(QuestionType::Scale->isScale())->toBeTrue()
        ->and(QuestionType::Text->isScale())->toBeFalse()
        ->and(QuestionType::Datetime->isDatetime())->toBeTrue()
        ->and(QuestionType::Text->isDatetime())->toBeFalse();
});

// ── Form model ────────────────────────────────────────────────────────────────

test('form can be created', function () {
    $form = makeForm(['title' => 'Min form']);

    expect($form)->toBeInstanceOf(Form::class)
        ->and($form->title)->toBe('Min form');
});

test('form questions relation', function () {
    $form = makeForm();
    FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Text, 'label' => 'Namn', 'sort_order' => 0]);
    FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Textarea, 'label' => 'Kommentar', 'sort_order' => 1]);

    expect($form->questions)->toHaveCount(2);
});

test('form questions are ordered by sort_order', function () {
    $form = makeForm();
    FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Text, 'label' => 'B', 'sort_order' => 2]);
    FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Text, 'label' => 'A', 'sort_order' => 0]);
    FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Text, 'label' => 'C', 'sort_order' => 4]);

    $labels = $form->questions->pluck('label')->all();

    expect($labels)->toBe(['A', 'B', 'C']);
});

// ── FormQuestion model ────────────────────────────────────────────────────────

test('form question type is cast to QuestionType enum', function () {
    $form = makeForm();
    $q    = FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Radio, 'label' => 'Val', 'sort_order' => 0]);

    expect($q->fresh()->type)->toBe(QuestionType::Radio);
});

test('form question options relation', function () {
    $form = makeForm();
    $q    = FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Radio, 'label' => 'Val', 'sort_order' => 0]);

    FormQuestionOption::create(['question_id' => $q->id, 'text' => 'Ja',  'sort_order' => 0]);
    FormQuestionOption::create(['question_id' => $q->id, 'text' => 'Nej', 'sort_order' => 1]);

    expect($q->options)->toHaveCount(2);
});

test('form question options are ordered by sort_order', function () {
    $form = makeForm();
    $q    = FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Radio, 'label' => 'Val', 'sort_order' => 0]);

    FormQuestionOption::create(['question_id' => $q->id, 'text' => 'B', 'sort_order' => 1]);
    FormQuestionOption::create(['question_id' => $q->id, 'text' => 'A', 'sort_order' => 0]);

    expect($q->options->pluck('text')->first())->toBe('A');
});

// ── FormSubmission model ──────────────────────────────────────────────────────

test('form submission can be created', function () {
    $survey = makeFormSurvey();

    $submission = FormSubmission::create([
        'form_id'          => $survey->form_id,
        'submittable_type' => Survey::class,
        'submittable_id'   => $survey->id,
        'ip_address'       => '127.0.0.1',
    ]);

    expect($submission)->toBeInstanceOf(FormSubmission::class)
        ->and($submission->form_id)->toBe($survey->form_id);
});

test('form submission submittable polymorphic relation', function () {
    $survey     = makeFormSurvey();
    $submission = FormSubmission::create([
        'form_id'          => $survey->form_id,
        'submittable_type' => Survey::class,
        'submittable_id'   => $survey->id,
    ]);

    expect($submission->submittable)->toBeInstanceOf(Survey::class)
        ->and($submission->submittable->id)->toBe($survey->id);
});

test('form submission answers relation', function () {
    $survey     = makeFormSurvey();
    $form       = $survey->form;
    $question   = FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Text, 'label' => 'Q', 'sort_order' => 0]);
    $submission = FormSubmission::create(['form_id' => $form->id, 'submittable_type' => Survey::class, 'submittable_id' => $survey->id]);

    FormAnswer::create(['submission_id' => $submission->id, 'question_id' => $question->id, 'answer_text' => 'Svar']);

    expect($submission->answers)->toHaveCount(1)
        ->and($submission->answers->first()->answer_text)->toBe('Svar');
});

// ── FormAnswer model ──────────────────────────────────────────────────────────

test('form answer can store text answer', function () {
    $survey     = makeFormSurvey();
    $form       = $survey->form;
    $question   = FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Textarea, 'label' => 'Q', 'sort_order' => 0]);
    $submission = FormSubmission::create(['form_id' => $form->id, 'submittable_type' => Survey::class, 'submittable_id' => $survey->id]);

    $answer = FormAnswer::create(['submission_id' => $submission->id, 'question_id' => $question->id, 'answer_text' => 'Mitt svar']);

    expect($answer->answer_text)->toBe('Mitt svar')
        ->and($answer->option_id)->toBeNull();
});

test('form answer can store option answer', function () {
    $survey     = makeFormSurvey();
    $form       = $survey->form;
    $question   = FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Radio, 'label' => 'Q', 'sort_order' => 0]);
    $option     = FormQuestionOption::create(['question_id' => $question->id, 'text' => 'Ja', 'sort_order' => 0]);
    $submission = FormSubmission::create(['form_id' => $form->id, 'submittable_type' => Survey::class, 'submittable_id' => $survey->id]);

    $answer = FormAnswer::create(['submission_id' => $submission->id, 'question_id' => $question->id, 'option_id' => $option->id]);

    expect($answer->option_id)->toBe($option->id)
        ->and($answer->answer_text)->toBeNull();
});

// ── Survey model ──────────────────────────────────────────────────────────────

test('survey questions via HasManyThrough returns form questions', function () {
    $survey   = makeFormSurvey();
    $form     = $survey->form;

    FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Text, 'label' => 'Q1', 'sort_order' => 0]);
    FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Textarea, 'label' => 'Q2', 'sort_order' => 1]);

    expect($survey->questions)->toHaveCount(2);
});

test('survey questions are ordered by sort_order via HasManyThrough', function () {
    $survey = makeFormSurvey();
    $form   = $survey->form;

    FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Text, 'label' => 'Sista', 'sort_order' => 10]);
    FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Text, 'label' => 'Första', 'sort_order' => 0]);

    expect($survey->questions->first()->label)->toBe('Första');
});

test('survey submissions relation returns only this survey submissions', function () {
    $s1 = makeFormSurvey();
    $s2 = makeFormSurvey();

    FormSubmission::create(['form_id' => $s1->form_id, 'submittable_type' => Survey::class, 'submittable_id' => $s1->id]);
    FormSubmission::create(['form_id' => $s2->form_id, 'submittable_type' => Survey::class, 'submittable_id' => $s2->id]);

    expect($s1->submissions)->toHaveCount(1)
        ->and($s2->submissions)->toHaveCount(1);
});

test('survey isActive returns true only for active status', function () {
    $active = makeFormSurvey(['status' => SurveyStatus::Active]);
    $draft  = makeFormSurvey(['status' => SurveyStatus::Draft]);
    $closed = makeFormSurvey(['status' => SurveyStatus::Closed]);

    expect($active->isActive())->toBeTrue()
        ->and($draft->isActive())->toBeFalse()
        ->and($closed->isActive())->toBeFalse();
});

test('survey isStandalone returns true when no surveyable', function () {
    $standalone = makeFormSurvey(['surveyable_type' => null, 'surveyable_id' => null]);
    $linked     = makeFormSurvey(['surveyable_type' => Survey::class, 'surveyable_id' => 999]);

    expect($standalone->isStandalone())->toBeTrue()
        ->and($linked->isStandalone())->toBeFalse();
});

test('survey role helpers return correctly', function () {
    $none  = makeFormSurvey(['role' => null]);
    $reg   = makeFormSurvey(['role' => Survey::ROLE_REGISTRATION]);
    $follo = makeFormSurvey(['role' => Survey::ROLE_FOLLOWUP]);

    expect($reg->isRegistration())->toBeTrue()
        ->and($follo->isFollowup())->toBeTrue()
        ->and($none->isRegistration())->toBeFalse()
        ->and($none->isFollowup())->toBeFalse();
});

// ── SurveyInvitation model ────────────────────────────────────────────────────

test('survey invitation hasResponded returns false before submission', function () {
    $survey     = makeFormSurvey();
    $invitation = SurveyInvitation::create([
        'survey_id'    => $survey->id,
        'email'        => 'test@example.com',
        'token'        => 'abc123',
    ]);

    expect($invitation->hasResponded())->toBeFalse();
});

test('survey invitation hasResponded returns true after submission linked', function () {
    $survey     = makeFormSurvey();
    $invitation = SurveyInvitation::create([
        'survey_id'    => $survey->id,
        'email'        => 'test@example.com',
        'token'        => 'abc123',
        'responded_at' => now(),
    ]);

    expect($invitation->hasResponded())->toBeTrue();
});
