<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// #126: /admin/enkater had no link in the admin menu.

beforeEach(function () {
    $this->createPermission('mailings.manage');
    $this->createPermission('surveys.manage');
    $this->createPermission('users.manage-roles');
});

function utskickUserWith(string ...$permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(...$permissions);

    return $user;
}

test('surveys.manage alone gets the Utskick link pointing at Enkäter', function () {
    $this->actingAs(utskickUserWith('surveys.manage'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('admin.surveys.index'), false)
        ->assertDontSee(route('admin.mailings.index'), false);
});

test('the Enkäter page shows the Utskick submenu without the mailing tabs', function () {
    $this->actingAs(utskickUserWith('surveys.manage'))
        ->get(route('admin.surveys.index'))
        ->assertOk()
        ->assertSee('Utskick')
        ->assertDontSee(route('admin.mailing-templates.index'), false);
});

test('a user with both permissions sees all Utskick tabs', function () {
    $this->actingAs(utskickUserWith('mailings.manage', 'surveys.manage'))
        ->get(route('admin.mailings.index'))
        ->assertOk()
        ->assertSee(route('admin.mailings.index'), false)
        ->assertSee(route('admin.mailing-templates.index'), false)
        ->assertSee(route('admin.surveys.index'), false);
});

test('mailings.manage alone does not get the Enkäter tab', function () {
    $this->actingAs(utskickUserWith('mailings.manage'))
        ->get(route('admin.mailings.index'))
        ->assertOk()
        ->assertSee(route('admin.mailings.index'), false)
        ->assertDontSee(route('admin.surveys.index'), false);
});

test('a user without either permission gets no Utskick link', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('admin.mailings.index'), false)
        ->assertDontSee(route('admin.surveys.index'), false);
});
