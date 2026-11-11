<?php

use App\Models\ImapImportJob;
use App\Models\Membership\Member;
use App\Models\User;
use App\Models\WorkspaceAccount;
use App\Services\ImapImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

// Member::google_workspace_email was migrated to the polymorphic
// workspace_accounts table (see 2026_05_28_000003_create_workspace_accounts_table) —
// the "workspace email" a member's IMAP-import access gates on now lives there.
function imapUser(?string $workspaceEmail = 'anna@armagedon.se'): User
{
    $user   = User::factory()->create();
    $member = Member::factory()->create(['user_id' => $user->id]);

    if ($workspaceEmail !== null) {
        WorkspaceAccount::create([
            'email'            => $workspaceEmail,
            'accountable_type' => 'member',
            'accountable_id'   => $member->id,
        ]);
    }

    return $user;
}

function existingImapJob(User $user, array $attrs = []): ImapImportJob
{
    return ImapImportJob::create(array_merge([
        'user_id'    => $user->id,
        'host'       => 'mail.example.com',
        'port'       => 993,
        'encryption' => 'ssl',
        'username'   => 'anna@example.com',
        'password'   => 'secret',
        'status'     => ImapImportJob::STATUS_PENDING,
    ], $attrs));
}

// ── Mount / auth guards ───────────────────────────────────────────────────────

test('mount returns 403 for guest', function () {
    Volt::test('settings.imap-import')
        ->assertStatus(403);
});

test('mount returns 403 for user without workspace email', function () {
    $user = imapUser(workspaceEmail: null);

    $this->actingAs($user);

    Volt::test('settings.imap-import')
        ->assertStatus(403);
});

test('mount returns 403 for user without member record', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('settings.imap-import')
        ->assertStatus(403);
});

test('mount succeeds for user with workspace email', function () {
    $user = imapUser();
    $this->actingAs($user);

    Volt::test('settings.imap-import')
        ->assertOk()
        ->assertSet('verified', false)
        ->assertSet('job', null);
});

test('mount loads existing pending job', function () {
    $user = imapUser();
    $job  = existingImapJob($user);
    $this->actingAs($user);

    Volt::test('settings.imap-import')
        ->assertSet('job.id', $job->id);
});

test('mount loads latest job when multiple exist', function () {
    $user   = imapUser();
    $old    = existingImapJob($user, ['status' => ImapImportJob::STATUS_COMPLETED]);
    $recent = existingImapJob($user, ['status' => ImapImportJob::STATUS_PENDING]);

    // created_at is not fillable — set it directly so latest() returns $recent
    \Illuminate\Support\Facades\DB::table('imap_import_jobs')
        ->where('id', $old->id)
        ->update(['created_at' => now()->subMinutes(5)]);

    $this->actingAs($user);

    Volt::test('settings.imap-import')
        ->assertSet('job.id', $recent->id);
});

// ── test() action ─────────────────────────────────────────────────────────────

test('test action calls service with provided credentials', function () {
    $user = imapUser();
    $this->actingAs($user);

    $mock = Mockery::mock(ImapImportService::class);
    $mock->shouldReceive('testConnection')
        ->once()
        ->with('mail.example.com', 993, 'ssl', 'anna@example.com', 'secret')
        ->andReturn(42);
    app()->instance(ImapImportService::class, $mock);

    Volt::test('settings.imap-import')
        ->set('host', 'mail.example.com')
        ->set('port', '993')
        ->set('encryption', 'ssl')
        ->set('username', 'anna@example.com')
        ->set('password', 'secret')
        ->call('test')
        ->assertSet('verified', true)
        ->assertSet('verifiedCount', 42)
        ->assertSet('testError', '');
});

test('test action sets testError on service exception', function () {
    $user = imapUser();
    $this->actingAs($user);

    $mock = Mockery::mock(ImapImportService::class);
    $mock->shouldReceive('testConnection')
        ->once()
        ->andThrow(new \RuntimeException('Servern nekade anslutningen.'));
    app()->instance(ImapImportService::class, $mock);

    Volt::test('settings.imap-import')
        ->set('host', 'mail.example.com')
        ->set('port', '993')
        ->set('encryption', 'ssl')
        ->set('username', 'anna@example.com')
        ->set('password', 'secret')
        ->call('test')
        ->assertSet('verified', false)
        ->assertSet('testError', 'Servern nekade anslutningen.');
});

test('test action validates required fields', function () {
    $user = imapUser();
    $this->actingAs($user);

    Volt::test('settings.imap-import')
        ->call('test')
        ->assertHasErrors(['host', 'username', 'password']);
});

test('test action validates port range', function () {
    $user = imapUser();
    $this->actingAs($user);

    Volt::test('settings.imap-import')
        ->set('host', 'mail.example.com')
        ->set('port', '99999')
        ->set('username', 'u')
        ->set('password', 'p')
        ->call('test')
        ->assertHasErrors(['port']);
});

// ── queue() action ────────────────────────────────────────────────────────────

test('queue action creates job and clears form', function () {
    $user = imapUser();
    $this->actingAs($user);

    Volt::test('settings.imap-import')
        ->set('host', 'mail.example.com')
        ->set('port', '993')
        ->set('encryption', 'ssl')
        ->set('username', 'anna@example.com')
        ->set('password', 'secret')
        ->set('verified', true)
        ->call('queue');

    $job = ImapImportJob::where('user_id', $user->id)->first();
    expect($job)->not->toBeNull()
        ->and($job->host)->toBe('mail.example.com')
        ->and($job->port)->toBe(993)
        ->and($job->username)->toBe('anna@example.com')
        ->and($job->status)->toBe(ImapImportJob::STATUS_PENDING);
});

test('queue action encrypts password in database', function () {
    $user = imapUser();
    $this->actingAs($user);

    Volt::test('settings.imap-import')
        ->set('host', 'mail.example.com')
        ->set('port', '993')
        ->set('encryption', 'ssl')
        ->set('username', 'anna@example.com')
        ->set('password', 'supersecret')
        ->set('verified', true)
        ->call('queue');

    $raw = \Illuminate\Support\Facades\DB::table('imap_import_jobs')
        ->where('user_id', $user->id)
        ->value('password');

    expect($raw)->not->toBe('supersecret');
});

test('queue action clears password from component state', function () {
    $user = imapUser();
    $this->actingAs($user);

    Volt::test('settings.imap-import')
        ->set('host', 'mail.example.com')
        ->set('port', '993')
        ->set('encryption', 'ssl')
        ->set('username', 'anna@example.com')
        ->set('password', 'secret')
        ->set('verified', true)
        ->call('queue')
        ->assertSet('password', '')
        ->assertSet('verified', false)
        ->assertSet('host', '');
});

test('queue action is blocked when not verified', function () {
    $user = imapUser();
    $this->actingAs($user);

    Volt::test('settings.imap-import')
        ->set('host', 'mail.example.com')
        ->set('username', 'anna@example.com')
        ->set('password', 'secret')
        ->call('queue')
        ->assertStatus(403);

    expect(ImapImportJob::count())->toBe(0);
});

test('queue action deletes previous failed job before creating new one', function () {
    $user = imapUser();
    $old  = existingImapJob($user, ['status' => ImapImportJob::STATUS_FAILED]);
    $this->actingAs($user);

    Volt::test('settings.imap-import')
        ->set('host', 'new.example.com')
        ->set('port', '993')
        ->set('encryption', 'ssl')
        ->set('username', 'anna@example.com')
        ->set('password', 'secret')
        ->set('verified', true)
        ->call('queue');

    expect(ImapImportJob::where('id', $old->id)->exists())->toBeFalse()
        ->and(ImapImportJob::count())->toBe(1);
});

// ── cancel() action ───────────────────────────────────────────────────────────

test('cancel deletes a pending job', function () {
    $user = imapUser();
    existingImapJob($user, ['status' => ImapImportJob::STATUS_PENDING]);
    $this->actingAs($user);

    Volt::test('settings.imap-import')
        ->call('cancel')
        ->assertSet('job', null);

    expect(ImapImportJob::count())->toBe(0);
});

test('cancel deletes a failed job', function () {
    $user = imapUser();
    existingImapJob($user, ['status' => ImapImportJob::STATUS_FAILED]);
    $this->actingAs($user);

    Volt::test('settings.imap-import')
        ->call('cancel')
        ->assertSet('job', null);

    expect(ImapImportJob::count())->toBe(0);
});

test('cancel does not delete a running job', function () {
    $user = imapUser();
    existingImapJob($user, [
        'status'     => ImapImportJob::STATUS_RUNNING,
        'started_at' => now(),
    ]);
    $this->actingAs($user);

    Volt::test('settings.imap-import')
        ->call('cancel');

    expect(ImapImportJob::count())->toBe(1);
});

// ── polling ───────────────────────────────────────────────────────────────────

test('polling returns true when job is pending', function () {
    $user = imapUser();
    existingImapJob($user, ['status' => ImapImportJob::STATUS_PENDING]);
    $this->actingAs($user);

    $component = Volt::test('settings.imap-import');
    expect($component->instance()->polling())->toBeTrue();
});

test('polling returns true when job is running', function () {
    $user = imapUser();
    existingImapJob($user, ['status' => ImapImportJob::STATUS_RUNNING, 'started_at' => now()]);
    $this->actingAs($user);

    $component = Volt::test('settings.imap-import');
    expect($component->instance()->polling())->toBeTrue();
});

test('polling returns false when job is completed', function () {
    $user = imapUser();
    existingImapJob($user, ['status' => ImapImportJob::STATUS_COMPLETED]);
    $this->actingAs($user);

    $component = Volt::test('settings.imap-import');
    expect($component->instance()->polling())->toBeFalse();
});

test('polling returns false when there is no job', function () {
    $user = imapUser();
    $this->actingAs($user);

    $component = Volt::test('settings.imap-import');
    expect($component->instance()->polling())->toBeFalse();
});
