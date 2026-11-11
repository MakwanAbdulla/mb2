<?php

/*
 * Regression for #229: validation errors on /admin/taggar showed the English
 * property name ("New tag name är obligatoriskt.").
 */

use App\Livewire\Admin\TagManager;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->createPermission('bookings.categories');
});

function tagAdmin(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('bookings.categories');

    return $user;
}

test('adding a tag without a name shows a Swedish message', function () {
    Livewire::actingAs(tagAdmin())
        ->test(TagManager::class)
        ->call('addTag')
        ->assertHasErrors(['newTagName' => 'required'])
        ->assertSee('Ange ett taggnamn.');
});

test('adding a tag that already exists shows a Swedish message', function () {
    Tag::findOrCreateByName('brädspel');

    Livewire::actingAs(tagAdmin())
        ->test(TagManager::class)
        ->set('newTagName', 'brädspel')
        ->call('addTag')
        ->assertHasErrors(['newTagName' => 'unique'])
        ->assertSee('Det finns redan en tagg med det namnet.');
});

test('adding a tag with a valid name creates it', function () {
    Livewire::actingAs(tagAdmin())
        ->test(TagManager::class)
        ->set('newTagName', 'Rollspel')
        ->call('addTag')
        ->assertHasNoErrors();

    expect(Tag::where('name', 'rollspel')->exists())->toBeTrue();
});

test('renaming a tag to an empty name shows a Swedish message', function () {
    $tag = Tag::findOrCreateByName('kortspel');

    Livewire::actingAs(tagAdmin())
        ->test(TagManager::class)
        ->call('startRename', $tag->id, $tag->name)
        ->set('renameValue', '')
        ->call('saveRename')
        ->assertHasErrors(['renameValue' => 'required'])
        ->assertSee('Ange ett nytt namn.');
});

test('merging without a target shows a Swedish message', function () {
    $tag = Tag::findOrCreateByName('figurspel');

    Livewire::actingAs(tagAdmin())
        ->test(TagManager::class)
        ->call('startMerge', $tag->id)
        ->call('saveMerge')
        ->assertHasErrors(['mergeTarget' => 'required'])
        ->assertSee('Ange vilken tagg den ska slås ihop med.');
});

test('a tag name longer than 100 characters shows a Swedish message', function () {
    Livewire::actingAs(tagAdmin())
        ->test(TagManager::class)
        ->set('newTagName', str_repeat('a', 101))
        ->call('addTag')
        ->assertHasErrors(['newTagName' => 'max'])
        ->assertSee('Taggnamnet får vara högst 100 tecken.');
});
