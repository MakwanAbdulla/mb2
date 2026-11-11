<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Wiki\WikiSpace;
use App\Support\WikiAccess;

/** Reading follows the space's read_access; managing spaces is wiki.manage. */
class WikiSpacePolicy
{
    public function view(?User $user, WikiSpace $space): bool
    {
        return WikiAccess::forUser($user)->canRead($space);
    }

    public function create(User $user): bool
    {
        return $user->can('wiki.manage');
    }

    public function update(User $user, WikiSpace $space): bool
    {
        return $user->can('wiki.manage');
    }

    public function delete(User $user, WikiSpace $space): bool
    {
        return $user->can('wiki.manage');
    }

    /** Create pages in the space. */
    public function write(User $user, WikiSpace $space): bool
    {
        return WikiAccess::forUser($user)->canWrite($space);
    }
}
