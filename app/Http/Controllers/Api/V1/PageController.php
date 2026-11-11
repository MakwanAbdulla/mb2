<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PageResource;
use App\Models\Content\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;

class PageController extends Controller
{
    /**
     * List all published pages.
     */
    public function index(): ResourceCollection
    {
        $pages = Page::published()
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        return PageResource::collection($pages);
    }

    /**
     * Get a single published page by slug.
     */
    public function show(string $slug): JsonResponse
    {
        $page = Page::published()->where('slug', $slug)->first();

        if (! $page) {
            return response()->json(['message' => 'Page not found.'], 404);
        }

        return (new PageResource($page))->response();
    }
}
