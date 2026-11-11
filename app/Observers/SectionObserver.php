<?php

namespace App\Observers;

use App\Models\Organization\Section;
use App\Models\Wiki\WikiSpace;

// TODO(#168): observe Group once sections become groups.
class SectionObserver
{
    /** Every group gets a wiki space (#195). */
    public function created(Section $section): void
    {
        WikiSpace::forGroup($section);
    }

    /**
     * An empty space goes with its group. One with pages blocks the delete
     * (restrict FK): callers check Section::hasWikiPages() first and explain.
     */
    public function deleting(Section $section): void
    {
        $space = $section->wikiSpace;

        if ($space && ! $space->pages()->withTrashed()->exists()) {
            $space->delete();
        }
    }
}
