<?php

use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingAgendaItem;
use App\Models\Meeting\MeetingAttendee;
use App\Models\Meeting\MeetingTemplate;
use App\Models\Meeting\MeetingTemplateItem;
use App\Models\Membership\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function newMeeting(array $attrs = []): Meeting  // @phpstan-ignore-line (distinct from makeMeeting in MeetingAgendaItemTest)
{
    return Meeting::create(array_merge([
        'title'        => 'Styrelsemöte',
        'meeting_type' => 'board_meeting',
        'status'       => 'draft',
        'scheduled_at' => now()->addWeek(),
        'created_by'   => User::factory()->create()->id,
    ], $attrs));
}

// ── Status constants ──────────────────────────────────────────────────────────

test('meeting STATUSES constant contains all expected keys', function () {
    expect(array_keys(Meeting::STATUSES))->toBe([
        'draft', 'notice_sent', 'ongoing', 'pending_approval', 'approved', 'published', 'cancelled',
    ]);
});

test('statusLabel returns Swedish label', function () {
    $meeting = newMeeting(['status' => 'ongoing']);

    expect($meeting->statusLabel())->toBe('Pågående');
});

// ── nextStatus / nextStatusLabel ──────────────────────────────────────────────

test('nextStatus follows the correct flow', function () {
    $meeting = newMeeting();

    $statuses = [
        'draft'            => 'notice_sent',
        'notice_sent'      => 'ongoing',
        'ongoing'          => 'pending_approval',
        'pending_approval' => 'approved',
        'approved'         => 'published',
        'published'        => null,
        'cancelled'        => null,
    ];

    foreach ($statuses as $current => $expected) {
        $meeting->status = $current;
        expect($meeting->nextStatus())->toBe($expected);
    }
});

test('nextStatusLabel returns Swedish action label', function () {
    $meeting = newMeeting(['status' => 'draft']);

    expect($meeting->nextStatusLabel())->toBe('Skicka kallelse');

    $meeting->status = 'notice_sent';
    expect($meeting->nextStatusLabel())->toBe('Starta mötet');

    $meeting->status = 'ongoing';
    expect($meeting->nextStatusLabel())->toBe('Avsluta och skicka till justering');
});

test('nextStatusLabel returns null for terminal statuses', function () {
    $meeting = newMeeting(['status' => 'published']);

    expect($meeting->nextStatusLabel())->toBeNull();
});

// ── isApproved / isPublished ──────────────────────────────────────────────────

test('isApproved returns false without approved_at', function () {
    $meeting = newMeeting();

    expect($meeting->isApproved())->toBeFalse();
});

test('isApproved returns true when approved_at is set', function () {
    $meeting = newMeeting(['approved_at' => now()]);

    expect($meeting->isApproved())->toBeTrue();
});

test('isPublished returns false without published_at', function () {
    $meeting = newMeeting();

    expect($meeting->isPublished())->toBeFalse();
});

test('isPublished returns true when published_at is set', function () {
    $meeting = newMeeting(['published_at' => now()]);

    expect($meeting->isPublished())->toBeTrue();
});

// ── typeLabel ─────────────────────────────────────────────────────────────────

test('typeLabel returns meeting type description', function () {
    $meeting = newMeeting(['meeting_type' => 'board_meeting', 'is_extraordinary' => false]);

    expect($meeting->typeLabel())->toBe('Styrelsemöte');
});

test('typeLabel prepends Extra for extraordinary meetings', function () {
    $meeting = newMeeting(['meeting_type' => 'board_meeting', 'is_extraordinary' => true]);

    expect($meeting->typeLabel())->toBe('Extra Styrelsemöte');
});

// ── acceptsMotions / motionsOpen ─────────────────────────────────────────────

test('acceptsMotions is false for board_meeting', function () {
    $meeting = newMeeting(['meeting_type' => 'board_meeting']);

    expect($meeting->acceptsMotions())->toBeFalse();
});

test('acceptsMotions is true for members_meeting and annual_general', function () {
    $m1 = newMeeting(['meeting_type' => 'members_meeting']);
    $m2 = newMeeting(['meeting_type' => 'annual_general']);

    expect($m1->acceptsMotions())->toBeTrue()
        ->and($m2->acceptsMotions())->toBeTrue();
});

test('motionsOpen returns false for non-motion meeting type', function () {
    $meeting = newMeeting(['meeting_type' => 'board_meeting']);

    expect($meeting->motionsOpen())->toBeFalse();
});

test('motionsOpen returns true when no deadline set', function () {
    $meeting = newMeeting(['meeting_type' => 'members_meeting', 'motion_deadline' => null]);

    expect($meeting->motionsOpen())->toBeTrue();
});

test('motionsOpen returns true when deadline is today or future', function () {
    $meeting = newMeeting(['meeting_type' => 'annual_general', 'motion_deadline' => now()->toDateString()]);

    expect($meeting->motionsOpen())->toBeTrue();
});

test('motionsOpen returns false when deadline is past', function () {
    $meeting = newMeeting(['meeting_type' => 'annual_general', 'motion_deadline' => now()->subDay()->toDateString()]);

    expect($meeting->motionsOpen())->toBeFalse();
});

// ── agendaItems relation ──────────────────────────────────────────────────────

test('agendaItems relation returns items ordered by sort_order', function () {
    $meeting = newMeeting();

    MeetingAgendaItem::create(['meeting_id' => $meeting->id, 'sort_order' => 5, 'title' => 'Sista', 'item_type' => 'standard', 'is_required' => false]);
    MeetingAgendaItem::create(['meeting_id' => $meeting->id, 'sort_order' => 1, 'title' => 'Första', 'item_type' => 'standard', 'is_required' => false]);

    expect($meeting->agendaItems->first()->title)->toBe('Första');
});

// ── importFromTemplate ────────────────────────────────────────────────────────

test('importFromTemplate copies agenda items from template', function () {
    $user     = User::factory()->create();
    $template = MeetingTemplate::create([
        'name'         => 'Styrelsemallmöte',
        'meeting_type' => 'board_meeting',
        'created_by'   => $user->id,
    ]);

    MeetingTemplateItem::create(['template_id' => $template->id, 'sort_order' => 0, 'title' => 'Öppning',    'item_type' => 'standard', 'is_required' => true]);
    MeetingTemplateItem::create(['template_id' => $template->id, 'sort_order' => 1, 'title' => 'Dagordning', 'item_type' => 'standard', 'is_required' => true]);

    $meeting = newMeeting(['template_id' => $template->id]);
    $meeting->importFromTemplate($template->load('items', 'roleMappings'));

    expect($meeting->agendaItems)->toHaveCount(2)
        ->and($meeting->agendaItems->first()->title)->toBe('Öppning');
});

// ── votingList ────────────────────────────────────────────────────────────────

test('votingList returns only present attendees with voting rights', function () {
    $meeting = newMeeting(['status' => 'ongoing']);
    $member  = Member::factory()->create();

    MeetingAttendee::create([
        'meeting_id'    => $meeting->id,
        'member_id'     => $member->id,
        'present'       => true,
        'voting_rights' => true,
        'invited'       => true,
    ]);

    $member2 = Member::factory()->create();
    MeetingAttendee::create([
        'meeting_id'    => $meeting->id,
        'member_id'     => $member2->id,
        'present'       => false,
        'voting_rights' => true,
        'invited'       => true,
    ]);

    $member3 = Member::factory()->create();
    MeetingAttendee::create([
        'meeting_id'    => $meeting->id,
        'member_id'     => $member3->id,
        'present'       => true,
        'voting_rights' => false,
        'invited'       => true,
    ]);

    expect($meeting->votingList()->count())->toBe(1);
});
