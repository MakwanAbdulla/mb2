<?php

use Illuminate\Support\Facades\Blade;

/**
 * Returns [upDisabled, downDisabled] for the rendered component.
 */
function sortButtonStates(int $index, int $count): array
{
    $html = Blade::render(
        '<x-sort-buttons prefix="moveItem" :id="42" :index="$index" :count="$count" />',
        ['index' => $index, 'count' => $count],
    );

    preg_match_all('/<button[^>]*>/', $html, $matches);

    return array_map(fn (string $tag) => str_contains($tag, 'disabled="disabled"'), $matches[0]);
}

it('calls prefixed up and down methods with the id', function () {
    $html = Blade::render('<x-sort-buttons prefix="moveItem" :id="42" :index="1" :count="3" />');

    expect($html)
        ->toContain('wire:click="moveItemUp(42)"')
        ->toContain('wire:click="moveItemDown(42)"');
});

it('defaults the prefix to move', function () {
    expect(Blade::render('<x-sort-buttons :id="3" :index="1" :count="3" />'))
        ->toContain('wire:click="moveUp(3)"')
        ->toContain('wire:click="moveDown(3)"');
});

it('disables buttons at the edges of the list', function (int $index, int $count, array $expected) {
    expect(sortButtonStates($index, $count))->toBe($expected);
})->with([
    'first' => [0, 3, [true, false]],
    'middle' => [1, 3, [false, false]],
    'last' => [2, 3, [false, true]],
    'single' => [0, 1, [true, true]],
]);
