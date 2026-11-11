<?php

use App\Mail\MailingMail;
use App\Models\Content\Mailing;
use App\Models\Content\MailingRecipient;
use App\Models\Membership\Member;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(fn () => Mail::fake());

test('command exits cleanly when no mailings are sending', function () {
    Mailing::factory()->draft()->create();

    $code = Artisan::call('mailings:process');

    expect($code)->toBe(0);
    Mail::assertNothingSent();
});

test('sends pending recipients and marks sent_at', function () {
    $mailing = Mailing::factory()->sending()->create();
    MailingRecipient::create(['mailing_id' => $mailing->id, 'email' => 'a@test.com']);
    MailingRecipient::create(['mailing_id' => $mailing->id, 'email' => 'b@test.com']);

    Artisan::call('mailings:process');

    Mail::assertSent(MailingMail::class, 2);

    expect(MailingRecipient::where('mailing_id', $mailing->id)->whereNotNull('sent_at')->count())->toBe(2);
});

test('finalizes mailing to sent when all recipients done', function () {
    $mailing = Mailing::factory()->sending()->create();
    MailingRecipient::create(['mailing_id' => $mailing->id, 'email' => 'x@test.com']);

    Artisan::call('mailings:process');

    expect($mailing->fresh()->status)->toBe(Mailing::STATUS_SENT)
        ->and($mailing->fresh()->sent_at)->not->toBeNull()
        ->and($mailing->fresh()->recipients_count)->toBe(1);
});

test('only sends up to chunk_size per run', function () {
    SystemSetting::set('mailings.chunk_size', 2);

    $mailing = Mailing::factory()->sending()->create();
    foreach (['a@t.com', 'b@t.com', 'c@t.com'] as $email) {
        MailingRecipient::create(['mailing_id' => $mailing->id, 'email' => $email]);
    }

    Artisan::call('mailings:process');

    Mail::assertSent(MailingMail::class, 2);
    expect($mailing->fresh()->status)->toBe(Mailing::STATUS_SENDING);

    // Second run sends the remainder
    Artisan::call('mailings:process');

    Mail::assertSent(MailingMail::class, 3);
    expect($mailing->fresh()->status)->toBe(Mailing::STATUS_SENT);
});

test('skips already sent recipients on subsequent runs', function () {
    $mailing = Mailing::factory()->sending()->create();
    MailingRecipient::create(['mailing_id' => $mailing->id, 'email' => 'done@test.com', 'sent_at' => now()]);
    MailingRecipient::create(['mailing_id' => $mailing->id, 'email' => 'pending@test.com']);

    Artisan::call('mailings:process');

    Mail::assertSent(MailingMail::class, 1);
    Mail::assertSent(MailingMail::class, fn ($m) => $m->recipient->email === 'pending@test.com');
});

test('resolves member variables in subject', function () {
    $member  = Member::factory()->active()->create(['first_name' => 'Kalle', 'email' => 'kalle@test.com']);
    $mailing = Mailing::factory()->sending()->create(['subject' => 'Hej {{fornamn}}!']);
    MailingRecipient::create(['mailing_id' => $mailing->id, 'email' => 'kalle@test.com', 'member_id' => $member->id]);

    Artisan::call('mailings:process');

    Mail::assertSent(MailingMail::class, fn ($mail) =>
        $mail->envelope()->subject === 'Hej Kalle!'
    );
});

test('does not resend to mailing with status draft', function () {
    $mailing = Mailing::factory()->draft()->create();
    MailingRecipient::create(['mailing_id' => $mailing->id, 'email' => 'x@test.com']);

    Artisan::call('mailings:process');

    Mail::assertNothingSent();
});

test('handles multiple concurrent mailings independently', function () {
    $m1 = Mailing::factory()->sending()->create();
    $m2 = Mailing::factory()->sending()->create();
    MailingRecipient::create(['mailing_id' => $m1->id, 'email' => 'one@test.com']);
    MailingRecipient::create(['mailing_id' => $m2->id, 'email' => 'two@test.com']);

    Artisan::call('mailings:process');

    Mail::assertSent(MailingMail::class, 2);
    expect($m1->fresh()->status)->toBe(Mailing::STATUS_SENT)
        ->and($m2->fresh()->status)->toBe(Mailing::STATUS_SENT);
});
