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
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function livewireSurvey(array $attrs = []): Survey
{
    $user = User::factory()->create();
    $form = Form::create(['title' => 'Testformulär', 'created_by' => $user->id]);
    return Survey::create(array_merge([
        'title'      => 'Testenkät',
        'form_id'    => $form->id,
        'status'     => SurveyStatus::Active,
        'is_open'    => false,
        'created_by' => $user->id,
    ], $attrs));
}

function livewireInvitation(Survey $survey, string $token = 'test-token'): SurveyInvitation
{
    return SurveyInvitation::create([
        'survey_id' => $survey->id,
        'email'     => 'person@example.com',
        'token'     => $token,
    ]);
}

// ── Mount via invitation token ────────────────────────────────────────────────

test('respond mounts successfully with valid invitation token', function () {
    $survey     = livewireSurvey();
    $invitation = livewireInvitation($survey);

    Volt::test('pages.surveys.respond', ['token' => $invitation->token])
        ->assertSet('alreadyResponded', false)
        ->assertSet('done', false);
});

test('respond shows already responded when invitation has responded_at', function () {
    $survey     = livewireSurvey();
    $invitation = livewireInvitation($survey);
    $invitation->update(['responded_at' => now()]);

    Volt::test('pages.surveys.respond', ['token' => $invitation->token])
        ->assertSet('alreadyResponded', true);
});

test('respond returns 404 for inactive survey via invitation', function () {
    $survey     = livewireSurvey(['status' => SurveyStatus::Draft]);
    $invitation = livewireInvitation($survey);

    $this->get(route('surveys.respond', ['token' => $invitation->token]))
        ->assertNotFound();
});

// ── Mount via open token ──────────────────────────────────────────────────────

test('respond mounts via open-{id} token for is_open survey', function () {
    $survey = livewireSurvey(['is_open' => true]);

    Volt::test('pages.surveys.respond', ['token' => "open-{$survey->id}"])
        ->assertSet('alreadyResponded', false);
});

test('respond returns 404 for open token when survey is not is_open', function () {
    $survey = livewireSurvey(['is_open' => false]);

    $this->get(route('surveys.respond', ['token' => "open-{$survey->id}"]))
        ->assertNotFound();
});

// ── Submit ────────────────────────────────────────────────────────────────────

test('submit creates FormSubmission and FormAnswers', function () {
    $survey     = livewireSurvey(['is_open' => true]);
    $form       = $survey->form;
    $q          = FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Text, 'label' => 'Namnfråga', 'sort_order' => 0]);

    Volt::test('pages.surveys.respond', ['token' => "open-{$survey->id}"])
        ->set("answers.{$q->id}", 'Test svar')
        ->call('submit')
        ->assertSet('done', true);

    expect(FormSubmission::count())->toBe(1)
        ->and(FormSubmission::first()->submittable_id)->toBe($survey->id);
});

test('submit validation fails when required question is empty', function () {
    $survey = livewireSurvey(['is_open' => true]);
    $form   = $survey->form;
    FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Text, 'label' => 'Obligatorisk', 'sort_order' => 0, 'is_required' => true]);

    Volt::test('pages.surveys.respond', ['token' => "open-{$survey->id}"])
        ->call('submit')
        ->assertHasErrors();

    expect(FormSubmission::count())->toBe(0);
});

test('submit handles checkbox answers by creating multiple FormAnswers', function () {
    $survey  = livewireSurvey(['is_open' => true]);
    $form    = $survey->form;
    $q       = FormQuestion::create(['form_id' => $form->id, 'type' => QuestionType::Checkbox, 'label' => 'Välj', 'sort_order' => 0]);
    $opt1    = FormQuestionOption::create(['question_id' => $q->id, 'text' => 'A', 'sort_order' => 0]);
    $opt2    = FormQuestionOption::create(['question_id' => $q->id, 'text' => 'B', 'sort_order' => 1]);

    Volt::test('pages.surveys.respond', ['token' => "open-{$survey->id}"])
        ->set("answers.{$q->id}", [$opt1->id, $opt2->id])
        ->call('submit')
        ->assertSet('done', true);

    $submission = FormSubmission::first();
    expect($submission->answers)->toHaveCount(2);
});

test('submit links invitation to submission', function () {
    $survey     = livewireSurvey();
    $invitation = livewireInvitation($survey);

    Volt::test('pages.surveys.respond', ['token' => $invitation->token])
        ->call('submit');

    $submission = FormSubmission::first();
    expect($submission->invitation_id)->toBe($invitation->id);
});

// ── Admin create component ────────────────────────────────────────────────────

test('admin create survey requires surveys.manage permission', function () {
    $this->createPermission('surveys.manage');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.surveys.create'))
        ->assertForbidden();
});

test('admin create survey is accessible with surveys.manage permission', function () {
    $this->createPermission('surveys.manage');
    $user = User::factory()->create();
    $user->givePermissionTo('surveys.manage');

    $this->actingAs($user);

    Volt::test('admin.surveys.create')
        ->assertSet('surveyTitle', '')
        ->assertSet('questions', fn ($q) => count($q) === 1);
});

test('admin create addQuestion appends empty question', function () {
    $this->createPermission('surveys.manage');
    $user = User::factory()->create();
    $user->givePermissionTo('surveys.manage');

    $this->actingAs($user);

    Volt::test('admin.surveys.create')
        ->call('addQuestion')
        ->assertSet('questions', fn ($q) => count($q) === 2);
});

test('admin create removeQuestion removes question at index', function () {
    $this->createPermission('surveys.manage');
    $user = User::factory()->create();
    $user->givePermissionTo('surveys.manage');

    $this->actingAs($user);

    Volt::test('admin.surveys.create')
        ->call('addQuestion')   // 2 questions
        ->call('removeQuestion', 0) // remove first
        ->assertSet('questions', fn ($q) => count($q) === 1);
});
