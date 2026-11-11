<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\HelpSectionResource;
use App\Models\AuditLog;
use App\Models\Content\HelpSection;
use App\Models\Content\HelpText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Str;

class HelpAdminController extends Controller
{
    // GET /api/v1/admin/help/sections  — all incl. hidden
    public function sections(Request $request): ResourceCollection
    {
        abort_unless($request->user()->hasPermissionTo('help.manage'), 403);

        $sections = HelpSection::orderBy('group_label')->orderBy('sort_order')->get();

        return HelpSectionResource::collection($sections);
    }

    // POST /api/v1/admin/help/sections
    public function storeSection(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('help.manage'), 403);

        $data = $request->validate([
            'title'               => 'required|string|max:255',
            'group_label'         => 'required|string|max:255',
            'content'             => 'nullable|string',
            'show_public'         => 'boolean',
            'show_auth'           => 'boolean',
            'required_permission' => 'nullable|string|max:100',
            'sort_order'          => 'integer|min:0',
        ]);

        $data['slug'] = Str::slug($data['title']);

        $section = HelpSection::create($data);

        AuditLog::record('help_section_created', 'cms', $section->title, $section);

        return (new HelpSectionResource($section))->response()->setStatusCode(201);
    }

    // PATCH /api/v1/admin/help/sections/{section}
    public function updateSection(Request $request, HelpSection $helpSection): HelpSectionResource
    {
        abort_unless($request->user()->hasPermissionTo('help.manage'), 403);

        $data = $request->validate([
            'title'               => 'sometimes|string|max:255',
            'group_label'         => 'sometimes|string|max:255',
            'content'             => 'nullable|string',
            'show_public'         => 'boolean',
            'show_auth'           => 'boolean',
            'required_permission' => 'nullable|string|max:100',
            'sort_order'          => 'integer|min:0',
        ]);

        if (isset($data['title'])) {
            $data['slug'] = Str::slug($data['title']);
        }

        $helpSection->update($data);

        AuditLog::record('help_section_updated', 'cms', $helpSection->title, $helpSection);

        return new HelpSectionResource($helpSection);
    }

    // DELETE /api/v1/admin/help/sections/{section}
    public function destroySection(Request $request, HelpSection $helpSection): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('help.manage'), 403);

        $name = $helpSection->title;
        $helpSection->delete();

        AuditLog::record('help_section_deleted', 'cms', $name, null);

        return response()->json(null, 204);
    }

    // GET /api/v1/admin/help/texts
    public function texts(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('help.manage'), 403);

        $texts = HelpText::orderBy('group')->orderBy('field_label')->get()
            ->map(fn ($t) => [
                'id'           => $t->id,
                'key'          => $t->key,
                'group'        => $t->group,
                'field_label'  => $t->field_label,
                'default_text' => $t->default_text,
                'custom_text'  => $t->custom_text,
                'url'          => $t->url,
                'custom_url'   => $t->custom_url,
            ]);

        return response()->json(['data' => $texts]);
    }

    // PATCH /api/v1/admin/help/texts/{helpText}
    public function updateText(Request $request, HelpText $helpText): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('help.manage'), 403);

        $data = $request->validate([
            'custom_text' => 'nullable|string|max:5000',
            'custom_url'  => 'nullable|string|max:500',
        ]);

        $helpText->update($data);

        AuditLog::record('help_text_updated', 'cms', $helpText->key, $helpText);

        return response()->json([
            'id'           => $helpText->id,
            'key'          => $helpText->key,
            'custom_text'  => $helpText->custom_text,
            'custom_url'   => $helpText->custom_url,
        ]);
    }
}
