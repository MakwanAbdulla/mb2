<?php

use App\Enums\SurveyStatus;
use App\Models\Survey\Survey;
use App\Models\Survey\SurveyInvitation;
use App\Models\User;
use App\Notifications\SurveyClosedNotification;
use App\Notifications\SurveyInvitationNotification;
use App\Notifications\SurveyOpenedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function psSurvey(array $attrs = []): Survey
{
    $user = User::factory()->create();
    return Survey::create(array_merge([
        'title'      => 'Test Survey',
        'status'     => SurveyStatus::Draft,
        'created_by' => $user->id,
    ], $attrs));
}

// ── Activation ────────────────────────────────────────────────────────────────

test('activates draft survey when opens_at has passed', function () {
    Notification::fake();

    $survey = psSurvey(['opens_at' => now()->subMinute(), 'status' => SurveyStatus::Draft]);

    Artisan::call('surveys:process');

    expect($survey->fresh()->status)->toBe(SurveyStatus::Active);
});

test('does not activate draft survey before opens_at', function () {
    Notification::fake();

    $survey = psSurvey(['opens_at' => now()->addHour(), 'status' => SurveyStatus::Draft]);

    Artisan::call('surveys:process');

    expect($survey->fresh()->status)->toBe(SurveyStatus::Draft);
});

test('notifies creator when survey is activated', function () {
    Notification::fake();

    $user   = User::factory()->create();
    $survey = Survey::create([
        'title'      => 'Notif test',
        'status'     => SurveyStatus::Draft,
        'opens_at'   => now()->subMinute(),
        'created_by' => $user->id,
    ]);

    Artisan::call('surveys:process');

    Notification::assertSentTo($user, SurveyOpenedNotification::class);
});

test('draft survey without opens_at is not activated', function () {
    Notification::fake();

    $survey = psSurvey(['opens_at' => null, 'status' => SurveyStatus::Draft]);

    Artisan::call('surveys:process');

    expect($survey->fresh()->status)->toBe(SurveyStatus::Draft);
});

// ── Closing ───────────────────────────────────────────────────────────────────

test('closes active survey when closes_at has passed', function () {
    Notification::fake();

    $survey = psSurvey(['status' => SurveyStatus::Active, 'closes_at' => now()->subMinute()]);

    Artisan::call('surveys:process');

    expect($survey->fresh()->status)->toBe(SurveyStatus::Closed);
});

test('does not close active survey before closes_at', function () {
    Notification::fake();

    $survey = psSurvey(['status' => SurveyStatus::Active, 'closes_at' => now()->addHour()]);

    Artisan::call('surveys:process');

    expect($survey->fresh()->status)->toBe(SurveyStatus::Active);
});

test('notifies creator when survey is closed', function () {
    Notification::fake();

    $user   = User::factory()->create();
    $survey = Survey::create([
        'title'      => 'Closing test',
        'status'     => SurveyStatus::Active,
        'closes_at'  => now()->subMinute(),
        'created_by' => $user->id,
    ]);

    Artisan::call('surveys:process');

    Notification::assertSentTo($user, SurveyClosedNotification::class);
});

// ── Invitations ───────────────────────────────────────────────────────────────

test('sends pending invitations when send_invitations_at has passed', function () {
    Notification::fake();

    $survey = psSurvey([
        'status'               => SurveyStatus::Active,
        'send_invitations_at'  => now()->subMinute(),
    ]);
    SurveyInvitation::create(['survey_id' => $survey->id, 'email' => 'test@example.com', 'token' => \Str::uuid(), 'sent_at' => null]);

    Artisan::call('surveys:process');

    Notification::assertSentOnDemand(SurveyInvitationNotification::class);
    expect(SurveyInvitation::first()->sent_at)->not->toBeNull();
});

test('does not re-send already sent invitations', function () {
    Notification::fake();

    $survey = psSurvey([
        'status'              => SurveyStatus::Active,
        'send_invitations_at' => now()->subMinute(),
    ]);
    SurveyInvitation::create([
        'survey_id' => $survey->id,
        'email'     => 'sent@example.com',
        'token'     => \Str::uuid(),
        'sent_at'   => now()->subHour(),
    ]);

    Artisan::call('surveys:process');

    Notification::assertNothingSent();
});

test('does not send invitations before send_invitations_at', function () {
    Notification::fake();

    $survey = psSurvey([
        'status'              => SurveyStatus::Active,
        'send_invitations_at' => now()->addHour(),
    ]);
    SurveyInvitation::create(['survey_id' => $survey->id, 'email' => 'pending@example.com', 'token' => \Str::uuid(), 'sent_at' => null]);

    Artisan::call('surveys:process');

    Notification::assertNothingSent();
});
