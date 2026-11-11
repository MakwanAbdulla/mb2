<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SectionResource;
use App\Models\AuditLog;
use App\Models\Organization\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class SectionController extends Controller
{
    public function index(Request $request): ResourceCollection
    {
        $sections = Section::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->when($request->boolean('tree'), fn ($q) => $q->whereNull('parent_id')->with('children.children'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return SectionResource::collection($sections);
    }

    public function show(Section $section): SectionResource
    {
        $section->load('children');

        return new SectionResource($section);
    }

    public function store(Request $request): SectionResource
    {
        abort_unless($request->user()->hasPermissionTo('sections.manage'), 403);

        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'slug'            => 'nullable|string|max:255|unique:sections,slug',
            'description'     => 'nullable|string',
            'parent_id'       => 'nullable|exists:sections,id',
            'visibility'      => 'required|in:public,members,private,secret',
            'membership_type' => 'required|in:mandatory,open,application,closed,admin_only',
            'accepts_issues'  => 'boolean',
            'sort_order'      => 'integer',
            'is_active'       => 'boolean',
        ]);

        if (empty($data['slug'])) {
            $data['slug'] = \Str::slug($data['name']);
        }

        $section = Section::create($data);

        AuditLog::record('section_created', 'sections', $section->name, $section);

        return new SectionResource($section);
    }

    public function update(Request $request, Section $section): SectionResource
    {
        abort_unless($request->user()->hasPermissionTo('sections.manage'), 403);

        $data = $request->validate([
            'name'            => 'sometimes|string|max:255',
            'description'     => 'nullable|string',
            'parent_id'       => 'nullable|exists:sections,id',
            'visibility'      => 'sometimes|in:public,members,private,secret',
            'membership_type' => 'sometimes|in:mandatory,open,application,closed,admin_only',
            'accepts_issues'  => 'boolean',
            'sort_order'      => 'integer',
            'is_active'       => 'boolean',
        ]);

        $section->update($data);

        AuditLog::record('section_updated', 'sections', $section->name, $section);

        return new SectionResource($section);
    }

    public function destroy(Request $request, Section $section): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('sections.manage'), 403);
        abort_unless($section->children()->count() === 0, 422, 'Cannot delete a section with children.');
        abort_if($section->hasWikiPages(), 422, 'Cannot delete a section whose wiki has pages. Deactivate it instead.');

        $name = $section->name;
        $section->delete();

        AuditLog::record('section_deleted', 'sections', $name, null);

        return response()->json(['message' => 'Section deleted.']);
    }
}
