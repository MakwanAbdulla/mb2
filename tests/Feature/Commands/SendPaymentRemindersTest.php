<?php

use App\Mail\PaymentReminderMail;
use App\Models\Membership\Member;
use App\Models\Content\NotificationLog;
use App\Models\Shop\Order;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    SystemSetting::set('notification.payment_reminder_1_days', 7);
    SystemSetting::set('notification.payment_reminder_2_days', 14);
});

test('command runs successfully with no pending orders', function () {
    $exitCode = Artisan::call('members:send-payment-reminders');
    expect($exitCode)->toBe(0);
    Mail::assertNothingSent();
});

test('sends reminder for pending order past threshold', function () {
    $user = User::factory()->create();
    Member::factory()->create(['user_id' => $user->id]);
    Order::factory()->create([
        'user_id'    => $user->id,
        'status'     => Order::STATUS_PENDING,
        'created_at' => now()->subDays(8),
    ]);

    Artisan::call('members:send-payment-reminders');

    Mail::assertSent(PaymentReminderMail::class, 1);
});

test('does not send for pending order not yet past threshold', function () {
    $user = User::factory()->create();
    Member::factory()->create(['user_id' => $user->id]);
    Order::factory()->create([
        'user_id'    => $user->id,
        'status'     => Order::STATUS_PENDING,
        'created_at' => now()->subDays(3),
    ]);

    Artisan::call('members:send-payment-reminders');

    Mail::assertNothingSent();
});

test('does not send for already paid orders', function () {
    $user = User::factory()->create();
    Member::factory()->create(['user_id' => $user->id]);
    Order::factory()->paid()->create([
        'user_id'    => $user->id,
        'created_at' => now()->subDays(10),
    ]);

    Artisan::call('members:send-payment-reminders');

    Mail::assertNothingSent();
});

test('does not send for guest orders', function () {
    Order::factory()->create([
        'user_id'      => null,
        'guest_email'  => 'guest@test.com',
        'status'       => Order::STATUS_PENDING,
        'created_at'   => now()->subDays(10),
    ]);

    Artisan::call('members:send-payment-reminders');

    Mail::assertNothingSent();
});

test('skips order when reminder already sent recently', function () {
    $user = User::factory()->create();
    Member::factory()->create(['user_id' => $user->id]);
    $order = Order::factory()->create([
        'user_id'    => $user->id,
        'status'     => Order::STATUS_PENDING,
        'created_at' => now()->subDays(8),
    ]);

    // Pre-log a recent reminder
    NotificationLog::record($order, 'payment_reminder_1', $user->email);

    Artisan::call('members:send-payment-reminders');

    Mail::assertNothingSent();
});

test('dry-run does not send emails', function () {
    $user = User::factory()->create();
    Member::factory()->create(['user_id' => $user->id]);
    Order::factory()->create([
        'user_id'    => $user->id,
        'status'     => Order::STATUS_PENDING,
        'created_at' => now()->subDays(8),
    ]);

    Artisan::call('members:send-payment-reminders', ['--dry-run' => true]);

    Mail::assertNothingSent();
});

test('dry-run does not create notification log entries', function () {
    $user = User::factory()->create();
    Member::factory()->create(['user_id' => $user->id]);
    Order::factory()->create([
        'user_id'    => $user->id,
        'status'     => Order::STATUS_PENDING,
        'created_at' => now()->subDays(8),
    ]);

    Artisan::call('members:send-payment-reminders', ['--dry-run' => true]);

    expect(NotificationLog::count())->toBe(0);
});

test('records notification log after sending', function () {
    $user = User::factory()->create();
    Member::factory()->create(['user_id' => $user->id]);
    $order = Order::factory()->create([
        'user_id'    => $user->id,
        'status'     => Order::STATUS_PENDING,
        'created_at' => now()->subDays(8),
    ]);

    Artisan::call('members:send-payment-reminders');

    expect(NotificationLog::where('notifiable_type', Order::class)
        ->where('notifiable_id', $order->id)
        ->where('notification_type', 'payment_reminder_1')
        ->exists()
    )->toBeTrue();
});
