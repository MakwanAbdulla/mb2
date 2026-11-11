<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Meeting\MeetingTemplate;
use App\Models\Meeting\MeetingTemplateItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeetingTemplateController extends Controller
{
    private function templateToArray(MeetingTemplate $t): array
    {
        $data = [
            'id'                          => $t->id,
            'name'                        => $t->name,
            'description'                 => $t->description,
            'meeting_type'                => $t->meeting_type,
            'meeting_type_label'          => $t->typeLabel(),
            'is_extraordinary'            => $t->is_extraordinary,
            'default_notice_days'         => $t->default_notice_days,
            'include_absences_in_minutes' => $t->include_absences_in_minutes,
            'section_id'                  => $t->section_id,
            'created_by'                  => $t->created_by,
            'created_at'                  => $t->created_at->toIso8601String(),
        ];

        if ($t->relationLoaded('items')) {
            $data['items'] = $t->items->map(fn ($i) => [
                'id'               => $i->id,
                'sort_order'       => $i->sort_order,
                'title'            => $i->title,
                'description'      => $i->description,
                'minutes_template' => $i->minutes_template,
                'item_type'        => $i->item_type,
                'is_required'      => $i->is_required,
            ]);
        }

        return $data;
    }

    // GET /api/v1/admin/meeting-templates
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('meetings.manage'), 403);

        $templates = MeetingTemplate::with('items')->orderBy('name')->get();

        return response()->json(['data' => $templates->map(fn ($t) => $this->templateToArray($t))]);
    }

    // GET /api/v1/admin/meeting-templates/{template}
    public function show(Request $request, MeetingTemplate $meetingTemplate): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('meetings.manage'), 403);

        return response()->json($this->templateToArray($meetingTemplate->load('items')));
    }

    // POST /api/v1/admin/meeting-templates
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('meetings.manage'), 403);

        $data = $request->validate([
            'name'                        => 'required|string|max:255',
            'description'                 => 'nullable|string|max:2000',
            'meeting_type'                => 'required|in:' . implode(',', array_keys(MeetingTemplate::TYPES)),
            'is_extraordinary'            => 'boolean',
            'default_notice_days'         => 'integer|min:0',
            'include_absences_in_minutes' => 'boolean',
            'section_id'                  => 'nullable|exists:sections,id',
            'items'                       => 'array',
            'items.*.title'               => 'required|string|max:255',
            'items.*.description'         => 'nullable|string|max:2000',
            'items.*.minutes_template'    => 'nullable|string|max:5000',
            'items.*.item_type'           => 'in:' . implode(',', array_keys(MeetingTemplateItem::ITEM_TYPES)),
            'items.*.is_required'         => 'boolean',
            'items.*.sort_order'          => 'integer|min:0',
        ]);

        $items = $data['items'] ?? [];
        unset($data['items']);
        $data['created_by'] = $request->user()->id;

        $template = MeetingTemplate::create($data);

        foreach ($items as $idx => $item) {
            $template->items()->create(array_merge($item, ['sort_order' => $item['sort_order'] ?? $idx]));
        }

        AuditLog::record('meeting_template_created', 'meetings', $template->name, $template);

        return response()->json($this->templateToArray($template->load('items')), 201);
    }

    // PATCH /api/v1/admin/meeting-templates/{template}
    public function update(Request $request, MeetingTemplate $meetingTemplate): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('meetings.manage'), 403);

        $data = $request->validate([
            'name'                        => 'sometimes|string|max:255',
            'description'                 => 'nullable|string|max:2000',
            'meeting_type'                => 'sometimes|in:' . implode(',', array_keys(MeetingTemplate::TYPES)),
            'is_extraordinary'            => 'boolean',
            'default_notice_days'         => 'integer|min:0',
            'include_absences_in_minutes' => 'boolean',
            'section_id'                  => 'nullable|exists:sections,id',
        ]);

        $meetingTemplate->update($data);

        AuditLog::record('meeting_template_updated', 'meetings', $meetingTemplate->name, $meetingTemplate);

        return response()->json($this->templateToArray($meetingTemplate->load('items')));
    }

    // DELETE /api/v1/admin/meeting-templates/{template}
    public function destroy(Request $request, MeetingTemplate $meetingTemplate): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('meetings.manage'), 403);
        abort_if($meetingTemplate->meetings()->exists(), 422, 'Template is in use by meetings.');

        $name = $meetingTemplate->name;
        $meetingTemplate->delete();

        AuditLog::record('meeting_template_deleted', 'meetings', $name, null);

        return response()->json(null, 204);
    }

    // POST /api/v1/admin/meeting-templates/{template}/items
    public function storeItem(Request $request, MeetingTemplate $meetingTemplate): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('meetings.manage'), 403);

        $data = $request->validate([
            'title'            => 'required|string|max:255',
            'description'      => 'nullable|string|max:2000',
            'minutes_template' => 'nullable|string|max:5000',
            'item_type'        => 'in:' . implode(',', array_keys(MeetingTemplateItem::ITEM_TYPES)),
            'is_required'      => 'boolean',
            'sort_order'       => 'integer|min:0',
        ]);

        $data['sort_order'] ??= $meetingTemplate->items()->max('sort_order') + 1;

        $item = $meetingTemplate->items()->create($data);

        AuditLog::record('meeting_template_item_created', 'meetings', $meetingTemplate->name . ': ' . $item->title, $item);

        return response()->json([
            'id'               => $item->id,
            'title'            => $item->title,
            'description'      => $item->description,
            'minutes_template' => $item->minutes_template,
            'item_type'        => $item->item_type,
            'is_required'      => $item->is_required,
            'sort_order'       => $item->sort_order,
        ], 201);
    }

    // PATCH /api/v1/admin/meeting-templates/{template}/items/{item}
    public function updateItem(Request $request, MeetingTemplate $meetingTemplate, MeetingTemplateItem $meetingTemplateItem): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('meetings.manage'), 403);
        abort_unless($meetingTemplateItem->template_id === $meetingTemplate->id, 404);

        $data = $request->validate([
            'title'            => 'sometimes|string|max:255',
            'description'      => 'nullable|string|max:2000',
            'minutes_template' => 'nullable|string|max:5000',
            'item_type'        => 'sometimes|in:' . implode(',', array_keys(MeetingTemplateItem::ITEM_TYPES)),
            'is_required'      => 'boolean',
            'sort_order'       => 'integer|min:0',
        ]);

        $meetingTemplateItem->update($data);

        AuditLog::record('meeting_template_item_updated', 'meetings', $meetingTemplate->name . ': ' . $meetingTemplateItem->title, $meetingTemplateItem);

        return response()->json([
            'id'               => $meetingTemplateItem->id,
            'title'            => $meetingTemplateItem->title,
            'description'      => $meetingTemplateItem->description,
            'minutes_template' => $meetingTemplateItem->minutes_template,
            'item_type'        => $meetingTemplateItem->item_type,
            'is_required'      => $meetingTemplateItem->is_required,
            'sort_order'       => $meetingTemplateItem->sort_order,
        ]);
    }

    // DELETE /api/v1/admin/meeting-templates/{template}/items/{item}
    public function destroyItem(Request $request, MeetingTemplate $meetingTemplate, MeetingTemplateItem $meetingTemplateItem): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('meetings.manage'), 403);
        abort_unless($meetingTemplateItem->template_id === $meetingTemplate->id, 404);

        $itemTitle = $meetingTemplateItem->title;
        $meetingTemplateItem->delete();

        AuditLog::record('meeting_template_item_deleted', 'meetings', $meetingTemplate->name . ': ' . $itemTitle, null);

        return response()->json(null, 204);
    }
}
