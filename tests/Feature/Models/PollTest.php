<?php

use App\Models\Poll;
use App\Models\PollBallot;
use App\Models\PollOption;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function makePollSubject(): Submission
{
    return Submission::create([
        'type'       => 'motion',
        'title'      => 'Testmotion',
        'status'     => 'submitted',
        'created_by' => User::factory()->create()->id,
    ]);
}

function makePoll(string $type = 'yes_no_abstain', string $status = 'pending', array $extra = []): Poll
{
    return Poll::create(array_merge([
        'voteable_type'  => Submission::class,
        'voteable_id'    => makePollSubject()->id,
        'question'       => 'Bifalla motionen?',
        'type'           => $type,
        'status'         => $status,
        'eligible_count' => 0,
        'created_by'     => User::factory()->create()->id,
    ], $extra));
}

function addOptions(Poll $poll, array $labels): array
{
    return collect($labels)->values()->map(fn ($l, $i) =>
        PollOption::create(['poll_id' => $poll->id, 'label' => $l, 'sort_order' => $i])
    )->all();
}

// ── Status helpers ────────────────────────────────────────────────────────────

test('isOpen returns true only when status is open', function () {
    $open    = makePoll(status: 'open');
    $pending = makePoll(status: 'pending');
    $closed  = makePoll(status: 'closed');

    expect($open->isOpen())->toBeTrue()
        ->and($pending->isOpen())->toBeFalse()
        ->and($closed->isOpen())->toBeFalse();
});

test('isClosed returns true for closed and manual statuses', function () {
    $closed = makePoll(status: 'closed');
    $manual = makePoll(status: 'manual');
    $open   = makePoll(status: 'open');

    expect($closed->isClosed())->toBeTrue()
        ->and($manual->isClosed())->toBeTrue()
        ->and($open->isClosed())->toBeFalse();
});

// ── votesCast ─────────────────────────────────────────────────────────────────

test('votesCast on open poll counts ballots with option_id set', function () {
    $poll    = makePoll(status: 'open');
    $options = addOptions($poll, ['Ja', 'Nej', 'Avstår']);

    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $u3 = User::factory()->create();

    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $u1->id, 'option_id' => $options[0]->id, 'voted_at' => now()]);
    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $u2->id, 'option_id' => null]); // pending
    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $u3->id, 'option_id' => $options[1]->id, 'voted_at' => now()]);

    expect($poll->votesCast())->toBe(2);
});

test('votesCast on closed poll sums result columns', function () {
    $poll = makePoll(status: 'closed', extra: [
        'result_yes'     => 5,
        'result_no'      => 3,
        'result_abstain' => 2,
    ]);

    expect($poll->votesCast())->toBe(10);
});

// ── hasVoted ─────────────────────────────────────────────────────────────────

test('hasVoted returns false when no ballot exists', function () {
    $poll = makePoll(status: 'open');
    $user = User::factory()->create();

    expect($poll->hasVoted($user->id))->toBeFalse();
});

test('hasVoted returns false when ballot has no option set', function () {
    $poll = makePoll(status: 'open');
    $user = User::factory()->create();

    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $user->id, 'option_id' => null]);

    expect($poll->hasVoted($user->id))->toBeFalse();
});

test('hasVoted returns true when ballot has option set', function () {
    $poll    = makePoll(status: 'open');
    $options = addOptions($poll, ['Ja', 'Nej']);
    $user    = User::factory()->create();

    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $user->id, 'option_id' => $options[0]->id, 'voted_at' => now()]);

    expect($poll->hasVoted($user->id))->toBeTrue();
});

// ── resultLabel ──────────────────────────────────────────────────────────────

test('resultLabel returns correct Swedish labels', function () {
    $poll = makePoll(extra: ['result' => 'approved']);

    expect($poll->resultLabel())->toBe('Bifallen');

    $poll->result = 'rejected'; expect($poll->resultLabel())->toBe('Avslagen');
    $poll->result = 'tied';     expect($poll->resultLabel())->toBe('Lika röstetal');
    $poll->result = 'no_quorum'; expect($poll->resultLabel())->toBe('Beslutsoförmögen');
    $poll->result = null;        expect($poll->resultLabel())->toBeNull();
});

// ── seedDefaultOptions ────────────────────────────────────────────────────────

test('open seeds yes_no_abstain options', function () {
    $poll = makePoll('yes_no_abstain');
    $poll->open(voterIds: [User::factory()->create()->id]);

    $labels = $poll->options->pluck('label')->all();

    expect($labels)->toBe(['Ja', 'Nej', 'Avstår']);
});

test('open seeds yes_no options', function () {
    $poll = makePoll('yes_no');
    $poll->open(voterIds: [User::factory()->create()->id]);

    $labels = $poll->options->pluck('label')->all();

    expect($labels)->toBe(['Ja', 'Nej']);
});

test('open does not duplicate options if already seeded', function () {
    $poll = makePoll('yes_no_abstain');
    addOptions($poll, ['Ja', 'Nej', 'Avstår']);
    $poll->open(voterIds: [User::factory()->create()->id]);

    expect($poll->options()->count())->toBe(3);
});

// ── open + seedBallots ────────────────────────────────────────────────────────

test('open with voterIds sets eligible_count and creates ballots', function () {
    $poll = makePoll('yes_no');
    $u1   = User::factory()->create();
    $u2   = User::factory()->create();

    $poll->open(voterIds: [$u1->id, $u2->id]);
    $poll->refresh();

    expect($poll->status)->toBe('open')
        ->and($poll->eligible_count)->toBe(2)
        ->and($poll->ballots()->count())->toBe(2);
});

// ── castVote ─────────────────────────────────────────────────────────────────

test('castVote creates ballot entry with option and voted_at', function () {
    $poll    = makePoll('yes_no', 'open');
    $options = addOptions($poll, ['Ja', 'Nej']);
    $user    = User::factory()->create();

    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $user->id, 'option_id' => null]);

    $poll->castVote($user->id, $options[0]->id);

    $ballot = $poll->ballots()->where('voter_id', $user->id)->first();
    expect($ballot->option_id)->toBe($options[0]->id)
        ->and($ballot->voted_at)->not->toBeNull();
});

// ── close ─────────────────────────────────────────────────────────────────────

test('close counts ballots correctly and sets result', function () {
    $poll    = makePoll('yes_no_abstain', 'open', ['eligible_count' => 3]);
    $options = addOptions($poll, ['Ja', 'Nej', 'Avstår']);
    $u1      = User::factory()->create();
    $u2      = User::factory()->create();
    $u3      = User::factory()->create();

    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $u1->id, 'option_id' => $options[0]->id, 'voted_at' => now()]); // Ja
    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $u2->id, 'option_id' => $options[0]->id, 'voted_at' => now()]); // Ja
    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $u3->id, 'option_id' => $options[1]->id, 'voted_at' => now()]); // Nej

    $poll->close();
    $poll->refresh();

    expect($poll->status)->toBe('closed')
        ->and($poll->result_yes)->toBe(2)
        ->and($poll->result_no)->toBe(1)
        ->and($poll->result_abstain)->toBe(0)
        ->and($poll->result)->toBe('approved')
        ->and($poll->closed_at)->not->toBeNull();
});

test('close sets result to rejected when no votes win', function () {
    $poll    = makePoll('yes_no', 'open', ['eligible_count' => 2]);
    $options = addOptions($poll, ['Ja', 'Nej']);
    $u1      = User::factory()->create();
    $u2      = User::factory()->create();

    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $u1->id, 'option_id' => $options[1]->id, 'voted_at' => now()]); // Nej
    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $u2->id, 'option_id' => $options[1]->id, 'voted_at' => now()]); // Nej

    $poll->close();
    $poll->refresh();

    expect($poll->result)->toBe('rejected');
});

test('close sets result to tied when equal votes', function () {
    $poll    = makePoll('yes_no', 'open', ['eligible_count' => 2]);
    $options = addOptions($poll, ['Ja', 'Nej']);
    $u1      = User::factory()->create();
    $u2      = User::factory()->create();

    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $u1->id, 'option_id' => $options[0]->id, 'voted_at' => now()]); // Ja
    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $u2->id, 'option_id' => $options[1]->id, 'voted_at' => now()]); // Nej

    $poll->close();
    $poll->refresh();

    expect($poll->result)->toBe('tied');
});

// ── closeManual ───────────────────────────────────────────────────────────────

test('closeManual sets status to manual with given counts', function () {
    $poll = makePoll('yes_no_abstain', 'open', ['eligible_count' => 10]);

    $poll->closeManual(7, 2, 1, 'Acklamation');
    $poll->refresh();

    expect($poll->status)->toBe('manual')
        ->and($poll->result_yes)->toBe(7)
        ->and($poll->result_no)->toBe(2)
        ->and($poll->result_abstain)->toBe(1)
        ->and($poll->result_note)->toBe('Acklamation')
        ->and($poll->result)->toBe('approved');
});

// ── isResultDetermined ────────────────────────────────────────────────────────

test('isResultDetermined returns true when yes cannot be overtaken', function () {
    $poll    = makePoll('yes_no_abstain', 'open', ['eligible_count' => 5]);
    $options = addOptions($poll, ['Ja', 'Nej', 'Avstår']);
    $users   = User::factory()->count(5)->create();

    // 3 Ja, 1 Nej, 1 pending → yes=3, no=1, remaining=1. 3 > 1+1=2 → determined
    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $users[0]->id, 'option_id' => $options[0]->id, 'voted_at' => now()]);
    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $users[1]->id, 'option_id' => $options[0]->id, 'voted_at' => now()]);
    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $users[2]->id, 'option_id' => $options[0]->id, 'voted_at' => now()]);
    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $users[3]->id, 'option_id' => $options[1]->id, 'voted_at' => now()]);
    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $users[4]->id, 'option_id' => null]);

    expect($poll->isResultDetermined())->toBeTrue();
});

test('isResultDetermined returns false when result still can change', function () {
    $poll    = makePoll('yes_no_abstain', 'open', ['eligible_count' => 5]);
    $options = addOptions($poll, ['Ja', 'Nej', 'Avstår']);
    $users   = User::factory()->count(5)->create();

    // 2 Ja, 1 Nej, 2 pending → still undecided
    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $users[0]->id, 'option_id' => $options[0]->id, 'voted_at' => now()]);
    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $users[1]->id, 'option_id' => $options[0]->id, 'voted_at' => now()]);
    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $users[2]->id, 'option_id' => $options[1]->id, 'voted_at' => now()]);
    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $users[3]->id, 'option_id' => null]);
    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $users[4]->id, 'option_id' => null]);

    expect($poll->isResultDetermined())->toBeFalse();
});

test('castVote with deadline auto-closes when result determined', function () {
    $poll    = makePoll('yes_no', 'open', ['eligible_count' => 3, 'deadline' => now()->addDay()]);
    $options = addOptions($poll, ['Ja', 'Nej']);
    $users   = User::factory()->count(3)->create();

    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $users[0]->id, 'option_id' => $options[0]->id, 'voted_at' => now()]);
    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $users[1]->id, 'option_id' => null]);
    PollBallot::create(['poll_id' => $poll->id, 'voter_id' => $users[2]->id, 'option_id' => null]);

    // Cast winning vote → 2 Ja, 0 Nej, 1 remaining → 2 > 0+1 → determined
    $poll->castVote($users[1]->id, $options[0]->id);
    $poll->refresh();

    expect($poll->status)->toBe('closed')
        ->and($poll->result)->toBe('approved');
});
