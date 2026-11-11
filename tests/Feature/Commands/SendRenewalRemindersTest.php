<?php

use App\Mail\MembershipRenewalMail;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use App\Models\Content\NotificationLog;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    // Tests run in March — set renewal visible from December so command normally skips
    SystemSetting::set('membership.renewal_visible_month', 12);
});

/** Open renewal window by setting the visible month to the current month or earlier. */
function openRenewalWindow(): void
{
    SystemSetting::set('membership.renewal_visible_month', now()->month);
}

/** Create an active member with an active membership period for the current year. */
function activeMemberWithCurrentPeriod(array $memberOverrides = []): Member
{
    $member = Member::factory()->active()->create($memberOverrides);
    MembershipPeriod::factory()->active()->create([
        'member_id'       => $member->id,
        'year'            => now()->year,
        'membership_type' => $member->membership_type,
    ]);
    return $member;
}

// ── Seasonal gate ─────────────────────────────────────────────────────────────

test('does nothing when current month is before renewal month', function () {
    // renewal_visible_month=12, current month is March
    Artisan::call('members:send-renewal-reminders');

    Mail::assertNothingSent();
});

test('returns success even when skipped due to month', function () {
    $exitCode = Artisan::call('members:send-renewal-reminders');

    expect($exitCode)->toBe(0);
});

// ── Sending logic ─────────────────────────────────────────────────────────────

test('sends renewal mail to active member without next-year period', function () {
    openRenewalWindow();
    activeMemberWithCurrentPeriod();

    Artisan::call('members:send-renewal-reminders');

    Mail::assertSent(MembershipRenewalMail::class, 1);
});

test('sends to member email when user has no email', function () {
    openRenewalWindow();
    $member = activeMemberWithCurrentPeriod(['email' => 'member@example.com']);
    $member->user?->update(['email' => null]);

    Artisan::call('members:send-renewal-reminders');

    Mail::assertSent(MembershipRenewalMail::class, 1);
});

test('skips member with no email', function () {
    openRenewalWindow();
    $member = Member::factory()->active()->create(['email' => null]);
    $member->user?->update(['email' => null]);
    MembershipPeriod::factory()->active()->create([
        'member_id' => $member->id,
        'year'      => now()->year,
    ]);

    Artisan::call('members:send-renewal-reminders');

    Mail::assertNothingSent();
});

test('skips member who already has active period for next year', function () {
    openRenewalWindow();
    $member = activeMemberWithCurrentPeriod();
    MembershipPeriod::factory()->active()->create([
        'member_id' => $member->id,
        'year'      => now()->year + 1,
    ]);

    Artisan::call('members:send-renewal-reminders');

    Mail::assertNothingSent();
});

test('skips member with pending payment for next year', function () {
    openRenewalWindow();
    $member = activeMemberWithCurrentPeriod();
    MembershipPeriod::factory()->create([
        'member_id' => $member->id,
        'year'      => now()->year + 1,
        'status'    => MembershipPeriod::STATUS_PENDING_PAYMENT,
    ]);

    Artisan::call('members:send-renewal-reminders');

    Mail::assertNothingSent();
});

test('skips member who already received a renewal reminder this year', function () {
    openRenewalWindow();
    $member = activeMemberWithCurrentPeriod();

    $notifType = 'renewal_reminder_' . (now()->year + 1);
    NotificationLog::record($member, $notifType, $member->email);

    Artisan::call('members:send-renewal-reminders');

    Mail::assertNothingSent();
});

test('skips inactive members', function () {
    openRenewalWindow();
    $member = Member::factory()->create(['status' => Member::STATUS_INACTIVE]);
    MembershipPeriod::factory()->active()->create([
        'member_id' => $member->id,
        'year'      => now()->year,
    ]);

    Artisan::call('members:send-renewal-reminders');

    Mail::assertNothingSent();
});

test('skips member with no current-year period', function () {
    openRenewalWindow();
    Member::factory()->active()->create();

    Artisan::call('members:send-renewal-reminders');

    Mail::assertNothingSent();
});

// ── Notification log ──────────────────────────────────────────────────────────

test('records notification log after sending', function () {
    openRenewalWindow();
    $member = activeMemberWithCurrentPeriod();

    Artisan::call('members:send-renewal-reminders');

    $notifType = 'renewal_reminder_' . (now()->year + 1);
    expect(NotificationLog::where('notifiable_type', Member::class)
        ->where('notifiable_id', $member->id)
        ->where('notification_type', $notifType)
        ->exists()
    )->toBeTrue();
});

test('does not send to same member twice in same run', function () {
    openRenewalWindow();
    activeMemberWithCurrentPeriod();

    Artisan::call('members:send-renewal-reminders');
    Artisan::call('members:send-renewal-reminders');

    Mail::assertSent(MembershipRenewalMail::class, 1);
});

// ── Dry-run ───────────────────────────────────────────────────────────────────

test('dry-run does not send emails', function () {
    openRenewalWindow();
    activeMemberWithCurrentPeriod();

    Artisan::call('members:send-renewal-reminders', ['--dry-run' => true]);

    Mail::assertNothingSent();
});

test('dry-run does not create notification log entries', function () {
    openRenewalWindow();
    activeMemberWithCurrentPeriod();

    Artisan::call('members:send-renewal-reminders', ['--dry-run' => true]);

    expect(NotificationLog::count())->toBe(0);
});

test('dry-run returns success exit code', function () {
    openRenewalWindow();
    activeMemberWithCurrentPeriod();

    $exitCode = Artisan::call('members:send-renewal-reminders', ['--dry-run' => true]);

    expect($exitCode)->toBe(0);
});

// ── Multiple members ──────────────────────────────────────────────────────────

test('sends to all eligible members', function () {
    openRenewalWindow();
    activeMemberWithCurrentPeriod();
    activeMemberWithCurrentPeriod();
    activeMemberWithCurrentPeriod();

    Artisan::call('members:send-renewal-reminders');

    Mail::assertSent(MembershipRenewalMail::class, 3);
});
