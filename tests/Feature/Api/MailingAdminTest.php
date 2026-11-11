<?php

use App\Models\Content\Mailing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('mailings.view');
    $this->createPermission('mailings.manage');
});

function mailingsAdminUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('mailings.manage');
    return $user;
}

function makeMailing(string $status = 'draft'): Mailing
{
    return Mailing::create([
        'title'      => 'Testutskick',
        'subject'    => 'Testutskick ämne',
        'body_html'  => '<p>Test</p>',
        'status'     => $status,
        'from_email' => 'info@armagedon.se',
        'from_name'  => 'Armagedon',
        'created_by' => User::factory()->create()->id,
    ]);
}

// ── POST /api/v1/mailings ─────────────────────────────────────────────────────

test('admin can create mailing draft', function () {
    Sanctum::actingAs(mailingsAdminUser());

    $this->postJson('/api/v1/mailings', [
        'title'     => 'Nyhetsbrev april',
        'subject'   => 'Nyhetsbrev april',
        'body_html' => '<p>Hej alla!</p>',
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.subject', 'Nyhetsbrev april');
});

test('create mailing requires mailings.manage permission', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('mailings.view');
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/mailings', ['subject' => 'Test'])->assertForbidden();
});

test('create mailing requires auth', function () {
    $this->postJson('/api/v1/mailings', ['subject' => 'Test'])->assertUnauthorized();
});

// ── PATCH /api/v1/mailings/{mailing} ─────────────────────────────────────────

test('admin can update draft mailing', function () {
    $mailing = makeMailing();
    Sanctum::actingAs(mailingsAdminUser());

    $this->patchJson("/api/v1/mailings/{$mailing->id}", [
        'subject' => 'Uppdaterat ämne',
    ])
        ->assertOk()
        ->assertJsonPath('data.subject', 'Uppdaterat ämne');
});

test('cannot update sent mailing', function () {
    $mailing = makeMailing('sent');
    Sanctum::actingAs(mailingsAdminUser());

    $this->patchJson("/api/v1/mailings/{$mailing->id}", ['subject' => 'Hack'])
        ->assertStatus(422);
});

// ── POST /api/v1/mailings/{mailing}/send ─────────────────────────────────────

test('admin can queue draft mailing for sending', function () {
    $mailing = makeMailing('draft');
    Sanctum::actingAs(mailingsAdminUser());

    $this->postJson("/api/v1/mailings/{$mailing->id}/send")
        ->assertOk()
        ->assertJsonPath('status', 'sending');
});

test('cannot send already-sent mailing', function () {
    $mailing = makeMailing('sent');
    Sanctum::actingAs(mailingsAdminUser());

    $this->postJson("/api/v1/mailings/{$mailing->id}/send")
        ->assertStatus(422);
});

// ── POST /api/v1/mailings/{mailing}/cancel ───────────────────────────────────

test('admin can cancel a scheduled mailing', function () {
    $mailing = makeMailing('scheduled');
    Sanctum::actingAs(mailingsAdminUser());

    $this->postJson("/api/v1/mailings/{$mailing->id}/cancel")
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');
});

test('cannot cancel an already-sent mailing', function () {
    $mailing = makeMailing('sent');
    Sanctum::actingAs(mailingsAdminUser());

    $this->postJson("/api/v1/mailings/{$mailing->id}/cancel")
        ->assertStatus(422);
});

// ── DELETE /api/v1/mailings/{mailing} ────────────────────────────────────────

test('admin can delete draft mailing', function () {
    $mailing = makeMailing('draft');
    Sanctum::actingAs(mailingsAdminUser());

    $this->deleteJson("/api/v1/mailings/{$mailing->id}")
        ->assertOk();

    expect(Mailing::find($mailing->id))->toBeNull();
});

test('cannot delete sent mailing', function () {
    $mailing = makeMailing('sent');
    Sanctum::actingAs(mailingsAdminUser());

    $this->deleteJson("/api/v1/mailings/{$mailing->id}")
        ->assertStatus(422);
});
