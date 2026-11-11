<?php

use App\Models\Content\Page;
use App\Models\Membership\Member;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

uses(RefreshDatabase::class);

function createHomePage(): Page
{
    return Page::create([
        'title' => 'Välkommen till Armagedon',
        'subtitle' => 'Borås spelförening för brädspel',
        'slug' => 'hem',
        'content' => '<p>Fri text</p>',
        'is_published' => true,
    ]);
}

test('guests see the hero with the guest buttons', function () {
    createHomePage();

    $this->get('/')
        ->assertOk()
        ->assertSee('Välkommen till')
        ->assertSee('Armagedon')
        ->assertSee('Bli medlem')
        ->assertSee('Logga in')
        ->assertDontSee('Min sida')
        ->assertSee('Veckans spelkvällar');
});

test('logged in members see the member buttons instead', function () {
    createHomePage();
    $user = User::factory()->create();
    Member::factory()->active()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('Min sida')
        ->assertSee('Boka lokal')
        ->assertDontSee('Logga in');
});

test('logged in users who cannot book do not see the booking button', function () {
    createHomePage();

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('Min sida')
        ->assertDontSee('href="'.route('booking.create').'"', false);
});

test('title and meta description come from the hem page', function () {
    createHomePage();

    $this->get('/')
        ->assertSee('<title>Välkommen till Armagedon – Armagedon</title>', false)
        ->assertSee('<meta name="description" content="Borås spelförening för brädspel">', false);
});

test('the start page still works without a hem page', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Bli medlem')
        ->assertSee('kortspel och rollspel');
});

test('a one-word title is not shown twice', function () {
    $html = Blade::render('<x-home.hero title="Armagedon" />');

    expect(substr_count($html, 'Armagedon'))->toBe(1);
});

test('the meta description is escaped only once', function () {
    $page = createHomePage();
    $page->update(['subtitle' => 'Brädspel & rollspel, "alltid"']);

    $this->get('/')
        ->assertSee('<meta name="description" content="Brädspel &amp; rollspel, &quot;alltid&quot;">', false);
});

test('"Just nu i lokalen" is hidden until there is real data', function () {
    createHomePage();

    $this->get('/')->assertDontSee('Just nu i lokalen');
});

test('editors see a link to edit the hem page, others do not', function () {
    $page = createHomePage();
    $editUrl = route('admin.pages.edit', $page);

    $this->get('/')->assertDontSee($editUrl);

    // The layout checks other admin permissions for staff, so all of them must exist
    $this->seed(PermissionSeeder::class);
    $editor = User::factory()->create();
    $editor->givePermissionTo('cms.edit');

    $this->actingAs($editor)->get('/')->assertSee($editUrl);
});
