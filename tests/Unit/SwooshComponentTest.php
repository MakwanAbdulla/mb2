<?php

use Illuminate\Support\Facades\Blade;

test('swoosh shows the word and the svg', function () {
    $html = Blade::render('<x-swoosh>grupper</x-swoosh>');

    expect($html)->toContain('grupper')
        ->and($html)->toContain('<svg');
});

test('no space between word and svg', function () {
    $html = Blade::render('<x-swoosh>grupper</x-swoosh>');

    expect($html)->toContain('grupper<svg');
});

test('two swooshes get different ids', function () {
    $html = Blade::render('<x-swoosh>a</x-swoosh><x-swoosh>b</x-swoosh>');

    preg_match_all('/id="(swoosh-[^"]+)"/', $html, $m);

    expect($m[1])->toHaveCount(2)
        ->and($m[1][0])->not->toBe($m[1][1]);
});

test('svg is hidden from screen readers', function () {
    $html = Blade::render('<x-swoosh>grupper</x-swoosh>');

    expect($html)->toContain('aria-hidden="true"');
});
