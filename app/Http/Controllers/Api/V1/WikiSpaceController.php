<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WikiSpaceResource;
use App\Models\Wiki\WikiSpace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Public, with optional Bearer auth: guests get public spaces, a token
 * unlocks what that user may read (WikiAccess).
 */
class WikiSpaceController extends Controller
{
    /** Spaces the caller may read, archived ones left out. */
    public function index(Request $request): ResourceCollection
    {
        $spaces = WikiSpace::readableBy($request->user('sanctum'))
            ->active()
            ->with('group:id,name')
            ->withCount('pages')
            ->orderByRaw('group_id is not null')
            ->orderBy('name')
            ->get();

        return WikiSpaceResource::collection($spaces);
    }

    /** One space by slug. 404 also when it can't be read, so closed spaces stay hidden. */
    public function show(Request $request, string $slug): JsonResponse
    {
        $space = WikiSpace::with('group:id,name')->withCount('pages')->where('slug', $slug)->first();

        if (! $space || ! Gate::forUser($request->user('sanctum'))->allows('view', $space)) {
            return response()->json(['message' => 'Wiki space not found.'], 404);
        }

        return (new WikiSpaceResource($space))->response();
    }
}
