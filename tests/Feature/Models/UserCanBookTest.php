<?php

use App\Models\Membership\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('active member can book', function () {
    $user = User::factory()->create();
    Member::factory()->active()->create(['user_id' => $user->id]);

    expect($user->canBook())->toBeTrue();
});

test('user without member record cannot book', function () {
    $user = User::factory()->create();

    expect($user->canBook())->toBeFalse();
});

test('user with pending membership cannot book', function () {
    $user = User::factory()->create();
    Member::factory()->create(['user_id' => $user->id, 'status' => Member::STATUS_PENDING]);

    expect($user->canBook())->toBeFalse();
});

test('admin can book without membership', function () {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('admin');

    expect($user->canBook())->toBeTrue();
});
