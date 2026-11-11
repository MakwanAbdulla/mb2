<?php

use App\Models\Events\Event;
use App\Models\Events\EventOccurrence;
use App\Models\Events\EventPriceTier;
use App\Models\Events\EventRegistration;
use App\Models\Content\NotificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function eventWithReminder(int $hoursFromNow): array
{
    $event = Event::factory()->published()->create([
        'reminder_hours_before' => $hoursFromNow,
    ]);
    $occurrence = EventOccurrence::factory()->create([
        'event_id'   => $event->id,
        'starts_at'  => now()->addHours($hoursFromNow),
        'ends_at'    => now()->addHours($hoursFromNow + 2),
    ]);
    $tier = EventPriceTier::factory()->free()->create(['event_id' => $event->id]);

    return [$event, $occurrence, $tier];
}

test('sends reminder to confirmed registrations within window', function () {
    Mail::fake();

    [$event, $occurrence, $tier] = eventWithReminder(24);
    $user = User::factory()->create();
    EventRegistration::factory()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'user_id'       => $user->id,
        'status'        => EventRegistration::STATUS_CONFIRMED,
    ]);

    $this->artisan('events:send-reminders')->assertSuccessful();

    Mail::assertQueued(\App\Mail\EventRegistrationMail::class, fn ($m) =>
        $m->recipientEmail() === $user->email
    );
});

test('does not send to waitlisted registrations', function () {
    Mail::fake();

    [$event, $occurrence, $tier] = eventWithReminder(24);
    $user = User::factory()->create();
    EventRegistration::factory()->waitlisted()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'user_id'       => $user->id,
    ]);

    $this->artisan('events:send-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

test('does not send if occurrence is outside window', function () {
    Mail::fake();

    // reminder_hours_before = 24, but occurrence starts in 48h — outside ±30min window
    $event = Event::factory()->published()->create(['reminder_hours_before' => 24]);
    $occurrence = EventOccurrence::factory()->create([
        'event_id'  => $event->id,
        'starts_at' => now()->addHours(48),
        'ends_at'   => now()->addHours(50),
    ]);
    $tier = EventPriceTier::factory()->free()->create(['event_id' => $event->id]);
    $user = User::factory()->create();
    EventRegistration::factory()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'user_id'       => $user->id,
        'status'        => EventRegistration::STATUS_CONFIRMED,
    ]);

    $this->artisan('events:send-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

test('skips registration if reminder already sent', function () {
    Mail::fake();

    [$event, $occurrence, $tier] = eventWithReminder(24);
    $user = User::factory()->create();
    $reg  = EventRegistration::factory()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'user_id'       => $user->id,
        'status'        => EventRegistration::STATUS_CONFIRMED,
    ]);

    NotificationLog::record($reg, 'event_reminder_occ_' . $occurrence->id, $user->email);

    $this->artisan('events:send-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

test('dry-run does not send mail', function () {
    Mail::fake();

    [$event, $occurrence, $tier] = eventWithReminder(24);
    $user = User::factory()->create();
    EventRegistration::factory()->create([
        'event_id'      => $event->id,
        'price_tier_id' => $tier->id,
        'user_id'       => $user->id,
        'status'        => EventRegistration::STATUS_CONFIRMED,
    ]);

    $this->artisan('events:send-reminders', ['--dry-run' => true])->assertSuccessful();

    Mail::assertNothingQueued();
    expect(NotificationLog::count())->toBe(0);
});
