<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingAgendaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeetingAgendaItemController extends Controller
{
    public function index(Request $request, Meeting $meeting): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['meetings.view', 'meetings.manage']), 403);

        $items = $meeting->agendaItems()->with('polls.options')->orderBy('sort_order')->get();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request, Meeting $meeting): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('meetings.manage'), 403);

        $data = $request->validate([
            'title'            => 'required|string|max:255',
            'description'      => 'nullable|string',
            'item_type'        => 'required|string|max:50',
            'sort_order'       => 'integer',
            'is_required'      => 'boolean',
            'minutes_template' => 'nullable|string',
        ]);

        $item = $meeting->agendaItems()->create($data);

        AuditLog::record('meeting_agenda_item_created', 'meetings', $meeting->title . ': ' . $item->title, $item);

        return response()->json(['data' => $item], 201);
    }

    public function update(Request $request, Meeting $meeting, MeetingAgendaItem $agendaItem): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('meetings.manage'), 403);
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        $data = $request->validate([
            'title'            => 'sometimes|string|max:255',
            'description'      => 'nullable|string',
            'item_type'        => 'sometimes|string|max:50',
            'sort_order'       => 'integer',
            'is_required'      => 'boolean',
            'minutes_template' => 'nullable|string',
        ]);

        $agendaItem->update($data);

        AuditLog::record('meeting_agenda_item_updated', 'meetings', $meeting->title . ': ' . $agendaItem->title, $agendaItem);

        return response()->json(['data' => $agendaItem]);
    }

    public function destroy(Request $request, Meeting $meeting, MeetingAgendaItem $agendaItem): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('meetings.manage'), 403);
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);

        $itemTitle = $agendaItem->title;
        $agendaItem->delete();

        AuditLog::record('meeting_agenda_item_deleted', 'meetings', $meeting->title . ': ' . $itemTitle, null);

        return response()->json(['message' => 'Agenda item deleted.']);
    }
}
