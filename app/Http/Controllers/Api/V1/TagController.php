<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['tags.view', 'tags.manage']), 403);

        $tags = Tag::orderBy('name')->get(['id', 'name', 'slug']);

        return response()->json(['data' => $tags]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('tags.manage'), 403);

        $data = $request->validate(['name' => 'required|string|max:100|unique:tags,name']);

        $tag = Tag::findOrCreateByName($data['name']);

        AuditLog::record('tag_created', 'members', $tag->name, $tag);

        return response()->json(['data' => $tag], 201);
    }

    public function destroy(Request $request, Tag $tag): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('tags.manage'), 403);

        $name = $tag->name;
        $tag->delete();

        AuditLog::record('tag_deleted', 'members', $name, null);

        return response()->json(['message' => 'Tag deleted.']);
    }
}
