<?php

use App\Mail\ContactConfirmationMail;
use App\Models\ContactMessage;
use App\Models\User;
use App\Notifications\ContactMessageReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function submitContactForm(): Testable
{
    return Volt::test('pages.contact')
        ->set('name', 'Test Testsson')
        ->set('email', 'test@example.com')
        ->set('subject', 'Fråga om medlemskap')
        ->set('message', 'Hej! Hur blir man medlem i föreningen?')
        ->set('formLoadedAt', time() - 10)
        ->call('submit');
}

it('submits the contact form and notifies the office by mail', function () {
    Notification::fake();
    Mail::fake();
    config(['mail.contact_address' => 'kansli@example.com']);

    submitContactForm()
        ->assertHasNoErrors()
        ->assertSet('sent', true);

    expect(ContactMessage::where('email', 'test@example.com')->exists())->toBeTrue();

    Notification::assertSentOnDemand(
        ContactMessageReceived::class,
        fn ($notification, array $channels, object $notifiable) => $channels === ['mail']
            && $notifiable->routes['mail'] === 'kansli@example.com',
    );
    Mail::assertQueued(ContactConfirmationMail::class);
});

it('sends the office notification without a real fake (regression for #7)', function () {
    // Without Notification::fake() the channels are resolved for real — an
    // anonymous notifiable has no wantsEmailForNotifications(), which used to 500.
    Mail::fake();

    submitContactForm()->assertHasNoErrors()->assertSet('sent', true);
});

it('uses database and mail channels for a user who wants email', function () {
    $user = User::factory()->create();
    $notification = new ContactMessageReceived(ContactMessage::create([
        'name' => 'Test Testsson',
        'email' => 'test@example.com',
        'subject' => 'Fråga',
        'message' => 'Ett meddelande.',
        'status' => ContactMessage::STATUS_NEW,
    ]));

    expect($notification->via($user))->toContain('database')
        ->and($notification->via(new AnonymousNotifiable))->toBe(['mail']);
});
