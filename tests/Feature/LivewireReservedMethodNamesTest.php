<?php

use Illuminate\Support\Facades\File;

/**
 * Livewire's $wire object has built-in JS methods (upload, removeUpload, …)
 * that win over component methods with the same name — wire:click="upload"
 * runs Livewire's file upload helper and never reaches the server. Component
 * tests call the PHP method directly, so they don't notice. Found via #157
 * (Spara on the document archive did nothing).
 *
 * The reserved names are read from Livewire's own alias table so the list
 * follows Livewire upgrades.
 */
test('no Livewire component defines a method shadowed by a $wire built-in', function () {
    $js = file_get_contents(base_path('vendor/livewire/livewire/dist/livewire.js'));

    preg_match('/var aliases = \{(.*?)\};/s', $js, $block);
    preg_match_all('/"(\w+)":\s*"\$\w+"/', $block[1] ?? '', $matches);
    $reserved = $matches[1];

    expect($reserved)->toContain('upload', 'removeUpload');

    $files = collect(File::allFiles(resource_path('views/livewire')))
        ->merge(File::allFiles(app_path('Livewire')))
        ->filter(fn ($file) => str_ends_with($file->getFilename(), '.php'));

    $pattern = '/function\s+('.implode('|', $reserved).')\s*\(/';
    $failures = [];

    foreach ($files as $file) {
        if (preg_match_all($pattern, file_get_contents($file->getPathname()), $found)) {
            $failures[] = $file->getRelativePathname().': '.implode(', ', $found[1]);
        }
    }

    expect($failures)->toBe([]);
});
