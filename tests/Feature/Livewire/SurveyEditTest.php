<?php

use App\Enums\QuestionType;
use App\Enums\SurveyStatus;
use App\Models\Form\Form;
use App\Models\Form\FormQuestion;
use App\Models\Form\FormQuestionOption;
use App\Models\Survey\Survey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function editableSurvey(array $attrs = []): Survey
{
    $user = User::factory()->create();
    $form = Form::create(['title' => 'Testformulär', 'created_by' => $user->id]);
    return Survey::create(array_merge([
        'title'        => 'Testenkät',
        'form_id'      => $form->id,
        'status'       => SurveyStatus::Draft,
        'created_by'   => $user->id,
        'is_anonymous' => false,
        'is_open'      => false,
    ], $attrs));
}

function editAdmin(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('surveys.manage');
    return $user;
}

// ── Permission ────────────────────────────────────────────────────────────────

test('edit requires surveys.manage permission', function () {
    $this->createPermission('surveys.manage');
    $survey = editableSurvey();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.surveys.edit', $survey))
        ->assertForbidden();
});

// ── Mount ─────────────────────────────────────────────────────────────────────

test('edit mounts with survey data', function () {
    $this->createPermission('surveys.manage');
    $survey = editableSurvey(['title' => 'Min enkät']);

    $this->actingAs(editAdmin());

    Volt::test('admin.surveys.edit', ['survey' => $survey])
        ->assertSet('surveyTitle', 'Min enkät');
});

test('edit mounts with existing questions from form', function () {
    $this->createPermission('surveys.manage');
    $survey = editableSurvey();
    FormQuestion::create(['form_id' => $survey->form_id, 'type' => QuestionType::Text, 'label' => 'Fråga A', 'sort_order' => 0]);
    FormQuestion::create(['form_id' => $survey->form_id, 'type' => QuestionType::Textarea, 'label' => 'Fråga B', 'sort_order' => 1]);

    $this->actingAs(editAdmin());

    Volt::test('admin.surveys.edit', ['survey' => $survey])
        ->assertSet('questions', fn ($q) => count($q) === 2 && $q[0]['label'] === 'Fråga A');
});

// ── save() — survey fields ────────────────────────────────────────────────────

test('save updates survey title', function () {
    $this->createPermission('surveys.manage');
    $survey = editableSurvey();
    FormQuestion::create(['form_id' => $survey->form_id, 'type' => QuestionType::Text, 'label' => 'Fråga', 'sort_order' => 0]);

    $this->actingAs(editAdmin());

    Volt::test('admin.surveys.edit', ['survey' => $survey])
        ->set('surveyTitle', 'Uppdaterad titel')
        ->call('save');

    expect($survey->fresh()->title)->toBe('Uppdaterad titel');
});

// ── save() — creates new questions via form->questions()->create() ─────────────

test('save creates new question in the forms table', function () {
    $this->createPermission('surveys.manage');
    $survey = editableSurvey();

    $this->actingAs(editAdmin());

    Volt::test('admin.surveys.edit', ['survey' => $survey])
        ->set('surveyTitle', 'Enkät')
        ->set('questions', [[
            '_id'              => null,
            'type'             => 'text',
            'label'            => 'Ny fråga',
            'description'      => '',
            'is_required'      => false,
            'sort_order'       => 0,
            'scale_min'        => 1,
            'scale_max'        => 5,
            'scale_icon'       => 'star',
            'datetime_variant' => 'date',
            'options'          => [],
        ]])
        ->call('save');

    expect(FormQuestion::where('form_id', $survey->form_id)->where('label', 'Ny fråga')->exists())->toBeTrue();
});

// ── save() — updates existing questions ──────────────────────────────────────

test('save updates existing question label', function () {
    $this->createPermission('surveys.manage');
    $survey   = editableSurvey();
    $question = FormQuestion::create(['form_id' => $survey->form_id, 'type' => QuestionType::Text, 'label' => 'Gammal label', 'sort_order' => 0]);

    $this->actingAs(editAdmin());

    Volt::test('admin.surveys.edit', ['survey' => $survey])
        ->set('surveyTitle', 'Enkät')
        ->set("questions.0.label", 'Ny label')
        ->call('save');

    expect($question->fresh()->label)->toBe('Ny label');
});

// ── save() — deletes removed questions ───────────────────────────────────────

test('save deletes questions removed from the array', function () {
    $this->createPermission('surveys.manage');
    $survey = editableSurvey();
    $q1     = FormQuestion::create(['form_id' => $survey->form_id, 'type' => QuestionType::Text, 'label' => 'Behålls', 'sort_order' => 0]);
    $q2     = FormQuestion::create(['form_id' => $survey->form_id, 'type' => QuestionType::Text, 'label' => 'Tas bort', 'sort_order' => 1]);

    $this->actingAs(editAdmin());

    Volt::test('admin.surveys.edit', ['survey' => $survey])
        ->set('surveyTitle', 'Enkät')
        ->set('questions', [[
            '_id'              => $q1->id,
            'type'             => 'text',
            'label'            => 'Behålls',
            'description'      => '',
            'is_required'      => false,
            'sort_order'       => 0,
            'scale_min'        => 1,
            'scale_max'        => 5,
            'scale_icon'       => 'star',
            'datetime_variant' => 'date',
            'options'          => [],
        ]])
        ->call('save');

    expect(FormQuestion::find($q1->id))->not->toBeNull()
        ->and(FormQuestion::find($q2->id))->toBeNull();
});

// ── removeQuestion() ──────────────────────────────────────────────────────────

test('removeQuestion deletes FormQuestion from DB', function () {
    $this->createPermission('surveys.manage');
    $survey   = editableSurvey();
    $question = FormQuestion::create(['form_id' => $survey->form_id, 'type' => QuestionType::Text, 'label' => 'Q', 'sort_order' => 0]);

    $this->actingAs(editAdmin());

    Volt::test('admin.surveys.edit', ['survey' => $survey])
        ->call('removeQuestion', 0);

    expect(FormQuestion::find($question->id))->toBeNull();
});

// ── save() — options (radio) ──────────────────────────────────────────────────

test('save creates options for radio question', function () {
    $this->createPermission('surveys.manage');
    $survey = editableSurvey();

    $this->actingAs(editAdmin());

    Volt::test('admin.surveys.edit', ['survey' => $survey])
        ->set('surveyTitle', 'Enkät')
        ->set('questions', [[
            '_id'              => null,
            'type'             => 'radio',
            'label'            => 'Välj ett',
            'description'      => '',
            'is_required'      => false,
            'sort_order'       => 0,
            'scale_min'        => 1,
            'scale_max'        => 5,
            'scale_icon'       => 'star',
            'datetime_variant' => 'date',
            'options'          => [
                ['_id' => null, 'text' => 'Ja'],
                ['_id' => null, 'text' => 'Nej'],
            ],
        ]])
        ->call('save');

    $question = FormQuestion::where('form_id', $survey->form_id)->first();
    expect($question)->not->toBeNull()
        ->and($question->options)->toHaveCount(2)
        ->and($question->options->pluck('text')->toArray())->toBe(['Ja', 'Nej']);
});

test('save deletes options removed from radio question', function () {
    $this->createPermission('surveys.manage');
    $survey   = editableSurvey();
    $question = FormQuestion::create(['form_id' => $survey->form_id, 'type' => QuestionType::Radio, 'label' => 'Val', 'sort_order' => 0]);
    $optJa    = FormQuestionOption::create(['question_id' => $question->id, 'text' => 'Ja',  'sort_order' => 0]);
    $optNej   = FormQuestionOption::create(['question_id' => $question->id, 'text' => 'Nej', 'sort_order' => 1]);

    $this->actingAs(editAdmin());

    Volt::test('admin.surveys.edit', ['survey' => $survey])
        ->set('surveyTitle', 'Enkät')
        ->set('questions', [[
            '_id'              => $question->id,
            'type'             => 'radio',
            'label'            => 'Val',
            'description'      => '',
            'is_required'      => false,
            'sort_order'       => 0,
            'scale_min'        => 1,
            'scale_max'        => 5,
            'scale_icon'       => 'star',
            'datetime_variant' => 'date',
            'options'          => [
                ['_id' => $optJa->id, 'text' => 'Ja'], // keep Ja, remove Nej
            ],
        ]])
        ->call('save');

    expect(FormQuestionOption::find($optJa->id))->not->toBeNull()
        ->and(FormQuestionOption::find($optNej->id))->toBeNull();
});

// ── save() — validation ───────────────────────────────────────────────────────

test('save fails validation when title is empty', function () {
    $this->createPermission('surveys.manage');
    $survey = editableSurvey();

    $this->actingAs(editAdmin());

    Volt::test('admin.surveys.edit', ['survey' => $survey])
        ->set('surveyTitle', '')
        ->call('save')
        ->assertHasErrors(['surveyTitle']);
});
