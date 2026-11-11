<?php

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('system setting type constants are defined', function () {
    expect(SystemSetting::TYPE_STRING)->toBe('string')
        ->and(SystemSetting::TYPE_INTEGER)->toBe('integer')
        ->and(SystemSetting::TYPE_BOOLEAN)->toBe('boolean')
        ->and(SystemSetting::TYPE_JSON)->toBe('json')
        ->and(SystemSetting::TYPE_ENCRYPTED)->toBe('encrypted');
});

test('system setting get returns null for missing key', function () {
    expect(SystemSetting::get('nonexistent'))->toBeNull();
});

test('system setting get returns default for missing key', function () {
    expect(SystemSetting::get('nonexistent', 'fallback'))->toBe('fallback');
});

test('system setting set and get string value', function () {
    SystemSetting::set('site.name', 'Brässpelarna');

    expect(SystemSetting::get('site.name'))->toBe('Brässpelarna');
});

test('system setting set and get integer value', function () {
    SystemSetting::set('membership.fee', 15000, SystemSetting::TYPE_INTEGER);

    expect(SystemSetting::get('membership.fee'))->toBe(15000)
        ->and(SystemSetting::get('membership.fee'))->toBeInt();
});

test('system setting set and get boolean value', function () {
    SystemSetting::set('feature.registration', true, SystemSetting::TYPE_BOOLEAN);

    expect(SystemSetting::get('feature.registration'))->toBeTrue();

    SystemSetting::set('feature.registration', false, SystemSetting::TYPE_BOOLEAN);

    expect(SystemSetting::get('feature.registration'))->toBeFalse();
});

test('system setting set and get json value', function () {
    $data = ['key' => 'value', 'nested' => ['a', 'b']];
    SystemSetting::set('config.data', $data, SystemSetting::TYPE_JSON);

    expect(SystemSetting::get('config.data'))->toBe($data);
});

test('system setting set updates existing value', function () {
    SystemSetting::set('site.name', 'Old Name');
    SystemSetting::set('site.name', 'New Name');

    expect(SystemSetting::get('site.name'))->toBe('New Name')
        ->and(SystemSetting::where('key', 'site.name')->count())->toBe(1);
});

test('system setting uses string primary key', function () {
    SystemSetting::set('test.key', 'value');
    $setting = SystemSetting::find('test.key');

    expect($setting)->not->toBeNull()
        ->and($setting->key)->toBe('test.key');
});

test('system setting encrypted value is stored as ciphertext, not plaintext', function () {
    SystemSetting::set('secret.key', 'sk_test_super_secret', SystemSetting::TYPE_ENCRYPTED);

    $raw = SystemSetting::query()->find('secret.key');

    expect($raw->value)->not->toBe('sk_test_super_secret')
        ->and($raw->value)->not->toContain('sk_test_super_secret')
        ->and($raw->type)->toBe('encrypted');
});

test('system setting encrypted value round-trips correctly via get', function () {
    SystemSetting::set('secret.key', 'sk_test_super_secret', SystemSetting::TYPE_ENCRYPTED);

    expect(SystemSetting::get('secret.key'))->toBe('sk_test_super_secret');
});

test('system setting encrypted empty value round-trips as empty without decrypt errors', function () {
    SystemSetting::set('secret.key', '', SystemSetting::TYPE_ENCRYPTED);

    $raw = SystemSetting::query()->find('secret.key');

    expect($raw->value)->toBe('')
        ->and(SystemSetting::get('secret.key'))->toBe('');
});

test('system setting firstOrCreateSetting encrypts a fresh encrypted row', function () {
    SystemSetting::firstOrCreateSetting([
        'key' => 'secret.fresh',
        'category' => 'payment',
        'label' => 'Test',
        'value' => 'topsecret',
        'type' => SystemSetting::TYPE_ENCRYPTED,
    ]);

    $raw = SystemSetting::query()->find('secret.fresh');

    expect($raw->value)->not->toContain('topsecret')
        ->and(SystemSetting::get('secret.fresh'))->toBe('topsecret');
});

test('system setting firstOrCreateSetting never overwrites an existing row', function () {
    SystemSetting::set('secret.existing', 'original', SystemSetting::TYPE_ENCRYPTED);

    SystemSetting::firstOrCreateSetting([
        'key' => 'secret.existing',
        'category' => 'payment',
        'label' => 'Test',
        'value' => 'should-not-be-used',
        'type' => SystemSetting::TYPE_ENCRYPTED,
    ]);

    expect(SystemSetting::get('secret.existing'))->toBe('original');
});
