<?php

use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingAgendaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('meetings.manage');
});

function meetingsAdminUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('meetings.manage');
    return $user;
}

function makeMeeting(): Meeting
{
    return Meeting::create([
        'title'        => 'Styrelsemöte',
        'meeting_type' => 'board',
        'status'       => 'draft',
        'scheduled_at' => now()->addWeek(),
        'created_by'   => User::factory()->create()->id,
    ]);
}

// ── GET /api/v1/meetings/{meeting}/agenda-items ───────────────────────────────

test('agenda items list requires auth', function () {
    $meeting = makeMeeting();
    $this->getJson("/api/v1/meetings/{$meeting->id}/agenda-items")->assertUnauthorized();
});

test('agenda items list returns items ordered by order', function () {
    $meeting = makeMeeting();
    MeetingAgendaItem::create(['meeting_id' => $meeting->id, 'title' => 'Punkt 2', 'sort_order' => 2]);
    MeetingAgendaItem::create(['meeting_id' => $meeting->id, 'title' => 'Punkt 1', 'sort_order' => 1]);

    Sanctum::actingAs(meetingsAdminUser());

    $response = $this->getJson("/api/v1/meetings/{$meeting->id}/agenda-items")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect($response->json('data.0.title'))->toBe('Punkt 1');
});

// ── POST /api/v1/meetings/{meeting}/agenda-items ──────────────────────────────

test('can create agenda item', function () {
    $meeting = makeMeeting();
    Sanctum::actingAs(meetingsAdminUser());

    $this->postJson("/api/v1/meetings/{$meeting->id}/agenda-items", [
        'title'       => 'Val av sekreterare',
        'description' => 'Enligt stadgarna',
        'sort_order'  => 3,
        'item_type'   => 'vote',
    ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Val av sekreterare')
        ->assertJsonPath('data.meeting_id', $meeting->id);
});

test('create agenda item requires meetings.manage permission', function () {
    $meeting = makeMeeting();
    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/meetings/{$meeting->id}/agenda-items", [
        'title' => 'Test',
        'order' => 1,
    ])->assertForbidden();
});

// ── PATCH /api/v1/meetings/{meeting}/agenda-items/{agendaItem} ────────────────

test('can update agenda item', function () {
    $meeting = makeMeeting();
    $item    = MeetingAgendaItem::create(['meeting_id' => $meeting->id, 'title' => 'Gammal titel', 'sort_order' => 1]);
    Sanctum::actingAs(meetingsAdminUser());

    $this->patchJson("/api/v1/meetings/{$meeting->id}/agenda-items/{$item->id}", [
        'title'      => 'Ny titel',
        'sort_order' => 2,
    ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Ny titel');
});

test('cannot update agenda item belonging to different meeting', function () {
    $meeting1 = makeMeeting();
    $meeting2 = makeMeeting();
    $item     = MeetingAgendaItem::create(['meeting_id' => $meeting2->id, 'title' => 'Annan', 'sort_order' => 1]);
    Sanctum::actingAs(meetingsAdminUser());

    $this->patchJson("/api/v1/meetings/{$meeting1->id}/agenda-items/{$item->id}", [
        'title' => 'Hack',
        'order' => 1,
    ])->assertNotFound();
});

// ── DELETE /api/v1/meetings/{meeting}/agenda-items/{agendaItem} ───────────────

test('can delete agenda item', function () {
    $meeting = makeMeeting();
    $item    = MeetingAgendaItem::create(['meeting_id' => $meeting->id, 'title' => 'Ta bort', 'sort_order' => 1]);
    Sanctum::actingAs(meetingsAdminUser());

    $this->deleteJson("/api/v1/meetings/{$meeting->id}/agenda-items/{$item->id}")
        ->assertOk();

    expect(MeetingAgendaItem::find($item->id))->toBeNull();
});
