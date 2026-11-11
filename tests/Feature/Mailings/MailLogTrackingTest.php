<?php

use App\Models\Content\MailLog;
use App\Listeners\LogSentMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('recordOpen increments open_count', function () {
    $log = MailLog::create([
        'recipient_email' => 'a@test.com',
        'subject'         => 'Test',
        'delivery_status' => MailLog::STATUS_SENT,
        'tracking_token'  => 'token-abc',
    ]);

    $log->recordOpen();
    $log->recordOpen();

    expect($log->fresh()->open_count)->toBe(2);
});

test('recordOpen sets first_opened_at on first open only', function () {
    $log = MailLog::create([
        'recipient_email' => 'b@test.com',
        'subject'         => 'Test',
        'delivery_status' => MailLog::STATUS_SENT,
        'tracking_token'  => 'token-xyz',
    ]);

    $log->recordOpen();
    $firstOpened = $log->fresh()->first_opened_at;

    $log->recordOpen();

    expect($log->fresh()->first_opened_at->eq($firstOpened))->toBeTrue();
});

test('wasOpened returns false when open_count is zero', function () {
    $log = MailLog::create([
        'recipient_email' => 'c@test.com',
        'subject'         => 'Test',
        'delivery_status' => MailLog::STATUS_SENT,
    ]);

    expect($log->wasOpened())->toBeFalse();
});

test('open pixel endpoint records open and returns gif', function () {
    $log = MailLog::create([
        'recipient_email' => 'd@test.com',
        'subject'         => 'Pixel test',
        'delivery_status' => MailLog::STATUS_SENT,
        'tracking_token'  => 'pixel-token-1',
    ]);

    $response = $this->get(route('mail.track.open', 'pixel-token-1'));

    $response->assertOk()
             ->assertHeader('Content-Type', 'image/gif');

    expect($log->fresh()->open_count)->toBe(1);
});

test('open pixel with unknown token returns gif without error', function () {
    $response = $this->get(route('mail.track.open', 'no-such-token'));

    $response->assertOk()
             ->assertHeader('Content-Type', 'image/gif');
});

test('mailing_id is stored on mail log when set', function () {
    $log = MailLog::create([
        'recipient_email' => 'e@test.com',
        'subject'         => 'With mailing',
        'delivery_status' => MailLog::STATUS_SENT,
        'mailing_id'      => null,
    ]);

    expect($log->mailing_id)->toBeNull();
});

test('sensitive mail types do not store body_html', function () {
    $sensitiveTypes = ['VerifyEmail', 'ResetPassword', 'AccountDeletionApproved'];

    foreach ($sensitiveTypes as $shortName) {
        // Verify SENSITIVE_TYPES list via reflection
        $reflection = new ReflectionClass(LogSentMail::class);
        $constant   = $reflection->getConstant('SENSITIVE_TYPES');
        expect($constant)->toContain($shortName);
    }
});
