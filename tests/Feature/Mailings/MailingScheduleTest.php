<?php

use App\Models\Content\Mailing;
use App\Models\Content\MailingRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

// ── Scheduled activation ──────────────────────────────────────────────────────

test('scheduled mailing is no longer scheduled after its time has passed', function () {
    $mailing = Mailing::factory()->scheduled(now()->subMinute())->create();

    Artisan::call('mailings:process');

    expect($mailing->fresh()->status)->not->toBe(Mailing::STATUS_SCHEDULED);
});

test('scheduled mailing is not activated before scheduled_at', function () {
    $mailing = Mailing::factory()->scheduled(now()->addHour())->create();

    Artisan::call('mailings:process');

    expect($mailing->fresh()->status)->toBe(Mailing::STATUS_SCHEDULED);
});

test('activated scheduled mailing is processed in the same run', function () {
    Mail::fake();

    $mailing = Mailing::factory()->scheduled(now()->subMinute())->create();
    MailingRecipient::create(['mailing_id' => $mailing->id, 'email' => 'a@test.com']);

    Artisan::call('mailings:process');

    Mail::assertSent(\App\Mail\MailingMail::class, 1);
    expect($mailing->fresh()->status)->toBe(Mailing::STATUS_SENT);
});

// ── Cancel ────────────────────────────────────────────────────────────────────

test('cancel changes status from sending to cancelled', function () {
    $mailing = Mailing::factory()->sending()->create();

    $mailing->update(['status' => Mailing::STATUS_CANCELLED]);

    expect($mailing->fresh()->isCancelled())->toBeTrue();
});

test('cancelled mailing is not processed by command', function () {
    Mail::fake();

    $mailing = Mailing::factory()->create(['status' => Mailing::STATUS_CANCELLED]);
    MailingRecipient::create(['mailing_id' => $mailing->id, 'email' => 'a@test.com']);

    Artisan::call('mailings:process');

    Mail::assertNothingSent();
});

// ── Duplicate ─────────────────────────────────────────────────────────────────

test('duplicate creates a draft with same content', function () {
    $original = Mailing::factory()->sent()->create([
        'title'    => 'Original',
        'category' => 'newsletter',
        'subject'  => 'The subject',
        'body_html'=> '<p>Body</p>',
    ]);

    $copy = Mailing::create([
        'mailing_template_id' => $original->mailing_template_id,
        'title'               => $original->title . ' (kopia)',
        'category'            => $original->category,
        'subject'             => $original->subject,
        'body_html'           => $original->body_html,
        'status'              => Mailing::STATUS_DRAFT,
        'created_by'          => $original->created_by,
    ]);

    expect($copy->title)->toBe('Original (kopia)')
        ->and($copy->status)->toBe(Mailing::STATUS_DRAFT)
        ->and($copy->subject)->toBe('The subject')
        ->and($copy->category)->toBe('newsletter');
});

test('duplicate does not copy recipients', function () {
    $original = Mailing::factory()->sent()->create();
    MailingRecipient::create(['mailing_id' => $original->id, 'email' => 'a@test.com']);

    $copy = Mailing::create([
        'title'      => $original->title . ' (kopia)',
        'subject'    => $original->subject,
        'body_html'  => $original->body_html,
        'status'     => Mailing::STATUS_DRAFT,
        'created_by' => $original->created_by,
    ]);

    expect($copy->recipients()->count())->toBe(0);
});

// ── Stats ─────────────────────────────────────────────────────────────────────

test('stats are computed correctly for a sent mailing', function () {
    $mailing = Mailing::factory()->sent()->create();

    MailingRecipient::create(['mailing_id' => $mailing->id, 'email' => 'a@test.com', 'sent_at' => now()]);
    MailingRecipient::create(['mailing_id' => $mailing->id, 'email' => 'b@test.com', 'sent_at' => now()]);
    MailingRecipient::create(['mailing_id' => $mailing->id, 'email' => 'c@test.com', 'sent_at' => now()]);

    \App\Models\Content\MailLog::create([
        'mailing_id'      => $mailing->id,
        'recipient_email' => 'a@test.com',
        'subject'         => 'Test',
        'delivery_status' => \App\Models\Content\MailLog::STATUS_SENT,
        'open_count'      => 2,
    ]);
    \App\Models\Content\MailLog::create([
        'mailing_id'      => $mailing->id,
        'recipient_email' => 'b@test.com',
        'subject'         => 'Test',
        'delivery_status' => \App\Models\Content\MailLog::STATUS_SENT,
        'open_count'      => 0,
    ]);
    // c@test.com was skipped (no log entry)

    $total     = \App\Models\Content\MailingRecipient::where('mailing_id', $mailing->id)->count();
    $logs      = \App\Models\Content\MailLog::where('mailing_id', $mailing->id)->get();
    $delivered = $logs->where('delivery_status', \App\Models\Content\MailLog::STATUS_SENT)->count();
    $opened    = $logs->where('open_count', '>', 0)->count();
    $skipped   = max(0, $total - $delivered);

    expect($total)->toBe(3)
        ->and($delivered)->toBe(2)
        ->and($opened)->toBe(1)
        ->and($skipped)->toBe(1)
        ->and($delivered > 0 ? (int) round($opened / $delivered * 100) : 0)->toBe(50);
});
