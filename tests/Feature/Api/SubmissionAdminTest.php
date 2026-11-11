<?php

use App\Models\Meeting\MeetingAgendaItem;
use App\Models\Meeting\Meeting;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('submissions.view');
    $this->createPermission('submissions.manage');
});

function submissionsAdminUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('submissions.manage');
    return $user;
}

function makeSubmission(string $status = 'submitted'): Submission
{
    return Submission::create([
        'type'       => 'motion',
        'title'      => 'Testmotion',
        'body'       => 'Brödtext',
        'status'     => $status,
        'created_by' => User::factory()->create()->id,
    ]);
}

// ── PATCH /api/v1/submissions/{submission} ────────────────────────────────────

test('admin can update submission status', function () {
    $submission = makeSubmission();
    Sanctum::actingAs(submissionsAdminUser());

    $this->patchJson("/api/v1/submissions/{$submission->id}", [
        'status' => 'under_review',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'under_review');
});

test('admin can add board response to submission', function () {
    $submission = makeSubmission('under_review');
    Sanctum::actingAs(submissionsAdminUser());

    $this->patchJson("/api/v1/submissions/{$submission->id}", [
        'status'         => 'resolved',
        'board_response' => 'Styrelsen bifaller motionen.',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'resolved')
        ->assertJsonPath('data.board_response', 'Styrelsen bifaller motionen.');
});

test('admin can assign submission to agenda item', function () {
    $submission = makeSubmission();
    $admin   = submissionsAdminUser();
    $meeting = Meeting::create([
        'title' => 'Möte', 'meeting_type' => 'board', 'status' => 'draft',
        'scheduled_at' => now()->addWeek(), 'created_by' => $admin->id,
    ]);
    $item = MeetingAgendaItem::create([
        'meeting_id' => $meeting->id, 'title' => 'Motioner', 'order' => 5,
    ]);
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/submissions/{$submission->id}", [
        'agenda_item_id' => $item->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.agenda_item_id', $item->id);
});

test('submission admin update requires meetings.manage permission', function () {
    $submission = makeSubmission();
    Sanctum::actingAs(User::factory()->create());

    $this->patchJson("/api/v1/submissions/{$submission->id}", [
        'status' => 'under_review',
    ])->assertForbidden();
});

test('submission admin update requires auth', function () {
    $submission = makeSubmission();
    $this->patchJson("/api/v1/submissions/{$submission->id}", ['status' => 'under_review'])
        ->assertUnauthorized();
});

test('own submission can be viewed without special permission', function () {
    $user       = User::factory()->create();
    $submission = Submission::create([
        'type'       => 'motion',
        'title'      => 'Min motion',
        'body'       => 'Brödtext',
        'status'     => 'submitted',
        'created_by' => $user->id,
    ]);
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/submissions/{$submission->id}")
        ->assertOk()
        ->assertJsonPath('data.title', 'Min motion');
});
