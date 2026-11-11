<?php

use App\Mail\MailingMail;
use App\Models\Content\Mailing;
use App\Models\Content\MailingRecipient;
use App\Models\Membership\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('mailable resolves fornamn variable from linked member', function () {
    $member    = Member::factory()->create(['first_name' => 'Britta']);
    $mailing   = Mailing::factory()->create(['subject' => 'Hej {{fornamn}}!', 'body_html' => '<p>{{fornamn}}</p>']);
    $recipient = new MailingRecipient(['email' => $member->email, 'member_id' => $member->id]);
    $recipient->setRelation('member', $member);

    $mail = new MailingMail($mailing, $recipient);

    expect($mail->envelope()->subject)->toBe('Hej Britta!');
});

test('mailable falls back to empty string for missing member', function () {
    $mailing   = Mailing::factory()->create(['subject' => 'Hej {{fornamn}}!']);
    $recipient = new MailingRecipient(['email' => 'nobody@test.com', 'member_id' => null]);

    $mail = new MailingMail($mailing, $recipient);

    expect($mail->envelope()->subject)->toBe('Hej !');
});

test('mailable uses email variable', function () {
    $mailing   = Mailing::factory()->create(['subject' => 'Till {{email}}']);
    $recipient = new MailingRecipient(['email' => 'orjan@test.com', 'member_id' => null]);

    $mail = new MailingMail($mailing, $recipient);

    expect($mail->envelope()->subject)->toBe('Till orjan@test.com');
});

test('mailable generates unique tracking token per instance', function () {
    $mailing   = Mailing::factory()->create();
    $recipient = new MailingRecipient(['email' => 'a@test.com', 'member_id' => null]);

    $mail1 = new MailingMail($mailing, $recipient);
    $mail2 = new MailingMail($mailing, $recipient);

    expect($mail1->trackingToken)->not->toBe($mail2->trackingToken);
});

test('mailable can be sent via Mail facade', function () {
    Mail::fake();

    $mailing   = Mailing::factory()->create();
    $recipient = new MailingRecipient(['email' => 'test@test.com', 'member_id' => null]);

    Mail::to('test@test.com')->send(new MailingMail($mailing, $recipient));

    Mail::assertSent(MailingMail::class, fn ($m) => $m->recipient->email === 'test@test.com');
});
