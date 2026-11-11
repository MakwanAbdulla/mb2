<?php

use App\Models\AuditLog;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Set grace period to 1 month; tests run at 2026-03-13 so grace has already expired
    SystemSetting::set('membership.grace_period_months', 1);
});

test('command runs successfully with no candidates', function () {
    $exitCode = Artisan::call('members:deactivate-expired');
    expect($exitCode)->toBe(0);
});

test('deactivates active member with no membership period this year', function () {
    $member = Member::factory()->active()->create();
    // No MembershipPeriod for current year at all

    Artisan::call('members:deactivate-expired');

    expect($member->fresh()->status)->toBe(Member::STATUS_EXPIRED);
});

test('deactivates active member whose grace period has expired', function () {
    // Travel past the grace period end (grace ends 2026-02-01, now is 2026-03-13)
    $member = Member::factory()->active()->create();
    MembershipPeriod::factory()->create([
        'member_id'       => $member->id,
        'year'            => now()->year - 1,
        'status'          => MembershipPeriod::STATUS_ACTIVE,
        'membership_type' => $member->membership_type,
    ]);
    // No period for current year

    Artisan::call('members:deactivate-expired');

    expect($member->fresh()->status)->toBe(Member::STATUS_EXPIRED);
});

test('skips member who is still within grace period', function () {
    // Set grace to 12 months so member is always within period
    SystemSetting::set('membership.grace_period_months', 12);

    $member = Member::factory()->active()->create();
    MembershipPeriod::factory()->create([
        'member_id'       => $member->id,
        'year'            => now()->year - 1,
        'status'          => MembershipPeriod::STATUS_ACTIVE,
        'membership_type' => $member->membership_type,
    ]);

    Artisan::call('members:deactivate-expired');

    expect($member->fresh()->status)->toBe(Member::STATUS_ACTIVE);
});

test('skips honorary members', function () {
    $member = Member::factory()->active()->honorary()->create();

    Artisan::call('members:deactivate-expired');

    expect($member->fresh()->status)->toBe(Member::STATUS_ACTIVE);
});

test('skips members who already have active period this year', function () {
    $member = Member::factory()->active()->create();
    MembershipPeriod::factory()->active()->create([
        'member_id'       => $member->id,
        'year'            => now()->year,
        'membership_type' => $member->membership_type,
    ]);

    Artisan::call('members:deactivate-expired');

    expect($member->fresh()->status)->toBe(Member::STATUS_ACTIVE);
});

test('dry-run does not deactivate members', function () {
    $member = Member::factory()->active()->create();

    Artisan::call('members:deactivate-expired', ['--dry-run' => true]);

    expect($member->fresh()->status)->toBe(Member::STATUS_ACTIVE);
});

test('creates audit log entry on deactivation', function () {
    $member = Member::factory()->active()->create();

    Artisan::call('members:deactivate-expired');

    expect(AuditLog::where('action', 'membership_deactivated')
        ->where('auditable_type', (new Member)->getMorphClass())
        ->where('auditable_id', $member->id)
        ->exists()
    )->toBeTrue();
});

test('records correct deactivation reason in audit log', function () {
    $member = Member::factory()->active()->create();
    // No prior year period → reason = no_membership_period

    Artisan::call('members:deactivate-expired');

    $log = AuditLog::where('action', 'membership_deactivated')
        ->where('auditable_id', $member->id)
        ->first();

    expect($log?->changes['reason'])->toBe('no_membership_period');
});

test('records grace_period_expired reason when member had last year period', function () {
    $member = Member::factory()->active()->create();
    MembershipPeriod::factory()->create([
        'member_id'       => $member->id,
        'year'            => now()->year - 1,
        'status'          => MembershipPeriod::STATUS_ACTIVE,
        'membership_type' => $member->membership_type,
    ]);

    Artisan::call('members:deactivate-expired');

    $log = AuditLog::where('action', 'membership_deactivated')
        ->where('auditable_id', $member->id)
        ->first();

    expect($log?->changes['reason'])->toBe('grace_period_expired');
});

test('dry-run does not create audit log entries', function () {
    $member = Member::factory()->active()->create();

    Artisan::call('members:deactivate-expired', ['--dry-run' => true]);

    expect(AuditLog::where('action', 'membership_deactivated')->exists())->toBeFalse();
});
