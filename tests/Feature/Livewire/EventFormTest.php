<?php

use App\Enums\QuestionType;
use App\Enums\SurveyStatus;
use App\Models\Events\Event;
use App\Models\Events\EventPriceTier;
use App\Models\Events\EventRegistration;
use App\Models\Form\Form;
use App\Models\Form\FormAnswer;
use App\Models\Form\FormQuestion;
use App\Models\Form\FormQuestionOption;
use App\Models\Form\FormSubmission;
use App\Models\Survey\Survey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function eventWithRegistrationSurvey(): array
{
    $creator = User::factory()->create();
    $event   = Event::factory()->published()->admissionAutomatic()->create();
    $tier = EventPriceTier::factory()->create([
        'event_id'  => $event->id,
        'price_ore' => 0,
        'label'     => 'Gratis',
    ]);

    $form   = Form::create(['title' => 'Reg-form', 'created_by' => $creator->id]);
    $survey = Survey::create([
        'title'          => 'Anmälningsenkät',
        'form_id'        => $form->id,
        'status'         => SurveyStatus::Active,
        'created_by'     => $creator->id,
        'surveyable_type' => Event::class,
        'surveyable_id'  => $event->id,
        'role'           => Survey::ROLE_REGISTRATION,
    ]);

    return compact('event', 'tier', 'survey', 'form');
}

// ── FormSubmission created on registration ────────────────────────────────────

test('register creates FormSubmission linked to EventRegistration when survey answers provided', function () {
    ['event' => $event, 'tier' => $tier, 'survey' => $survey, 'form' => $form] = eventWithRegistrationSurvey();
    $question = FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Text, 'label' => 'Matpreferenser', 'sort_order' => 0]);
    $user     = User::factory()->create();

    $this->actingAs($user);

    Volt::test('events.show', ['event' => $event])
        ->set('selectedTierId', $tier->id)
        ->set("surveyAnswers.{$question->id}", 'Vegetarisk')
        ->call('register');

    $registration = EventRegistration::where('event_id', $event->id)->first();
    expect($registration)->not->toBeNull()
        ->and($registration->form_submission_id)->not->toBeNull();

    $submission = FormSubmission::find($registration->form_submission_id);
    expect($submission)->not->toBeNull()
        ->and($submission->form_id)->toBe($form->id)
        ->and($submission->answers)->toHaveCount(1)
        ->and($submission->answers->first()->answer_text)->toBe('Vegetarisk');
});

test('register creates FormAnswer for radio question', function () {
    ['event' => $event, 'tier' => $tier, 'survey' => $survey, 'form' => $form] = eventWithRegistrationSurvey();
    $question = FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Radio, 'label' => 'T-shirt', 'sort_order' => 0]);
    $opt      = FormQuestionOption::create(['question_id' => $question->id, 'text' => 'S', 'sort_order' => 0]);
    $user     = User::factory()->create();

    $this->actingAs($user);

    Volt::test('events.show', ['event' => $event])
        ->set('selectedTierId', $tier->id)
        ->set("surveyAnswers.{$question->id}", $opt->id)
        ->call('register');

    $submission = FormSubmission::first();
    expect($submission)->not->toBeNull();

    $answer = FormAnswer::where('submission_id', $submission->id)->first();
    expect($answer->option_id)->toBe($opt->id);
});

test('register creates multiple FormAnswers for checkbox question', function () {
    ['event' => $event, 'tier' => $tier, 'survey' => $survey, 'form' => $form] = eventWithRegistrationSurvey();
    $question = FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Checkbox, 'label' => 'Intressen', 'sort_order' => 0]);
    $opt1     = FormQuestionOption::create(['question_id' => $question->id, 'text' => 'Brädspel', 'sort_order' => 0]);
    $opt2     = FormQuestionOption::create(['question_id' => $question->id, 'text' => 'RPG',     'sort_order' => 1]);
    $user     = User::factory()->create();

    $this->actingAs($user);

    Volt::test('events.show', ['event' => $event])
        ->set('selectedTierId', $tier->id)
        ->set("surveyAnswers.{$question->id}", [$opt1->id, $opt2->id])
        ->call('register');

    $submission = FormSubmission::first();
    expect(FormAnswer::where('submission_id', $submission->id)->count())->toBe(2);
});

// ── No FormSubmission when no answers ─────────────────────────────────────────

test('register does not create FormSubmission when no survey answers given', function () {
    ['event' => $event, 'tier' => $tier] = eventWithRegistrationSurvey();
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('events.show', ['event' => $event])
        ->set('selectedTierId', $tier->id)
        ->call('register');

    expect(FormSubmission::count())->toBe(0);

    $registration = EventRegistration::where('event_id', $event->id)->first();
    expect($registration->form_submission_id)->toBeNull();
});

// ── Required survey question validation ───────────────────────────────────────

test('register fails when required survey question is empty', function () {
    ['event' => $event, 'tier' => $tier, 'survey' => $survey, 'form' => $form] = eventWithRegistrationSurvey();
    FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Text, 'label' => 'Obligatorisk', 'sort_order' => 0, 'is_required' => true]);
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('events.show', ['event' => $event])
        ->set('selectedTierId', $tier->id)
        ->call('register')
        ->assertHasErrors();

    expect(EventRegistration::count())->toBe(0);
});

// ── EventRegistration.form_submission_id column ───────────────────────────────

test('form_submission_id column is nullable — registration without survey has null', function () {
    $event = Event::factory()->published()->admissionAutomatic()->create();
    $tier  = EventPriceTier::factory()->create(['event_id' => $event->id, 'price_ore' => 0, 'label' => 'Gratis']);
    $user    = User::factory()->create();

    $this->actingAs($user);

    Volt::test('events.show', ['event' => $event])
        ->set('selectedTierId', $tier->id)
        ->call('register');

    $registration = EventRegistration::where('event_id', $event->id)->first();
    expect($registration)->not->toBeNull()
        ->and($registration->form_submission_id)->toBeNull();
});
