<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Catches Blade/Alpine directive collisions (e.g. Alpine's @error="..."
 * event shorthand being misread as Laravel's @error validation directive)
 * that Blade::compileString() alone won't surface, since Livewire's
 * ExtendBlade precompiler and the directive expansion only produce broken
 * PHP once fully compiled — compileString() succeeds even when the result
 * doesn't parse. Found via a real staging 500 on membership/payment.blade.php.
 */
test('every Blade view in resources/views compiles to valid PHP', function () {
    $files = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php'));

    $compiler = app('blade.compiler');
    $failures = [];

    foreach ($files as $file) {
        $raw = file_get_contents($file->getPathname());

        try {
            $compiled = $compiler->compileString($raw);
        } catch (Throwable $e) {
            $failures[$file->getRelativePathname()] = 'compile error: '.$e->getMessage();

            continue;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'blade_lint_').'.php';
        file_put_contents($tmpFile, $compiled);

        $result = Process::run(['php', '-l', $tmpFile]);

        if (! $result->successful()) {
            $failures[$file->getRelativePathname()] = trim($result->errorOutput());
        }

        unlink($tmpFile);
    }

    expect($failures)->toBe([]);
});
