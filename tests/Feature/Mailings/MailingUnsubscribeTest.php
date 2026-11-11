<?php

use App\Models\Content\Mailing;
use App\Models\Content\MailingCategoryOptout;
use App\Models\Content\MailingRecipient;
use App\Models\Content\MailingUnsubscribe;
use App\Models\Membership\Member;
use App\Support\NotificationCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

// ── Model ──────────────────────────────────────────────────────────────────────

test('hasUnsubscribed returns false for unknown email', function () {
    expect(MailingUnsubscribe::hasUnsubscribed('unknown@test.com'))->toBeFalse();
});

test('record creates an unsubscribe entry', function () {
    MailingUnsubscribe::record('orjan@test.com');

    expect(MailingUnsubscribe::hasUnsubscribed('orjan@test.com'))->toBeTrue();
});

test('record is case-insensitive', function () {
    MailingUnsubscribe::record('ORJAN@TEST.COM');

    expect(MailingUnsubscribe::hasUnsubscribed('orjan@test.com'))->toBeTrue();
});

test('record is idempotent — does not throw on duplicate', function () {
    MailingUnsubscribe::record('a@test.com');
    MailingUnsubscribe::record('a@test.com');

    expect(MailingUnsubscribe::where('email', 'a@test.com')->count())->toBe(1);
});

// ── Controller ─────────────────────────────────────────────────────────────────

test('unsubscribe show page requires valid signature', function () {
    $mailing = Mailing::factory()->sent()->create();
    $email   = base64_encode('test@test.com');

    $this->get(route('mailing.unsubscribe', ['mailing' => $mailing->id, 'email' => $email]))
         ->assertForbidden();
});

test('unsubscribe show page renders with valid signature', function () {
    $mailing = Mailing::factory()->sent()->create();
    $url = URL::signedRoute('mailing.unsubscribe', [
        'mailing' => $mailing->id,
        'email'   => base64_encode('test@test.com'),
    ]);

    $this->get($url)->assertOk()->assertSee('test@test.com');
});

test('unsubscribe show page shows already-unsubscribed state', function () {
    $mailing = Mailing::factory()->sent()->create();
    MailingUnsubscribe::record('already@test.com');

    $url = URL::signedRoute('mailing.unsubscribe', [
        'mailing' => $mailing->id,
        'email'   => base64_encode('already@test.com'),
    ]);

    $this->get($url)->assertOk()->assertSee('Redan avprenumererad');
});

test('unsubscribe confirm stores record and shows success', function () {
    $mailing = Mailing::factory()->sent()->create();
    $email   = 'confirm@test.com';
    $encoded = base64_encode($email);

    $url = URL::signedRoute('mailing.unsubscribe.confirm', [
        'mailing' => $mailing->id,
        'email'   => $encoded,
    ]);

    $this->post($url)->assertOk()->assertSee($email);

    expect(MailingUnsubscribe::hasUnsubscribed($email))->toBeTrue();
});

test('unsubscribe confirm links to member if email matches', function () {
    $member  = Member::factory()->create(['email' => 'member@test.com']);
    $mailing = Mailing::factory()->sent()->create();
    $encoded = base64_encode('member@test.com');

    $url = URL::signedRoute('mailing.unsubscribe.confirm', [
        'mailing' => $mailing->id,
        'email'   => $encoded,
    ]);

    $this->post($url)->assertOk();

    expect(MailingUnsubscribe::where('email', 'member@test.com')->value('member_id'))->toBe($member->id);
});

// ── ProcessMailings skips unsubscribed ─────────────────────────────────────────

test('process command skips unsubscribed recipients', function () {
    Mail::fake();

    MailingUnsubscribe::record('unsub@test.com');

    $mailing = Mailing::factory()->sending()->create();
    MailingRecipient::create(['mailing_id' => $mailing->id, 'email' => 'unsub@test.com']);
    MailingRecipient::create(['mailing_id' => $mailing->id, 'email' => 'active@test.com']);

    Artisan::call('mailings:process');

    Mail::assertSent(\App\Mail\MailingMail::class, 1);
    Mail::assertSent(\App\Mail\MailingMail::class, fn ($m) => $m->recipient->email === 'active@test.com');
});

// ── MailingMail includes unsubscribe URL ───────────────────────────────────────

test('mailing mail contains avprenumerera variable', function () {
    $mailing   = Mailing::factory()->create(['body_html' => '<p>{{avprenumerera}}</p>']);
    $recipient = new MailingRecipient(['email' => 'x@test.com', 'member_id' => null]);

    $mail = new \App\Mail\MailingMail($mailing, $recipient);
    $mail->content();

    expect($mail->bodyHtml)->toContain('avprenumerera');
});

// ── MailingCategoryOptout model ────────────────────────────────────────────────

test('hasOptedOut returns false for unknown email', function () {
    expect(MailingCategoryOptout::hasOptedOut('x@test.com', NotificationCategory::NEWSLETTER))->toBeFalse();
});

test('hasOptedOut returns true after record', function () {
    MailingCategoryOptout::record('a@test.com', NotificationCategory::NEWSLETTER);

    expect(MailingCategoryOptout::hasOptedOut('a@test.com', NotificationCategory::NEWSLETTER))->toBeTrue();
});

test('hasOptedOut is false for a different category', function () {
    MailingCategoryOptout::record('a@test.com', NotificationCategory::NEWSLETTER);

    expect(MailingCategoryOptout::hasOptedOut('a@test.com', NotificationCategory::MEMBERSHIP))->toBeFalse();
});

test('record is idempotent for category optout', function () {
    MailingCategoryOptout::record('a@test.com', NotificationCategory::NEWSLETTER);
    MailingCategoryOptout::record('a@test.com', NotificationCategory::NEWSLETTER);

    expect(MailingCategoryOptout::where('email', 'a@test.com')->count())->toBe(1);
});

test('restore removes a category optout', function () {
    MailingCategoryOptout::record('a@test.com', NotificationCategory::NEWSLETTER);
    MailingCategoryOptout::restore('a@test.com', NotificationCategory::NEWSLETTER);

    expect(MailingCategoryOptout::hasOptedOut('a@test.com', NotificationCategory::NEWSLETTER))->toBeFalse();
});

test('forEmail returns all opted-out categories for email', function () {
    MailingCategoryOptout::record('a@test.com', NotificationCategory::NEWSLETTER);
    MailingCategoryOptout::record('a@test.com', NotificationCategory::MEMBERSHIP);

    $categories = MailingCategoryOptout::forEmail('a@test.com');

    expect($categories)->toHaveCount(2)
        ->toContain(NotificationCategory::NEWSLETTER)
        ->toContain(NotificationCategory::MEMBERSHIP);
});

test('system category cannot be opted out', function () {
    MailingCategoryOptout::record('a@test.com', NotificationCategory::SYSTEM);

    expect(MailingCategoryOptout::hasOptedOut('a@test.com', NotificationCategory::SYSTEM))->toBeFalse();
    expect(MailingCategoryOptout::where('email', 'a@test.com')->count())->toBe(0);
});

// ── Category opt-out on unsubscribe page ──────────────────────────────────────

test('unsubscribe page shows category-specific content', function () {
    $mailing = Mailing::factory()->sent()->create(['category' => NotificationCategory::NEWSLETTER]);
    $url = URL::signedRoute('mailing.unsubscribe', [
        'mailing'  => $mailing->id,
        'email'    => base64_encode('cat@test.com'),
        'category' => NotificationCategory::NEWSLETTER,
    ]);

    $this->get($url)->assertOk()->assertSee('Nyhetsbrev');
});

test('category unsubscribe confirm creates category optout not global unsubscribe', function () {
    $mailing = Mailing::factory()->sent()->create(['category' => NotificationCategory::NEWSLETTER]);
    $email   = 'cat@test.com';
    $encoded = base64_encode($email);

    $url = URL::signedRoute('mailing.unsubscribe.confirm', [
        'mailing'  => $mailing->id,
        'email'    => $encoded,
        'category' => NotificationCategory::NEWSLETTER,
    ]);

    $this->post($url)->assertOk();

    expect(MailingCategoryOptout::hasOptedOut($email, NotificationCategory::NEWSLETTER))->toBeTrue();
    expect(MailingUnsubscribe::hasUnsubscribed($email))->toBeFalse();
});

test('unknown category falls back to global unsubscribe', function () {
    $mailing = Mailing::factory()->sent()->create();
    $email   = 'fallback@test.com';
    $encoded = base64_encode($email);

    $url = URL::signedRoute('mailing.unsubscribe.confirm', [
        'mailing'  => $mailing->id,
        'email'    => $encoded,
        'category' => 'non.existent.category',
    ]);

    $this->post($url)->assertOk();

    expect(MailingUnsubscribe::hasUnsubscribed($email))->toBeTrue();
});

// ── ProcessMailings skips category opted-out ──────────────────────────────────

test('process command skips category opted-out recipients', function () {
    Mail::fake();

    MailingCategoryOptout::record('optout@test.com', NotificationCategory::NEWSLETTER);

    $mailing = Mailing::factory()->sending()->create(['category' => NotificationCategory::NEWSLETTER]);
    MailingRecipient::create(['mailing_id' => $mailing->id, 'email' => 'optout@test.com']);
    MailingRecipient::create(['mailing_id' => $mailing->id, 'email' => 'active@test.com']);

    Artisan::call('mailings:process');

    Mail::assertSent(\App\Mail\MailingMail::class, 1);
    Mail::assertSent(\App\Mail\MailingMail::class, fn ($m) => $m->recipient->email === 'active@test.com');
});

test('process command sends to category opted-out if mailing has different category', function () {
    Mail::fake();

    MailingCategoryOptout::record('optout@test.com', NotificationCategory::NEWSLETTER);

    $mailing = Mailing::factory()->sending()->create(['category' => NotificationCategory::MEMBERSHIP]);
    MailingRecipient::create(['mailing_id' => $mailing->id, 'email' => 'optout@test.com']);

    Artisan::call('mailings:process');

    Mail::assertSent(\App\Mail\MailingMail::class, 1);
});

test('process command sends to category opted-out if mailing has no category', function () {
    Mail::fake();

    MailingCategoryOptout::record('optout@test.com', NotificationCategory::NEWSLETTER);

    $mailing = Mailing::factory()->sending()->create(['category' => null]);
    MailingRecipient::create(['mailing_id' => $mailing->id, 'email' => 'optout@test.com']);

    Artisan::call('mailings:process');

    Mail::assertSent(\App\Mail\MailingMail::class, 1);
});
