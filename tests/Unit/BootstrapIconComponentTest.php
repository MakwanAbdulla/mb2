<?php

use Illuminate\Support\Facades\Blade;

/**
 * Every Bootstrap Icons SVG file ships with its own class="bi bi-xxx"
 * attribute baked in. The component used to insert its own class attribute
 * right next to it instead of replacing it, producing a <svg> with two
 * class attributes — invalid HTML, and the reason an icon+text button
 * (e.g. "Logga in med passnyckel") could render inconsistently across
 * browsers depending on which class attribute each one honours.
 */
test('rendered icon has exactly one class attribute, not a duplicate', function () {
    $html = Blade::render('<x-bootstrap-icon name="fingerprint" class="size-4 text-red-500" />');

    expect(substr_count($html, 'class="'))->toBe(1)
        ->and($html)->toContain('class="size-4 text-red-500"')
        ->and($html)->not->toContain('bi bi-fingerprint');
});

test('rendered icon is valid enough to contain the svg markup', function () {
    $html = Blade::render('<x-bootstrap-icon name="fingerprint" />');

    expect($html)->toContain('<svg')
        ->and($html)->toContain('</svg>');
});
