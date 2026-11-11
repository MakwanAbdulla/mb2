<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NavigationItemResource;
use App\Models\AuditLog;
use App\Models\Content\NavigationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NavigationAdminController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('pages.manage'), 403);

        $data = $request->validate([
            'label'        => 'required|string|max:100',
            'location'     => 'required|in:topnav,footer',
            'url'          => 'nullable|string|max:500',
            'route_name'   => 'nullable|string|max:100',
            'icon'         => 'nullable|string|max:100',
            'parent_id'    => 'nullable|exists:navigation_items,id',
            'order'        => 'integer',
            'permission'   => 'nullable|string|max:100',
            'requires_auth' => 'boolean',
            'is_active'    => 'boolean',
        ]);

        $item = NavigationItem::create($data);

        AuditLog::record('nav_item_created', 'cms', $item->label, $item);

        return (new NavigationItemResource($item))->response()->setStatusCode(201);
    }

    public function update(Request $request, NavigationItem $navigationItem): NavigationItemResource
    {
        abort_unless($request->user()->hasPermissionTo('pages.manage'), 403);

        $data = $request->validate([
            'label'        => 'sometimes|string|max:100',
            'location'     => 'sometimes|in:topnav,footer',
            'url'          => 'nullable|string|max:500',
            'route_name'   => 'nullable|string|max:100',
            'icon'         => 'nullable|string|max:100',
            'parent_id'    => 'nullable|exists:navigation_items,id',
            'order'        => 'integer',
            'permission'   => 'nullable|string|max:100',
            'requires_auth' => 'boolean',
            'is_active'    => 'boolean',
        ]);

        $navigationItem->update($data);

        AuditLog::record('nav_item_updated', 'cms', $navigationItem->label, $navigationItem);

        return new NavigationItemResource($navigationItem);
    }

    public function destroy(Request $request, NavigationItem $navigationItem): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('pages.manage'), 403);

        $name = $navigationItem->label;
        $navigationItem->delete();

        AuditLog::record('nav_item_deleted', 'cms', $name, null);

        return response()->json(['message' => 'Navigation item deleted.']);
    }
}
