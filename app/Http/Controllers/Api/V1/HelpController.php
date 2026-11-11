<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\HelpSectionResource;
use App\Models\Content\HelpSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HelpController extends Controller
{
    /**
     * GET /api/v1/help
     * List all help groups with section counts.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $counts = HelpSection::query()
            ->when(! $user, fn ($q) => $q->where('show_public', true))
            ->when($user, fn ($q) => $q->where(fn ($q) => $q
                ->where('show_public', true)
                ->orWhere('show_auth', true)
            ))
            ->selectRaw('group_label, count(*) as cnt')
            ->groupBy('group_label')
            ->pluck('cnt', 'group_label');

        $groups = HelpSection::groups()
            ->filter(fn ($g) => $counts->has($g['label']) || $counts->has($g['title']))
            ->map(fn ($g) => [
                'slug'          => $g['slug'],
                'title'         => $g['title'],
                'description'   => $g['description'],
                'icon'          => $g['icon'],
                'section_count' => $counts->get($g['label'], $counts->get($g['title'], 0)),
                'requires_auth' => $g['permission'] !== null,
            ])
            ->values();

        return response()->json(['data' => $groups]);
    }

    /**
     * GET /api/v1/help/{groupSlug}
     * List help sections for a specific group.
     */
    public function show(Request $request, string $groupSlug): JsonResponse
    {
        $group = HelpSection::groupBySlug($groupSlug);

        if (! $group) {
            return response()->json(['message' => 'Help group not found.'], 404);
        }

        $user = $request->user();

        if ($group['permission'] && (! $user || ! $user->hasPermissionTo($group['permission']))) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $sections = HelpSection::where('group_label', $group['label'])
            ->when(! $user, fn ($q) => $q->where('show_public', true))
            ->when($user, fn ($q) => $q->where(fn ($q) => $q
                ->where('show_public', true)
                ->orWhere('show_auth', true)
            ))
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        return response()->json([
            'data' => [
                'slug'        => $group['slug'],
                'title'       => $group['title'],
                'description' => $group['description'],
                'icon'        => $group['icon'],
                'sections'    => HelpSectionResource::collection($sections),
            ],
        ]);
    }
}
