<?php

use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function configureCredentials(): void
{
    config([
        'services.cogworks.org_code'  => 'TEST_ORG',
        'services.cogworks.api_token' => 'TEST_TOKEN',
    ]);
}

function cogWorksXml(array $persons = [], array $groups = []): string
{
    $groupsXml = '';
    foreach ($groups as $id => $name) {
        $groupsXml .= "<group id=\"{$id}\"><name>{$name}</name></group>";
    }

    $personsXml = '';
    foreach ($persons as $p) {
        $lastMship = '';
        if (! empty($p['last_period_year'])) {
            $lastMship = '<lastMembership>'
                . '<periodName>' . $p['last_period_year'] . '</periodName>'
                . '<startDate>' . ($p['last_period_start'] ?? '') . '</startDate>'
                . '<endDate>' . ($p['last_period_end'] ?? '') . '</endDate>'
                . '</lastMembership>';
        }

        $groupIdTags = '';
        foreach ($p['group_ids'] ?? [] as $gid) {
            $groupIdTags .= "<groupId>{$gid}</groupId>";
        }

        $personsXml .= '<person>'
            . '<userId>' . ($p['user_id'] ?? '') . '</userId>'
            . '<firstName>' . ($p['first_name'] ?? '') . '</firstName>'
            . '<lastName>' . ($p['last_name'] ?? '') . '</lastName>'
            . '<email>' . ($p['email'] ?? '') . '</email>'
            . '<username>' . ($p['username'] ?? '') . '</username>'
            . '<phone>' . ($p['phone'] ?? '') . '</phone>'
            . '<streetAddr>' . ($p['address'] ?? '') . '</streetAddr>'
            . '<zip>' . ($p['postal_code'] ?? '') . '</zip>'
            . '<city>' . ($p['city'] ?? '') . '</city>'
            . '<dateOfBirth>' . ($p['date_of_birth'] ?? '') . '</dateOfBirth>'
            . '<mshipNumber>' . ($p['membership_number'] ?? '') . '</mshipNumber>'
            . '<isMember>' . (($p['is_member'] ?? false) ? '1' : '0') . '</isMember>'
            . $lastMship
            . '<thumb>' . ($p['thumb_url'] ?? '') . '</thumb>'
            . $groupIdTags
            . '</person>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<root>'
        . '<groups>' . $groupsXml . '</groups>'
        . '<persons>' . $personsXml . '</persons>'
        . '</root>';
}

function fakeCogWorksUsers(array $persons): void
{
    Http::fake([
        'minaaktiviteter.se/*' => Http::response(cogWorksXml($persons), 200),
    ]);
}

function samplePerson(array $overrides = []): array
{
    return array_merge([
        'user_id'          => '42',
        'first_name'       => 'Anna',
        'last_name'        => 'Svensson',
        'email'            => 'anna@example.com',
        'username'         => 'anna42',
        'phone'            => '0701234567',
        'address'          => 'Storgatan 1',
        'postal_code'      => '50001',
        'city'             => 'Borås',
        'date_of_birth'    => '1990-01-15',
        'membership_number'=> '100',
        'is_member'        => true,
    ], $overrides);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test('returns failure when CogWorks is not configured', function () {
    config(['services.cogworks.org_code' => '', 'services.cogworks.api_token' => '']);

    $exitCode = Artisan::call('import:cogworks');

    expect($exitCode)->toBe(1);
});

test('returns success with zero users', function () {
    configureCredentials();
    fakeCogWorksUsers([]);

    $exitCode = Artisan::call('import:cogworks');

    expect($exitCode)->toBe(0);
});

test('creates new user and member for unknown person', function () {
    configureCredentials();
    fakeCogWorksUsers([samplePerson()]);

    Artisan::call('import:cogworks');

    $user = User::where('cogworks_id', '42')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Anna Svensson')
        ->and($user->email)->toBe('anna@example.com')
        ->and($user->cogworks_username)->toBe('anna42');

    $member = Member::where('cogworks_id', '42')->first();
    expect($member)->not->toBeNull()
        ->and($member->first_name)->toBe('Anna')
        ->and($member->last_name)->toBe('Svensson')
        ->and($member->email)->toBe('anna@example.com')
        ->and($member->membership_number)->toBe('100')
        ->and($member->user_id)->toBe($user->id);
});

test('sets member status active when is_member is true', function () {
    configureCredentials();
    fakeCogWorksUsers([samplePerson(['is_member' => true])]);

    Artisan::call('import:cogworks');

    expect(Member::where('cogworks_id', '42')->value('status'))->toBe(Member::STATUS_ACTIVE);
});

test('sets member status inactive when is_member is false', function () {
    configureCredentials();
    fakeCogWorksUsers([samplePerson(['is_member' => false])]);

    Artisan::call('import:cogworks');

    expect(Member::where('cogworks_id', '42')->value('status'))->toBe(Member::STATUS_INACTIVE);
});

test('updates existing user matched by cogworks_id', function () {
    configureCredentials();

    $user = User::factory()->create(['cogworks_id' => '42', 'name' => 'Gammal Name']);

    fakeCogWorksUsers([samplePerson()]);
    Artisan::call('import:cogworks');

    expect($user->fresh()->name)->toBe('Anna Svensson');
});

test('links existing user by email when cogworks_id is null', function () {
    configureCredentials();

    $user = User::factory()->create(['email' => 'anna@example.com', 'cogworks_id' => null]);

    fakeCogWorksUsers([samplePerson()]);
    Artisan::call('import:cogworks');

    expect($user->fresh()->cogworks_id)->toBe('42');
});

test('creates member with null user_id on email conflict', function () {
    configureCredentials();

    // Different CogWorks user already owns this email
    User::factory()->create(['email' => 'anna@example.com', 'cogworks_id' => '99']);

    fakeCogWorksUsers([samplePerson()]);
    Artisan::call('import:cogworks');

    $member = Member::where('cogworks_id', '42')->first();
    expect($member)->not->toBeNull()
        ->and($member->user_id)->toBeNull();

    // Should not create a new user for the conflicting person
    expect(User::where('cogworks_id', '42')->exists())->toBeFalse();
});

test('skips person with no user_id', function () {
    configureCredentials();
    fakeCogWorksUsers([samplePerson(['user_id' => ''])]);

    Artisan::call('import:cogworks');

    expect(User::count())->toBe(0);
    expect(Member::count())->toBe(0);
});

test('skips person with no email and no existing account', function () {
    configureCredentials();
    fakeCogWorksUsers([samplePerson(['email' => ''])]);

    Artisan::call('import:cogworks');

    expect(User::count())->toBe(0);
    expect(Member::count())->toBe(0);
});

test('creates membership period when last_period_year is provided', function () {
    configureCredentials();
    fakeCogWorksUsers([samplePerson([
        'last_period_year'  => '2025',
        'last_period_start' => '2025-01-01',
        'last_period_end'   => '2025-12-31',
        'is_member'         => true,
    ])]);

    Artisan::call('import:cogworks');

    $member = Member::where('cogworks_id', '42')->first();
    $period = $member->membershipPeriods()->where('year', 2025)->first();

    expect($period)->not->toBeNull()
        ->and($period->status)->toBe(MembershipPeriod::STATUS_ACTIVE)
        ->and($period->activated_at->toDateString())->toBe('2025-01-01')
        ->and($period->expires_at->toDateString())->toBe('2025-12-31');
});

test('does not duplicate membership period on re-import', function () {
    configureCredentials();
    fakeCogWorksUsers([samplePerson(['last_period_year' => '2025'])]);

    Artisan::call('import:cogworks');
    Artisan::call('import:cogworks');

    $member = Member::where('cogworks_id', '42')->first();
    expect($member->membershipPeriods()->where('year', 2025)->count())->toBe(1);
});

test('does not create period when last_period_year is empty', function () {
    configureCredentials();
    fakeCogWorksUsers([samplePerson()]);

    Artisan::call('import:cogworks');

    $member = Member::where('cogworks_id', '42')->first();
    expect($member->membershipPeriods()->count())->toBe(0);
});

test('downloads avatar when thumb_url is provided', function () {
    configureCredentials();
    Storage::fake('public');

    Http::fake([
        'minaaktiviteter.se/*'  => Http::response(cogWorksXml([samplePerson(['thumb_url' => 'https://cdn.example.com/thumb.jpg'])]), 200),
        'cdn.example.com/*'     => Http::response('fake-image-bytes', 200),
    ]);

    Artisan::call('import:cogworks');

    $user = User::where('cogworks_id', '42')->first();
    expect($user->avatar_path)->not->toBeNull();
    Storage::disk('public')->assertExists($user->avatar_path);
});

test('skip-avatars flag skips avatar download', function () {
    configureCredentials();

    Http::fake([
        'minaaktiviteter.se/*' => Http::response(cogWorksXml([samplePerson(['thumb_url' => 'https://cdn.example.com/thumb.jpg'])]), 200),
    ]);

    Artisan::call('import:cogworks', ['--skip-avatars' => true]);

    $user = User::where('cogworks_id', '42')->first();
    expect($user->avatar_path)->toBeNull();
});

test('dry-run does not create users or members', function () {
    configureCredentials();
    fakeCogWorksUsers([samplePerson()]);

    Artisan::call('import:cogworks', ['--dry-run' => true]);

    expect(User::count())->toBe(0);
    expect(Member::count())->toBe(0);
});

test('dry-run does not update existing user', function () {
    configureCredentials();

    $user = User::factory()->create(['cogworks_id' => '42', 'name' => 'Gammal Name']);

    fakeCogWorksUsers([samplePerson()]);
    Artisan::call('import:cogworks', ['--dry-run' => true]);

    expect($user->fresh()->name)->toBe('Gammal Name');
});

test('dry-run returns success exit code', function () {
    configureCredentials();
    fakeCogWorksUsers([samplePerson()]);

    $exitCode = Artisan::call('import:cogworks', ['--dry-run' => true]);

    expect($exitCode)->toBe(0);
});

test('handles multiple persons in one import', function () {
    configureCredentials();
    fakeCogWorksUsers([
        samplePerson(['user_id' => '1', 'email' => 'user1@example.com', 'username' => 'user1', 'membership_number' => '1']),
        samplePerson(['user_id' => '2', 'email' => 'user2@example.com', 'username' => 'user2', 'membership_number' => '2']),
        samplePerson(['user_id' => '3', 'email' => 'user3@example.com', 'username' => 'user3', 'membership_number' => '3']),
    ]);

    Artisan::call('import:cogworks');

    expect(User::count())->toBe(3);
    expect(Member::count())->toBe(3);
});
