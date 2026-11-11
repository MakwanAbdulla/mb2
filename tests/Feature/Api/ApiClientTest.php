<?php

use App\Models\ApiClient;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('api.access');
});

test('client can exchange credentials for a bearer token', function () {
    ['client' => $client, 'plain_secret' => $secret] = ApiClient::provision('Test Client');

    $response = $this->postJson('/api/v1/auth/client-tokens', [
        'client_id'     => $client->client_id,
        'client_secret' => $secret,
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['token', 'token_type', 'client', 'scopes']);

    expect($response->json('token_type'))->toBe('Bearer')
        ->and($response->json('client'))->toBe('Test Client');
});

test('wrong secret is rejected with 401', function () {
    ['client' => $client] = ApiClient::provision('Test Client');

    $this->postJson('/api/v1/auth/client-tokens', [
        'client_id'     => $client->client_id,
        'client_secret' => 'wrong-secret',
    ])->assertUnauthorized();
});

test('unknown client_id is rejected with 401', function () {
    $this->postJson('/api/v1/auth/client-tokens', [
        'client_id'     => (string) \Illuminate\Support\Str::uuid(),
        'client_secret' => 'whatever',
    ])->assertUnauthorized();
});

test('disabled client is rejected', function () {
    ['client' => $client, 'plain_secret' => $secret] = ApiClient::provision('Disabled');
    $client->update(['enabled' => false]);

    $this->postJson('/api/v1/auth/client-tokens', [
        'client_id'     => $client->client_id,
        'client_secret' => $secret,
    ])->assertUnauthorized();
});

test('issued token grants access to authenticated endpoints', function () {
    ['client' => $client, 'plain_secret' => $secret] = ApiClient::provision('Integration');

    $token = $this->postJson('/api/v1/auth/client-tokens', [
        'client_id'     => $client->client_id,
        'client_secret' => $secret,
    ])->json('token');

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('name', 'Integration');
});

test('token without api.access permission is rejected by middleware', function () {
    $user = User::factory()->create();
    // Deliberately not granting api.access
    Sanctum::actingAs($user, [], 'web');

    // Simulate bearer token by giving a real token and calling directly
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertForbidden();
});

test('regenerating secret revokes existing tokens', function () {
    ['client' => $client, 'plain_secret' => $secret] = ApiClient::provision('Regen Test');

    $token = $this->postJson('/api/v1/auth/client-tokens', [
        'client_id'     => $client->client_id,
        'client_secret' => $secret,
    ])->json('token');

    $client->regenerateSecret();

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});

test('provision creates a service account user', function () {
    ['client' => $client] = ApiClient::provision('Service');

    expect($client->user)->not->toBeNull()
        ->and($client->user->is_service_account)->toBeTrue()
        ->and($client->user->hasPermissionTo('api.access'))->toBeTrue();
});

// ── Scopes (#159) ─────────────────────────────────────────────────────────────

function clientToken(object $test, ?array $scopes, array $permissions = [], array $roles = []): string
{
    ['client' => $client, 'plain_secret' => $secret] = ApiClient::provision('Scoped', $scopes);

    foreach ($permissions as $permission) {
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $client->user->givePermissionTo($permission);
    }
    foreach ($roles as $role) {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $client->user->assignRole($role);
    }

    return $test->postJson('/api/v1/auth/client-tokens', [
        'client_id'     => $client->client_id,
        'client_secret' => $secret,
    ])->json('token');
}

test('scope that does not cover the permission is denied even if the service account has it', function () {
    $this->createPermission('events.view');
    $token = clientToken($this, ['events.view'], ['members.manage']);

    $this->withToken($token)->getJson('/api/v1/members')->assertForbidden();
});

test('scope that covers a permission the service account has is allowed', function () {
    $token = clientToken($this, ['members.manage'], ['members.manage']);

    $this->withToken($token)->getJson('/api/v1/members')->assertOk();
});

test('scope alone grants nothing the service account lacks', function () {
    $this->createPermission('members.manage');
    $token = clientToken($this, ['members.manage']);

    $this->withToken($token)->getJson('/api/v1/members')->assertForbidden();
});

test('client without scopes gets all service account permissions', function () {
    $token = clientToken($this, null, ['members.manage']);

    $this->withToken($token)->getJson('/api/v1/members')->assertOk();
});

test('scope restricts an admin service account too', function () {
    $this->createPermission('members.manage');
    $this->createPermission('events.view');
    $token = clientToken($this, ['events.view'], [], ['admin']);

    $this->withToken($token)->getJson('/api/v1/members')->assertForbidden();
});

// ── Rate limiting (#258) ──────────────────────────────────────────────────────

test('credential exchange is rate limited per ip', function () {
    foreach (range(1, 10) as $_) {
        $this->postJson('/api/v1/auth/client-tokens', [
            'client_id' => (string) \Illuminate\Support\Str::uuid(), 'client_secret' => 'x',
        ])->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/client-tokens', [
        'client_id' => (string) \Illuminate\Support\Str::uuid(), 'client_secret' => 'x',
    ])->assertTooManyRequests()->assertHeader('Retry-After');
});

test('authenticated api calls are rate limited per user', function () {
    $token = clientToken($this, null);

    foreach (range(1, 120) as $_) {
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertTooManyRequests();
});
