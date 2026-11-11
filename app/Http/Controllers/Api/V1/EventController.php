<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Events\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventController extends Controller
{
    /**
     * GET /api/v1/events
     *
     * Returns published events visible to the caller.
     * Visibility rules mirror the public Livewire component.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user   = $request->user();
        $member = $user?->member;

        $memberSectionIds = $member
            ? $member->sections()->pluck('sections.id')->toArray()
            : [];

        $events = Event::query()
            ->where('status', Event::STATUS_PUBLISHED)
            ->where(function ($q) use ($user, $memberSectionIds) {
                // public — always
                $q->where('visibility', Event::VISIBILITY_PUBLIC);

                // members_only — authenticated users
                if ($user) {
                    $q->orWhere('visibility', Event::VISIBILITY_MEMBERS_ONLY);
                }

                // section_only — members of the specific section
                if ($memberSectionIds) {
                    $q->orWhere(function ($q2) use ($memberSectionIds) {
                        $q2->where('visibility', Event::VISIBILITY_SECTION_ONLY)
                           ->whereIn('visibility_section_id', $memberSectionIds);
                    });
                }

                // invite_only: never listed
            })
            ->with(['occurrences', 'priceTiers', 'addons'])
            ->get()
            ->sortBy(fn ($e) => $e->occurrences->min('starts_at'));

        return EventResource::collection($events);
    }

    /**
     * GET /api/v1/events/{slug}
     *
     * Returns a single published event if visible to the caller.
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $event = Event::where('slug', $slug)
            ->where('status', Event::STATUS_PUBLISHED)
            ->with(['occurrences', 'priceTiers', 'addons', 'organizers'])
            ->firstOrFail();

        $user   = $request->user();
        $member = $user?->member;

        $memberSectionIds = $member
            ? $member->sections()->pluck('sections.id')->toArray()
            : [];

        $canView = match ($event->visibility) {
            Event::VISIBILITY_PUBLIC       => true,
            Event::VISIBILITY_MEMBERS_ONLY => $user !== null,
            Event::VISIBILITY_SECTION_ONLY => $user !== null
                && in_array($event->visibility_section_id, $memberSectionIds, true),
            Event::VISIBILITY_INVITE_ONLY  => false,
            default                        => false,
        };

        if (! $canView) {
            return response()->json(['message' => 'Event not visible.'], 403);
        }

        return (new EventResource($event))->response();
    }
}
