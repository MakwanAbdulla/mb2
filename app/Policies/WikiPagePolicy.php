<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Wiki\WikiPage;
use App\Models\Wiki\WikiSpace;
use App\Support\WikiAccess;

/**
 * A page is as readable and writable as the space it lives in.
 *
 * Reads $page->space and its group: when checking a list of pages, load them
 * with('space.group') so the checks run no queries per page (see WikiAccess).
 */
class WikiPagePolicy
{
    public function view(?User $user, WikiPage $page): bool
    {
        return WikiAccess::forUser($user)->canRead($page->space);
    }

    public function create(User $user, WikiSpace $space): bool
    {
        return WikiAccess::forUser($user)->canWrite($space);
    }

    public function update(User $user, WikiPage $page): bool
    {
        return WikiAccess::forUser($user)->canWrite($page->space);
    }

    public function delete(User $user, WikiPage $page): bool
    {
        return WikiAccess::forUser($user)->canWrite($page->space);
    }
}
