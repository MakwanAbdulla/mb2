<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WikiPageResource;
use App\Http\Resources\WikiPageRevisionResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Wiki\WikiPage;
use App\Models\Wiki\WikiSpace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Reading is public with optional Bearer auth (WikiAccess decides); writing
 * needs a token and the same rights as on the web. A space or page that may
 * not be read answers 404, so closed spaces stay hidden.
 */
class WikiPageController extends Controller
{
    /** The space's pages as a flat list (parent_id + sort_order give the tree), without content. */
    public function index(Request $request, string $slug): ResourceCollection
    {
        $space = $this->readableSpace($request->user('sanctum'), $slug);

        $pages = $space->pages()
            ->orderBy('sort_order')->orderBy('title')
            ->get(['id', 'wiki_space_id', 'parent_id', 'title', 'slug', 'sort_order', 'updated_at'])
            ->each->setRelation('space', $space);

        return WikiPageResource::collection($pages);
    }

    public function show(Request $request, string $slug, string $pageSlug): WikiPageResource
    {
        $space = $this->readableSpace($request->user('sanctum'), $slug);

        return new WikiPageResource($this->findPage($space, $pageSlug)->load('updater:id,name'));
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $space = $this->readableSpace($request->user(), $slug);
        abort_unless($request->user()->can('write', $space), 403, 'You may not write in this wiki space.');

        $page = WikiPage::createIn($space, $request->validate(WikiPage::rules($space)), $request->user());
        AuditLog::record('wiki_page_created', 'wiki', "{$space->name}: {$page->title}", $page);

        return (new WikiPageResource($page->setRelation('space', $space)->load('updater:id,name')))
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, string $slug, string $pageSlug): WikiPageResource
    {
        $space = $this->readableSpace($request->user(), $slug);
        $page = $this->findPage($space, $pageSlug);
        abort_unless($request->user()->can('update', $page), 403, 'You may not edit this page.');

        // PATCH: only the fields sent are changed.
        $rules = array_map(fn (array $r) => ['sometimes', ...$r], WikiPage::rules($space, $page));
        $page->updateFrom($request->validate($rules), $request->user());
        AuditLog::record('wiki_page_updated', 'wiki', "{$space->name}: {$page->title}", $page);

        return new WikiPageResource($page->load('updater:id,name'));
    }

    public function destroy(Request $request, string $slug, string $pageSlug): JsonResponse
    {
        $space = $this->readableSpace($request->user(), $slug);
        $page = $this->findPage($space, $pageSlug);
        abort_unless($request->user()->can('delete', $page), 403, 'You may not delete this page.');

        $page->delete();
        AuditLog::record('wiki_page_deleted', 'wiki', "{$space->name}: {$page->title}", $page);

        return response()->json(['message' => 'Wiki page deleted. Its subpages moved up one level.']);
    }

    /** The page's history, newest first, without content. Same read access as the page. */
    public function revisions(Request $request, string $slug, string $pageSlug): ResourceCollection
    {
        $page = $this->findPage($this->readableSpace($request->user('sanctum'), $slug), $pageSlug);

        return WikiPageRevisionResource::collection(
            $page->revisions()->with('user:id,name')
                ->select(['id', 'wiki_page_id', 'title', 'summary', 'user_id', 'created_at'])
                ->paginate(min($request->integer('per_page', 25) ?: 25, 100))
        );
    }

    public function revision(Request $request, string $slug, string $pageSlug, int $revisionId): WikiPageRevisionResource
    {
        $page = $this->findPage($this->readableSpace($request->user('sanctum'), $slug), $pageSlug);
        $revision = $page->revisions()->with('user:id,name')->find($revisionId);

        abort_unless($revision, 404, 'Revision not found.');

        return new WikiPageRevisionResource($revision);
    }

    private function readableSpace(?User $user, string $slug): WikiSpace
    {
        $space = WikiSpace::where('slug', $slug)->first();

        abort_unless($space && Gate::forUser($user)->allows('view', $space), 404, 'Wiki space not found.');

        return $space;
    }

    private function findPage(WikiSpace $space, string $pageSlug): WikiPage
    {
        $page = $space->pages()->where('slug', $pageSlug)->first();

        abort_unless($page, 404, 'Wiki page not found.');

        return $page->setRelation('space', $space);
    }
}
