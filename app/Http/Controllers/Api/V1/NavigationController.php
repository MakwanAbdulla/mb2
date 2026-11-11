<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NavigationItemResource;
use App\Models\Content\NavigationItem;
use Illuminate\Http\Resources\Json\ResourceCollection;

class NavigationController extends Controller
{
    /**
     * Get all active navigation items grouped by location.
     */
    public function index(): ResourceCollection
    {
        $items = NavigationItem::active()
            ->rootItems()
            ->with('children')
            ->orderBy('order')
            ->get();

        return NavigationItemResource::collection($items);
    }
}
