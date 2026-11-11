<?php

use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingAgendaItem;
use App\Models\Membership\Member;
use App\Models\Poll;
use App\Models\PollBallot;
use App\Models\PollOption;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('meetings.manage');
});

function pollsAdminUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('meetings.manage');
    return $user;
}

function makePollSubmission(): Submission
{
    return Submission::create([
        'type'       => 'motion',
        'title'      => 'Testmotion',
        'body'       => 'Brödtext',
        'status'     => 'submitted',
        'created_by' => User::factory()->create()->id,
    ]);
}

// ── GET /api/v1/polls/pending ─────────────────────────────────────────────────

test('pending polls requires auth', function () {
    $this->getJson('/api/v1/polls/pending')->assertUnauthorized();
});

test('pending polls returns polls with null ballot option for current user', function () {
    $user       = pollsAdminUser();
    $member     = Member::factory()->create(['user_id' => $user->id]);
    $submission = makePollSubmission();

    $poll = Poll::create([
        'voteable_type' => Submission::class,
        'voteable_id'   => $submission->id,
        'question'      => 'Bifalla motionen?',
        'type'          => 'yes_no_abstain',
        'status'        => 'open',
        'eligible_count' => 1,
        'created_by'    => $user->id,
    ]);

    // Pre-populate ballot (pending = null option_id)
    PollBallot::create([
        'poll_id'   => $poll->id,
        'voter_id'  => $member->id,
        'option_id' => null,
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/polls/pending')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
});

test('pending polls does not return already-voted polls', function () {
    $user       = pollsAdminUser();
    $member     = Member::factory()->create(['user_id' => $user->id]);
    $submission = makePollSubmission();

    $poll   = Poll::create([
        'voteable_type'  => Submission::class,
        'voteable_id'    => $submission->id,
        'question'       => 'Bifalla?',
        'type'           => 'yes_no_abstain',
        'status'         => 'open',
        'eligible_count' => 1,
        'created_by'     => $user->id,
    ]);
    $option = PollOption::create(['poll_id' => $poll->id, 'label' => 'Ja', 'order' => 1]);

    PollBallot::create([
        'poll_id'   => $poll->id,
        'voter_id'  => $member->id,
        'option_id' => $option->id,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/polls/pending')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// ── POST /api/v1/polls ────────────────────────────────────────────────────────

test('can create poll attached to submission', function () {
    $submission = makePollSubmission();
    Sanctum::actingAs(pollsAdminUser());

    $this->postJson('/api/v1/polls', [
        'question'      => 'Bifalla motionen?',
        'type'          => 'yes_no_abstain',
        'voteable_type' => 'submission',
        'voteable_id'   => $submission->id,
        'options'       => ['Ja', 'Nej', 'Avstår'],
    ])
        ->assertCreated()
        ->assertJsonPath('data.question', 'Bifalla motionen?');
});

test('create poll requires meetings.manage permission', function () {
    $submission = makePollSubmission();
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/polls', [
        'title'         => 'Test',
        'voteable_type' => 'submission',
        'voteable_id'   => $submission->id,
        'options'       => ['Ja', 'Nej'],
    ])->assertForbidden();
});

// ── POST /api/v1/polls/{poll}/open ────────────────────────────────────────────

test('can open a pending poll', function () {
    $user       = pollsAdminUser();
    $member     = Member::factory()->create(['user_id' => $user->id]);
    $submission = makePollSubmission();
    $meeting    = Meeting::create([
        'title'        => 'Möte',
        'meeting_type' => 'board',
        'status'       => 'ongoing',
        'scheduled_at' => now(),
        'created_by'   => $user->id,
    ]);

    $poll = Poll::create([
        'voteable_type'  => Submission::class,
        'voteable_id'    => $submission->id,
        'question'       => 'Ja/Nej?',
        'type'           => 'yes_no_abstain',
        'status'         => 'pending',
        'eligible_count' => 0,
        'created_by'     => $user->id,
    ]);
    PollOption::create(['poll_id' => $poll->id, 'label' => 'Ja',    'sort_order' => 1]);
    PollOption::create(['poll_id' => $poll->id, 'label' => 'Nej',   'sort_order' => 2]);
    PollOption::create(['poll_id' => $poll->id, 'label' => 'Avstår','sort_order' => 3]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/polls/{$poll->id}/open", [
        'meeting_id' => $meeting->id,
        'voter_ids'  => [$member->id],
    ])->assertOk()->assertJsonPath('status', 'open');
});

// ── POST /api/v1/polls/{poll}/close ──────────────────────────────────────────

test('can close an open poll', function () {
    $user       = pollsAdminUser();
    $submission = makePollSubmission();

    $poll = Poll::create([
        'voteable_type'  => Submission::class,
        'voteable_id'    => $submission->id,
        'question'       => 'Bifalla?',
        'type'           => 'yes_no_abstain',
        'status'         => 'open',
        'eligible_count' => 1,
        'opened_at'      => now(),
        'created_by'     => $user->id,
    ]);
    PollOption::create(['poll_id' => $poll->id, 'label' => 'Ja',    'sort_order' => 1]);
    PollOption::create(['poll_id' => $poll->id, 'label' => 'Nej',   'sort_order' => 2]);
    PollOption::create(['poll_id' => $poll->id, 'label' => 'Avstår','sort_order' => 3]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/polls/{$poll->id}/close")
        ->assertOk()
        ->assertJsonPath('status', 'closed');
});

// ── POST /api/v1/polls/{poll}/close-manual ────────────────────────────────────

test('can close poll with manual result', function () {
    $user       = pollsAdminUser();
    $submission = makePollSubmission();

    $poll = Poll::create([
        'voteable_type'  => Submission::class,
        'voteable_id'    => $submission->id,
        'question'       => 'Bifalla?',
        'type'           => 'yes_no_abstain',
        'status'         => 'open',
        'eligible_count' => 10,
        'opened_at'      => now(),
        'created_by'     => $user->id,
    ]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/polls/{$poll->id}/close-manual", [
        'result_yes'     => 7,
        'result_no'      => 2,
        'result_abstain' => 1,
        'result_note'    => 'Manuellt räknad handuppräckning',
    ])
        ->assertOk()
        ->assertJsonPath('status', 'manual')
        ->assertJsonPath('result_yes', 7)
        ->assertJsonPath('result_no', 2)
        ->assertJsonPath('result_abstain', 1);
});
