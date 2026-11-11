<?php

use App\Models\ImapImportJob;
use App\Models\User;
use App\Services\ImapImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function imapJob(array $attrs = []): ImapImportJob
{
    $user = User::factory()->create();
    return ImapImportJob::create(array_merge([
        'user_id'    => $user->id,
        'host'       => 'mail.example.com',
        'port'       => 993,
        'encryption' => 'ssl',
        'username'   => 'user@example.com',
        'password'   => 'secret',
        'status'     => ImapImportJob::STATUS_PENDING,
    ], $attrs));
}

function mockImport(string $finalStatus = ImapImportJob::STATUS_COMPLETED): void
{
    $mock = Mockery::mock(ImapImportService::class);
    $mock->shouldReceive('import')->once()->andReturnUsing(function (ImapImportJob $job) use ($finalStatus) {
        $job->update(['status' => $finalStatus, 'messages_imported' => 5]);
    });
    app()->instance(ImapImportService::class, $mock);
}

// ── Model — status helpers ────────────────────────────────────────────────────

test('isPending returns true for pending job', function () {
    $job = imapJob(['status' => ImapImportJob::STATUS_PENDING]);
    expect($job->isPending())->toBeTrue()
        ->and($job->isRunning())->toBeFalse()
        ->and($job->isCompleted())->toBeFalse()
        ->and($job->isFailed())->toBeFalse();
});

test('isRunning returns true for running job', function () {
    $job = imapJob(['status' => ImapImportJob::STATUS_RUNNING]);
    expect($job->isRunning())->toBeTrue()
        ->and($job->isPending())->toBeFalse();
});

test('isCompleted returns true for completed job', function () {
    $job = imapJob(['status' => ImapImportJob::STATUS_COMPLETED]);
    expect($job->isCompleted())->toBeTrue();
});

test('isFailed returns true for failed job', function () {
    $job = imapJob(['status' => ImapImportJob::STATUS_FAILED]);
    expect($job->isFailed())->toBeTrue();
});

// ── Model — folder progress ───────────────────────────────────────────────────

test('lastUidFor returns 0 for unknown folder', function () {
    $job = imapJob();
    expect($job->lastUidFor('INBOX'))->toBe(0);
});

test('markFolderProgress persists uid for folder', function () {
    $job = imapJob();
    $job->markFolderProgress('INBOX', 42);

    expect($job->fresh()->lastUidFor('INBOX'))->toBe(42);
});

test('markFolderProgress tracks multiple folders independently', function () {
    $job = imapJob();
    $job->markFolderProgress('INBOX', 10);
    $job->markFolderProgress('Sent', 20);

    $fresh = $job->fresh();
    expect($fresh->lastUidFor('INBOX'))->toBe(10)
        ->and($fresh->lastUidFor('Sent'))->toBe(20);
});

test('markFolderProgress updates existing uid', function () {
    $job = imapJob();
    $job->markFolderProgress('INBOX', 10);
    $job->markFolderProgress('INBOX', 25);

    expect($job->fresh()->lastUidFor('INBOX'))->toBe(25);
});

// ── Model — isStuck ───────────────────────────────────────────────────────────

test('isStuck returns false for pending job', function () {
    $job = imapJob(['status' => ImapImportJob::STATUS_PENDING]);
    expect($job->isStuck())->toBeFalse();
});

test('isStuck returns false for running job started recently', function () {
    $job = imapJob(['status' => ImapImportJob::STATUS_RUNNING, 'started_at' => now()->subMinutes(30)]);
    expect($job->isStuck())->toBeFalse();
});

test('isStuck returns true for running job started over 2 hours ago', function () {
    $job = imapJob(['status' => ImapImportJob::STATUS_RUNNING, 'started_at' => now()->subHours(3)]);
    expect($job->isStuck())->toBeTrue();
});

test('isStuck returns false for completed job regardless of age', function () {
    $job = imapJob(['status' => ImapImportJob::STATUS_COMPLETED, 'started_at' => now()->subHours(5)]);
    expect($job->isStuck())->toBeFalse();
});

// ── Model — password encryption ───────────────────────────────────────────────

test('password is stored encrypted and decrypted on access', function () {
    $job = imapJob(['password' => 'geheim123']);

    // Raw DB value should not be plaintext
    $raw = \Illuminate\Support\Facades\DB::table('imap_import_jobs')->where('id', $job->id)->value('password');
    expect($raw)->not->toBe('geheim123');

    // Accessed via model it should be plaintext
    expect($job->fresh()->password)->toBe('geheim123');
});

// ── Command — basic scheduling ────────────────────────────────────────────────

test('command returns success when there are no pending jobs', function () {
    $exit = Artisan::call('mail:import-imap');
    expect($exit)->toBe(0);
});

test('command picks up oldest pending job and calls import', function () {
    imapJob(['created_at' => now()->subMinutes(10)]);
    $oldest = imapJob(['created_at' => now()->subMinutes(20)]);
    $oldest->update(['created_at' => now()->subMinutes(20)]);

    mockImport(ImapImportJob::STATUS_COMPLETED);

    Artisan::call('mail:import-imap');

    // The service mock was called once — verified by Mockery expectations above.
    // The job processed should end up completed.
    expect(ImapImportJob::where('status', ImapImportJob::STATUS_COMPLETED)->count())->toBe(1);
});

test('command returns success when job completes', function () {
    imapJob();
    mockImport(ImapImportJob::STATUS_COMPLETED);

    $exit = Artisan::call('mail:import-imap');
    expect($exit)->toBe(0);
});

test('command returns failure when job fails', function () {
    imapJob();
    mockImport(ImapImportJob::STATUS_FAILED);

    $exit = Artisan::call('mail:import-imap');
    expect($exit)->toBe(1);
});

test('command skips when a job is already running', function () {
    imapJob(['status' => ImapImportJob::STATUS_RUNNING, 'started_at' => now()]);
    imapJob(['status' => ImapImportJob::STATUS_PENDING]);

    $mock = Mockery::mock(ImapImportService::class);
    $mock->shouldNotReceive('import');
    app()->instance(ImapImportService::class, $mock);

    $exit = Artisan::call('mail:import-imap');
    expect($exit)->toBe(0);
});

test('command resets stuck jobs to pending before checking for running', function () {
    $stuck = imapJob([
        'status'     => ImapImportJob::STATUS_RUNNING,
        'started_at' => now()->subHours(3),
    ]);

    mockImport(ImapImportJob::STATUS_COMPLETED);

    Artisan::call('mail:import-imap');

    // Stuck job was reset to pending then picked up and completed
    expect($stuck->fresh()->status)->toBe(ImapImportJob::STATUS_COMPLETED);
});

test('command does not reset recently started running jobs', function () {
    $running = imapJob([
        'status'     => ImapImportJob::STATUS_RUNNING,
        'started_at' => now()->subMinutes(10),
    ]);

    $mock = Mockery::mock(ImapImportService::class);
    $mock->shouldNotReceive('import');
    app()->instance(ImapImportService::class, $mock);

    Artisan::call('mail:import-imap');

    expect($running->fresh()->status)->toBe(ImapImportJob::STATUS_RUNNING);
});

// ── Command — --job flag ──────────────────────────────────────────────────────

test('--job option runs a specific job by id', function () {
    $job = imapJob();
    mockImport(ImapImportJob::STATUS_COMPLETED);

    $exit = Artisan::call('mail:import-imap', ['--job' => $job->id]);
    expect($exit)->toBe(0);
});

test('--job option returns failure for unknown id', function () {
    $exit = Artisan::call('mail:import-imap', ['--job' => 9999]);
    expect($exit)->toBe(1);
});
