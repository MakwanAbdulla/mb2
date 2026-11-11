<?php

use App\Models\Membership\Member;
use App\Models\User;
use App\Models\WorkspaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function mockSocialiteUser(string $email, string $googleId, string $name = 'Test User'): void
{
    $socialUser = Mockery::mock(SocialiteUser::class);
    $socialUser->shouldReceive('getEmail')->andReturn($email);
    $socialUser->shouldReceive('getId')->andReturn($googleId);
    $socialUser->shouldReceive('getName')->andReturn($name);

    $provider = Mockery::mock(\Laravel\Socialite\Two\GoogleProvider::class);
    $provider->shouldReceive('user')->andReturn($socialUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

// ── Match by email ────────────────────────────────────────────────────────────

test('existing user matched by email gets google_id set', function () {
    $user = User::factory()->create(['email' => 'john@example.com', 'google_id' => null]);

    mockSocialiteUser('john@example.com', 'gid-123');

    $this->get(route('auth.google.callback'));

    expect($user->fresh()->google_id)->toBe('gid-123');
    $this->assertAuthenticatedAs($user);
});

// ── Match by google_id ────────────────────────────────────────────────────────

test('existing user matched by google_id even if email differs', function () {
    $user = User::factory()->create(['email' => 'old@example.com', 'google_id' => 'gid-456']);

    mockSocialiteUser('new@example.com', 'gid-456');

    $this->get(route('auth.google.callback'));

    $this->assertAuthenticatedAs($user);
});

// ── Match by google_workspace_email ───────────────────────────────────────────

test('workspace email matches member and logs in existing user', function () {
    $user   = User::factory()->create(['email' => 'john@gmail.com', 'google_id' => 'gid-gmail']);
    $member = Member::factory()->create(['user_id' => $user->id]);
    WorkspaceAccount::create([
        'email'             => 'john@armagedon.se',
        'accountable_type'  => 'member',
        'accountable_id'    => $member->id,
    ]);

    mockSocialiteUser('john@armagedon.se', 'gid-workspace', 'John Doe');

    $this->get(route('auth.google.callback'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->google_id)->toBe('gid-workspace');
});

test('workspace match does not create a new user', function () {
    $user   = User::factory()->create(['email' => 'john@gmail.com', 'google_id' => 'gid-gmail']);
    $member = Member::factory()->create(['user_id' => $user->id]);
    WorkspaceAccount::create([
        'email'             => 'john@armagedon.se',
        'accountable_type'  => 'member',
        'accountable_id'    => $member->id,
    ]);

    mockSocialiteUser('john@armagedon.se', 'gid-workspace');

    $this->get(route('auth.google.callback'));

    expect(User::count())->toBe(1);
});

test('workspace email on member without user_id does not match', function () {
    $member = Member::factory()->create(['user_id' => null]);
    WorkspaceAccount::create([
        'email'             => 'child@armagedon.se',
        'accountable_type'  => 'member',
        'accountable_id'    => $member->id,
    ]);

    mockSocialiteUser('child@armagedon.se', 'gid-child');

    $this->get(route('auth.google.callback'));

    // No existing user found → new account created
    expect(User::where('email', 'child@armagedon.se')->exists())->toBeTrue();
});

// ── Match by google_personal_email ───────────────────────────────────────────

test('personal email matches existing user on login', function () {
    $user = User::factory()->create([
        'email'                => 'john@armagedon.se',
        'google_personal_email' => 'john@gmail.com',
    ]);

    mockSocialiteUser('john@gmail.com', 'gid-gmail');

    $this->get(route('auth.google.callback'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->google_id)->toBe('gid-gmail');
});

// ── Link flow ─────────────────────────────────────────────────────────────────

test('link flow stores google_personal_email on authenticated user', function () {
    $user = User::factory()->create(['email' => 'john@armagedon.se']);

    mockSocialiteUser('john@gmail.com', 'gid-gmail');

    $this->actingAs($user)
        ->withSession(['google_link_intent' => true])
        ->get(route('auth.google.callback'));

    expect($user->fresh()->google_personal_email)->toBe('john@gmail.com');
});

test('link flow rejects primary email', function () {
    $user = User::factory()->create(['email' => 'john@armagedon.se']);

    mockSocialiteUser('john@armagedon.se', 'gid-ws');

    $this->actingAs($user)
        ->withSession(['google_link_intent' => true])
        ->get(route('auth.google.callback'));

    expect($user->fresh()->google_personal_email)->toBeNull();
});

test('link flow rejects email already claimed by another user', function () {
    User::factory()->create(['email' => 'taken@gmail.com']);
    $user = User::factory()->create(['email' => 'john@armagedon.se']);

    mockSocialiteUser('taken@gmail.com', 'gid-taken');

    $this->actingAs($user)
        ->withSession(['google_link_intent' => true])
        ->get(route('auth.google.callback'));

    expect($user->fresh()->google_personal_email)->toBeNull();
});

test('unlink removes google_personal_email and clears notification_email if it matched', function () {
    $user = User::factory()->create([
        'email'                 => 'john@armagedon.se',
        'google_personal_email' => 'john@gmail.com',
        'notification_email'    => 'john@gmail.com',
    ]);

    $this->actingAs($user)->delete(route('auth.google.unlink'));

    expect($user->fresh()->google_personal_email)->toBeNull()
        ->and($user->fresh()->notification_email)->toBeNull();
});

// ── notificationEmail() ───────────────────────────────────────────────────────

test('notificationEmail returns primary email when no preference set', function () {
    $user = User::factory()->create(['email' => 'john@armagedon.se', 'notification_email' => null]);

    expect($user->notificationEmail())->toBe('john@armagedon.se');
});

test('notificationEmail returns notification_email when set', function () {
    $user = User::factory()->create([
        'email'              => 'john@armagedon.se',
        'notification_email' => 'john@gmail.com',
    ]);

    expect($user->notificationEmail())->toBe('john@gmail.com');
});

test('Member notificationEmail delegates to user', function () {
    $user   = User::factory()->create(['email' => 'john@armagedon.se', 'notification_email' => 'john@gmail.com']);
    $member = Member::factory()->create(['user_id' => $user->id, 'email' => 'john@armagedon.se']);

    expect($member->notificationEmail())->toBe('john@gmail.com');
});

test('Member notificationEmail falls back to member email without user', function () {
    $member = Member::factory()->create(['user_id' => null, 'email' => 'child@example.com']);

    expect($member->notificationEmail())->toBe('child@example.com');
});

// ── New user created when no match ───────────────────────────────────────────

test('unknown email creates new user', function () {
    mockSocialiteUser('new@example.com', 'gid-new', 'New Person');

    $this->get(route('auth.google.callback'));

    $user = User::where('email', 'new@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->google_id)->toBe('gid-new');
    $this->assertAuthenticatedAs($user);
});
