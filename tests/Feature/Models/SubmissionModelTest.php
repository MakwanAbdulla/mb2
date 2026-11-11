<?php

use App\Models\Meeting\MeetingAgendaItem;
use App\Models\Meeting\Meeting;
use App\Models\Poll;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeSubModel(array $attrs = []): Submission
{
    return Submission::create(array_merge([
        'type'       => 'issue',
        'title'      => 'Testärende',
        'status'     => 'submitted',
        'created_by' => User::factory()->create()->id,
    ], $attrs));
}

// ── Type and status constants ─────────────────────────────────────────────────

test('Submission TYPES constant has correct values', function () {
    expect(Submission::TYPES)->toBe([
        'issue'  => 'Ärende',
        'motion' => 'Motion',
    ]);
});

test('Submission STATUSES constant has correct values', function () {
    expect(array_keys(Submission::STATUSES))->toBe([
        'submitted', 'under_review', 'on_agenda', 'resolved', 'rejected',
    ]);
});

// ── typeLabel / statusLabel ───────────────────────────────────────────────────

test('typeLabel returns correct Swedish label', function () {
    $issue  = makeSubModel(['type' => 'issue']);
    $motion = makeSubModel(['type' => 'motion']);

    expect($issue->typeLabel())->toBe('Ärende')
        ->and($motion->typeLabel())->toBe('Motion');
});

test('statusLabel returns correct Swedish label', function () {
    $s = makeSubModel(['status' => 'submitted']);
    expect($s->statusLabel())->toBe('Inlämnad');

    $s->status = 'under_review';
    expect($s->statusLabel())->toBe('Under behandling');

    $s->status = 'on_agenda';
    expect($s->statusLabel())->toBe('På dagordning');

    $s->status = 'resolved';
    expect($s->statusLabel())->toBe('Behandlad');

    $s->status = 'rejected';
    expect($s->statusLabel())->toBe('Avvisad');
});

// ── isOpen ────────────────────────────────────────────────────────────────────

test('isOpen returns true for submitted and under_review', function () {
    $submitted    = makeSubModel(['status' => 'submitted']);
    $underReview  = makeSubModel(['status' => 'under_review']);

    expect($submitted->isOpen())->toBeTrue()
        ->and($underReview->isOpen())->toBeTrue();
});

test('isOpen returns false for terminal statuses', function () {
    $onAgenda = makeSubModel(['status' => 'on_agenda']);
    $resolved = makeSubModel(['status' => 'resolved']);
    $rejected = makeSubModel(['status' => 'rejected']);

    expect($onAgenda->isOpen())->toBeFalse()
        ->and($resolved->isOpen())->toBeFalse()
        ->and($rejected->isOpen())->toBeFalse();
});

// ── Gmail source fields ───────────────────────────────────────────────────────

test('submission stores and retrieves Gmail source fields', function () {
    $s = makeSubModel([
        'source_gmail_id'      => 'gmail-msg-abc123',
        'source_gmail_subject' => 'Feedback på föreningen',
        'source_gmail_from'    => 'member@example.com',
    ]);

    $fresh = $s->fresh();
    expect($fresh->source_gmail_id)->toBe('gmail-msg-abc123')
        ->and($fresh->source_gmail_subject)->toBe('Feedback på föreningen')
        ->and($fresh->source_gmail_from)->toBe('member@example.com');
});

// ── HasPolls trait ────────────────────────────────────────────────────────────

test('submission polls relation is MorphMany', function () {
    $sub  = makeSubModel();
    $user = User::factory()->create();

    Poll::create([
        'voteable_type'  => Submission::class,
        'voteable_id'    => $sub->id,
        'question'       => 'Bifalla?',
        'type'           => 'yes_no_abstain',
        'status'         => 'open',
        'eligible_count' => 0,
        'created_by'     => $user->id,
    ]);

    expect($sub->polls)->toHaveCount(1);
});

test('activePoll returns open poll', function () {
    $sub  = makeSubModel();
    $user = User::factory()->create();

    Poll::create([
        'voteable_type'  => Submission::class,
        'voteable_id'    => $sub->id,
        'question'       => 'Bifalla?',
        'type'           => 'yes_no',
        'status'         => 'open',
        'eligible_count' => 0,
        'created_by'     => $user->id,
    ]);

    expect($sub->activePoll())->not->toBeNull()
        ->and($sub->activePoll()->status)->toBe('open');
});

test('activePoll returns null when no open poll', function () {
    $sub  = makeSubModel();
    $user = User::factory()->create();

    Poll::create([
        'voteable_type'  => Submission::class,
        'voteable_id'    => $sub->id,
        'question'       => 'Bifalla?',
        'type'           => 'yes_no',
        'status'         => 'closed',
        'eligible_count' => 0,
        'created_by'     => $user->id,
    ]);

    expect($sub->activePoll())->toBeNull();
});

// ── agendaItem relation ───────────────────────────────────────────────────────

test('submission agendaItem relation', function () {
    $creator = User::factory()->create();
    $meeting = Meeting::create([
        'title'        => 'Möte',
        'meeting_type' => 'board_meeting',
        'status'       => 'draft',
        'scheduled_at' => now(),
        'created_by'   => $creator->id,
    ]);
    $item = MeetingAgendaItem::create([
        'meeting_id' => $meeting->id,
        'sort_order' => 0,
        'title'      => 'Ärenden',
        'item_type'  => 'standard',
        'is_required' => false,
    ]);

    $sub = makeSubModel(['agenda_item_id' => $item->id]);

    expect($sub->agendaItem)->not->toBeNull()
        ->and($sub->agendaItem->id)->toBe($item->id);
});
