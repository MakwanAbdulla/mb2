<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * route('name') with a name that doesn't exist only fails when that branch
 * of the code runs, so it can sit unnoticed until someone opens the page or
 * triggers the mail. Found via #248 (journal entries linked to
 * admin.accounting.invoices.show) and #251 (FamilyInvitationMail linked to
 * family.overview).
 *
 * Only names passed directly as a string literal are checked — not ones
 * built at runtime or chosen with a ternary.
 */
function literalRouteNames(string $source): array
{
    // The route() helper only — not $request->route() or Notification::route()
    preg_match_all("/(?<![\w>:$])route\(\s*'([\w.-]+)'\s*[,)]/", $source, $found);

    return array_unique($found[1]);
}

/**
 * PHP source with comments removed, so commented-out code isn't checked.
 */
function withoutComments(string $source): string
{
    return collect(token_get_all($source))
        ->reject(fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn ($token) => is_array($token) ? $token[1] : $token)
        ->implode('');
}

test('every literal route name used in a view exists', function () {
    $files = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file) => str_ends_with($file->getFilename(), '.php'));

    $failures = [];

    foreach ($files as $file) {
        foreach (literalRouteNames(file_get_contents($file->getPathname())) as $name) {
            if (! Route::has($name)) {
                $failures[] = $file->getRelativePathname().': '.$name;
            }
        }
    }

    expect($failures)->toBe([]);
});

test('every literal route name used in app code exists', function () {
    $files = collect(File::allFiles(app_path()))
        ->filter(fn ($file) => $file->getExtension() === 'php');

    $failures = [];

    foreach ($files as $file) {
        $source = withoutComments(file_get_contents($file->getPathname()));

        foreach (literalRouteNames($source) as $name) {
            if (! Route::has($name)) {
                $failures[] = 'app/'.$file->getRelativePathname().': '.$name;
            }
        }
    }

    expect($failures)->toBe([]);
});
