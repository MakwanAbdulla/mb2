<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PageResource;
use App\Models\AuditLog;
use App\Models\Content\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Str;

class PageAdminController extends Controller
{
    public function index(Request $request): ResourceCollection
    {
        abort_unless($request->user()->hasAnyPermission(['pages.view', 'pages.manage']), 403);

        $pages = Page::withTrashed()
            ->when(! $request->boolean('include_deleted'), fn ($q) => $q->withoutTrashed())
            ->orderBy('slug')
            ->get();

        return PageResource::collection($pages);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('pages.manage'), 403);

        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'slug'        => 'nullable|string|max:255|unique:pages,slug',
            'subtitle'    => 'nullable|string|max:500',
            'path_prefix' => 'nullable|string|max:100',
            'content'     => 'nullable|string',
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['title']);

        $page = Page::create([...$data, 'is_published' => false]);

        AuditLog::record('page_created', 'cms', $page->title, $page);

        return (new PageResource($page))->response()->setStatusCode(201);
    }

    public function update(Request $request, Page $page): PageResource
    {
        abort_unless($request->user()->hasPermissionTo('pages.manage'), 403);

        $data = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'subtitle'    => 'nullable|string|max:500',
            'content'     => 'nullable|string',
            'path_prefix' => 'nullable|string|max:100',
        ]);

        $page->update($data);

        AuditLog::record('page_updated', 'cms', $page->title, $page);

        return new PageResource($page);
    }

    public function publish(Request $request, Page $page): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('pages.manage'), 403);

        $page->update(['is_published' => true, 'published_at' => now()]);

        AuditLog::record('page_published', 'cms', $page->title, $page);

        return (new PageResource($page))->response();
    }

    public function unpublish(Request $request, Page $page): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('pages.manage'), 403);
        abort_unless(! in_array($page->slug, Page::PROTECTED_SLUGS), 422, 'Protected pages cannot be unpublished.');

        $page->update(['is_published' => false]);

        AuditLog::record('page_unpublished', 'cms', $page->title, $page);

        return (new PageResource($page))->response();
    }

    public function destroy(Request $request, Page $page): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('pages.manage'), 403);
        abort_unless(! in_array($page->slug, Page::PROTECTED_SLUGS), 422, 'Protected pages cannot be deleted.');

        $name = $page->title;
        $page->delete();

        AuditLog::record('page_deleted', 'cms', $name, null);

        return response()->json(['message' => 'Page deleted.']);
    }
}
