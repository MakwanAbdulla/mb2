<?php

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('settings.manage');
    Storage::fake('local');
});

function settingsAdmin(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('settings.manage');

    return $user;
}

test('uploading a Swish certificate stores it on the local disk and saves its path', function () {
    $file = UploadedFile::fake()->create('certificate.p12', 10, 'application/x-pkcs12');

    Volt::actingAs(settingsAdmin())->test('admin.settings.payment')
        ->set('swishType', 'merchant')
        ->set('swishNumber', '1231234567')
        ->set('swishCertificateFile', $file)
        ->set('swishCertificatePassphrase', 'hemligt')
        ->call('save');

    Storage::disk('local')->assertExists('private/swish/certificate.p12');
    expect(SystemSetting::get('payment.swish_certificate_path'))
        ->toBe(Storage::disk('local')->path('private/swish/certificate.p12'));
    expect(SystemSetting::get('payment.swish_certificate_passphrase'))->toBe('hemligt');
});

test('removing the Swish certificate clears the stored path and passphrase', function () {
    SystemSetting::set('payment.swish_certificate_path', '/tmp/does-not-matter.p12');
    SystemSetting::set('payment.swish_certificate_passphrase', 'hemligt');

    Volt::actingAs(settingsAdmin())->test('admin.settings.payment')
        ->set('swishType', 'merchant')
        ->call('removeSwishCertificate');

    expect(SystemSetting::get('payment.swish_certificate_path'))->toBe('');
    expect(SystemSetting::get('payment.swish_certificate_passphrase'))->toBe('');
});

test('swish emulator checkbox is persisted as a system setting', function () {
    Volt::actingAs(settingsAdmin())->test('admin.settings.payment')
        ->set('swishType', 'merchant')
        ->set('swishNumber', '1231234567')
        ->set('swishEmulatorEnabled', true)
        ->call('save');

    expect((bool) SystemSetting::get('payment.swish_emulator_enabled'))->toBeTrue();
});

test('settings page requires settings.manage permission', function () {
    $user = User::factory()->create();

    Volt::actingAs($user)->test('admin.settings.payment')
        ->assertForbidden();
});
