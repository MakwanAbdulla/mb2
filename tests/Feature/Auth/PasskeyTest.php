<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

test('guest can fetch passkey login options with a valid WebAuthn challenge', function () {
    $response = $this->getJson(route('passkey.login-options'));

    $response->assertOk();
    $options = $response->json('options');

    expect($options)->toHaveKeys(['challenge', 'timeout', 'rpId', 'userVerification'])
        ->and($options['rpId'])->toBe(parse_url(config('app.url'), PHP_URL_HOST));
});

test('authenticated user with confirmed password can fetch passkey registration options', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->getJson(route('passkey.registration-options'));

    $response->assertOk();
    $options = $response->json('options');

    expect($options)->toHaveKeys(['challenge', 'rp', 'user'])
        ->and($options['rp']['id'])->toBe(parse_url(config('app.url'), PHP_URL_HOST));
});

test('registration options are blocked without a confirmed password', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson(route('passkey.registration-options'));

    // Fortify's password.confirm middleware returns 423 Locked for JSON requests
    // rather than redirecting, since there's no page to redirect an XHR call to.
    $response->assertStatus(423);
});

test('dashboard passkeys page lists the users own passkeys', function () {
    $user = User::factory()->create();
    $user->passkeys()->create([
        'name' => 'Min iPhone',
        'credential_id' => base64_encode('cred-1'),
        'credential' => json_encode(['test' => true]),
    ]);

    Volt::actingAs($user)->test('dashboard.passkeys')
        ->assertSee('Min iPhone');
});

test('deleting a passkey removes it from the users list', function () {
    $user = User::factory()->create();
    $passkey = $user->passkeys()->create([
        'name' => 'Gammal enhet',
        'credential_id' => base64_encode('cred-2'),
        'credential' => json_encode(['test' => true]),
    ]);

    Volt::actingAs($user)->test('dashboard.passkeys')
        ->call('delete', $passkey->id);

    expect($user->passkeys()->count())->toBe(0);
});

test('a user can register several passkeys, e.g. a keychain YubiKey and a backup in a safe', function () {
    $user = User::factory()->create();
    $keychain = $user->passkeys()->create([
        'name' => 'YubiKey (nyckelknippa)',
        'credential_id' => base64_encode('yubikey-keychain'),
        'credential' => json_encode(['test' => true]),
    ]);
    $backup = $user->passkeys()->create([
        'name' => 'YubiKey (kassaskåp, backup)',
        'credential_id' => base64_encode('yubikey-safe-backup'),
        'credential' => json_encode(['test' => true]),
    ]);

    expect($user->passkeys()->count())->toBe(2);

    Volt::actingAs($user)->test('dashboard.passkeys')
        ->assertSee('YubiKey (nyckelknippa)')
        ->assertSee('YubiKey (kassaskåp, backup)');

    // Deleting the lost/compromised keychain key must not touch the backup.
    Volt::actingAs($user)->test('dashboard.passkeys')->call('delete', $keychain->id);

    expect($user->passkeys()->count())->toBe(1)
        ->and($user->passkeys()->first()->id)->toBe($backup->id);
});

test('registration excludes only the users own already-registered credentials, not a count limit', function () {
    $user = User::factory()->create();
    // credential_id must be base64url (unpadded), matching how StorePasskey
    // actually encodes it via Base64UrlSafe::encodeUnpadded() — the package
    // decodes it the same way when building excludeCredentials.
    $credentialId = rtrim(strtr(base64_encode('existing-cred'), '+/', '-_'), '=');
    $user->passkeys()->create([
        'name' => 'Befintlig nyckel',
        'credential_id' => $credentialId,
        'credential' => json_encode(['test' => true]),
    ]);

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->getJson(route('passkey.registration-options'));

    $response->assertOk();
    $excluded = $response->json('options.excludeCredentials');

    expect($excluded)->toHaveCount(1)
        ->and($excluded[0]['id'])->toBe($credentialId);
});
