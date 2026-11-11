<?php

use App\Enums\SurveyStatus;
use App\Models\Form\Form;
use App\Models\Survey\Survey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function indexSurvey(array $attrs = []): Survey
{
    $user = User::factory()->create();
    $form = Form::create(['title' => 'Testformulär', 'created_by' => $user->id]);

    return Survey::create(array_merge([
        'title' => 'Testenkät',
        'form_id' => $form->id,
        'status' => SurveyStatus::Draft,
        'is_open' => false,
        'created_by' => $user->id,
    ], $attrs));
}

function indexAdmin(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('surveys.manage');

    return $user;
}

// ── Permission ────────────────────────────────────────────────────────────────

test('index requires surveys.manage permission', function () {
    $this->createPermission('surveys.manage');

    $this->actingAs(User::factory()->create())
        ->get(route('admin.surveys.index'))
        ->assertForbidden();
});

test('index is accessible with surveys.manage permission', function () {
    $this->createPermission('surveys.manage');
    $this->createPermission('users.manage-roles');
    indexSurvey(['title' => 'Medlemsenkät 2026']);

    $this->actingAs(indexAdmin())
        ->get(route('admin.surveys.index'))
        ->assertOk()
        ->assertSee('Medlemsenkät 2026');
});

test('index actions require surveys.manage permission', function (string $action) {
    $this->createPermission('surveys.manage');
    $survey = indexSurvey();
    $user = indexAdmin();

    $this->actingAs($user);
    $component = Volt::test('admin.surveys.index');

    // Permission revoked after the page was loaded: actions must check again
    $user->revokePermissionTo('surveys.manage');

    $component->call($action, $survey->id)->assertForbidden();

    expect(Survey::find($survey->id))->not->toBeNull()
        ->and($survey->fresh()->status)->toBe(SurveyStatus::Draft);
})->with(['delete', 'activate', 'close']);

// ── Actions ───────────────────────────────────────────────────────────────────

test('index close sets status to closed', function () {
    $this->createPermission('surveys.manage');
    $survey = indexSurvey(['status' => SurveyStatus::Active]);

    $this->actingAs(indexAdmin());

    Volt::test('admin.surveys.index')->call('close', $survey->id);

    expect($survey->fresh()->status)->toBe(SurveyStatus::Closed);
});

test('index delete removes the survey', function () {
    $this->createPermission('surveys.manage');
    $survey = indexSurvey();

    $this->actingAs(indexAdmin());

    Volt::test('admin.surveys.index')->call('delete', $survey->id);

    expect(Survey::find($survey->id))->toBeNull();
});
