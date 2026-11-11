<?php

use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('contact.view');
    $this->createPermission('contact.manage');
});

function contactAdminUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('contact.manage');
    return $user;
}

function makeContactMessage(string $status = 'new'): ContactMessage
{
    return ContactMessage::create([
        'name'    => 'Anna Svensson',
        'email'   => 'anna@example.com',
        'subject' => 'Fråga om aktiviteter',
        'message' => 'Hej, jag undrar om...',
        'status'  => $status,
    ]);
}

// ── GET /api/v1/contact-messages ─────────────────────────────────────────────

test('contact messages list requires auth', function () {
    $this->getJson('/api/v1/contact-messages')->assertUnauthorized();
});

test('contact messages list requires contact permission', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/contact-messages')->assertForbidden();
});

test('admin can list contact messages', function () {
    makeContactMessage('new');
    makeContactMessage('read');
    Sanctum::actingAs(contactAdminUser());

    $this->getJson('/api/v1/contact-messages')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('admin can filter contact messages by status', function () {
    makeContactMessage('new');
    makeContactMessage('new');
    makeContactMessage('replied');
    Sanctum::actingAs(contactAdminUser());

    $this->getJson('/api/v1/contact-messages?status=new')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

// ── GET /api/v1/contact-messages/{contactMessage} ────────────────────────────

test('viewing new message auto-marks it as read', function () {
    $message = makeContactMessage('new');
    Sanctum::actingAs(contactAdminUser());

    $this->getJson("/api/v1/contact-messages/{$message->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'read');

    expect($message->fresh()->status)->toBe('read');
});

test('viewing already-read message does not change status', function () {
    $message = makeContactMessage('replied');
    Sanctum::actingAs(contactAdminUser());

    $this->getJson("/api/v1/contact-messages/{$message->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'replied');
});

// ── PATCH /api/v1/contact-messages/{contactMessage} ──────────────────────────

test('admin can update message status and notes', function () {
    $message = makeContactMessage('read');
    Sanctum::actingAs(contactAdminUser());

    $this->patchJson("/api/v1/contact-messages/{$message->id}", [
        'status' => 'replied',
        'notes'  => 'Svarade per telefon',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'replied')
        ->assertJsonPath('data.notes', 'Svarade per telefon');
});

test('marking message as replied sets replied_at', function () {
    $message = makeContactMessage('read');
    Sanctum::actingAs(contactAdminUser());

    $this->patchJson("/api/v1/contact-messages/{$message->id}", ['status' => 'replied'])
        ->assertOk();

    expect($message->fresh()->replied_at)->not->toBeNull();
});

test('update contact message requires contact.manage permission', function () {
    $message = makeContactMessage();
    $user    = User::factory()->create();
    $user->givePermissionTo('contact.view');
    Sanctum::actingAs($user);

    $this->patchJson("/api/v1/contact-messages/{$message->id}", ['status' => 'spam'])
        ->assertForbidden();
});
