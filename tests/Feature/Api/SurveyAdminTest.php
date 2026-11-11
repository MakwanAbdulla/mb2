<?php

use App\Enums\SurveyStatus;
use App\Models\Survey\Survey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('surveys.view');
    $this->createPermission('surveys.manage');
});

function surveysAdminUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('surveys.manage');
    return $user;
}

function makeSurvey(SurveyStatus $status = SurveyStatus::Draft): Survey
{
    return Survey::create([
        'title'      => 'Testenkät',
        'status'     => $status,
        'created_by' => User::factory()->create()->id,
    ]);
}

// ── GET /api/v1/admin/surveys ─────────────────────────────────────────────────

test('admin surveys list requires auth', function () {
    $this->getJson('/api/v1/admin/surveys')->assertUnauthorized();
});

test('admin surveys list requires surveys permission', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/admin/surveys')->assertForbidden();
});

test('admin surveys list returns all surveys', function () {
    makeSurvey(SurveyStatus::Draft);
    makeSurvey(SurveyStatus::Active);
    makeSurvey(SurveyStatus::Closed);
    Sanctum::actingAs(surveysAdminUser());

    $this->getJson('/api/v1/admin/surveys')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('admin surveys list can filter by status', function () {
    makeSurvey(SurveyStatus::Draft);
    makeSurvey(SurveyStatus::Active);
    Sanctum::actingAs(surveysAdminUser());

    $this->getJson('/api/v1/admin/surveys?status=active')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ── POST /api/v1/admin/surveys ────────────────────────────────────────────────

test('admin can create survey draft', function () {
    Sanctum::actingAs(surveysAdminUser());

    $this->postJson('/api/v1/admin/surveys', [
        'title'       => 'Nöjdhetsenkät 2027',
        'description' => 'Berätta vad du tycker',
    ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Nöjdhetsenkät 2027')
        ->assertJsonPath('data.status', 'draft');
});

test('create survey requires surveys.manage permission', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('surveys.view');
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/admin/surveys', ['title' => 'Test'])->assertForbidden();
});

// ── GET /api/v1/admin/surveys/{survey} ────────────────────────────────────────

test('admin can get survey detail', function () {
    $survey = makeSurvey();
    Sanctum::actingAs(surveysAdminUser());

    $this->getJson("/api/v1/admin/surveys/{$survey->id}")
        ->assertOk()
        ->assertJsonPath('data.title', 'Testenkät');
});

// ── PATCH /api/v1/admin/surveys/{survey} ──────────────────────────────────────

test('admin can update survey', function () {
    $survey = makeSurvey();
    Sanctum::actingAs(surveysAdminUser());

    $this->patchJson("/api/v1/admin/surveys/{$survey->id}", [
        'title'  => 'Uppdaterad enkät',
        'status' => 'active',
    ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Uppdaterad enkät');
});

// ── DELETE /api/v1/admin/surveys/{survey} ─────────────────────────────────────

test('admin can delete draft survey', function () {
    $survey = makeSurvey(SurveyStatus::Draft);
    Sanctum::actingAs(surveysAdminUser());

    $this->deleteJson("/api/v1/admin/surveys/{$survey->id}")
        ->assertOk();

    expect(Survey::find($survey->id))->toBeNull();
});

test('cannot delete active survey', function () {
    $survey = makeSurvey(SurveyStatus::Active);
    Sanctum::actingAs(surveysAdminUser());

    $this->deleteJson("/api/v1/admin/surveys/{$survey->id}")
        ->assertStatus(422);
});

// ── GET /api/v1/admin/surveys/{survey}/responses ──────────────────────────────

test('admin can list survey responses', function () {
    $survey = makeSurvey();
    Sanctum::actingAs(surveysAdminUser());

    $this->getJson("/api/v1/admin/surveys/{$survey->id}/responses")
        ->assertOk()
        ->assertJsonStructure(['data']);
});
