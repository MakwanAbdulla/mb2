<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NoticeResource;
use App\Models\AuditLog;
use App\Models\Content\Notice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class NoticeController extends Controller
{
    // GET /api/v1/notices  — auth optional; guests see is_public only
    public function index(Request $request): ResourceCollection
    {
        $user = $request->user();

        $notices = Notice::query()
            ->active()
            ->when(! $user, fn ($q) => $q->where('is_public', true))
            ->orderBy('starts_at')
            ->get();

        return NoticeResource::collection($notices);
    }

    // POST /api/v1/admin/notices
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('notices.manage'), 403);

        $data = $request->validate([
            'title'     => 'nullable|string|max:255',
            'message'   => 'required|string|max:5000',
            'type'      => 'required|string|in:info,warning,success,danger',
            'starts_at' => 'required|date',
            'ends_at'   => 'required|date|after_or_equal:starts_at',
            'duration'  => 'nullable|integer|min:1',
            'is_public' => 'boolean',
        ]);

        $notice = Notice::create([
            ...$data,
            'user_id'   => $request->user()->id,
            'duration'  => $data['duration'] ?? 0,
            'is_public' => $data['is_public'] ?? false,
        ]);

        AuditLog::record('notice_created', 'cms', $notice->title ?? $notice->message, $notice);

        return (new NoticeResource($notice))->response()->setStatusCode(201);
    }

    // PATCH /api/v1/admin/notices/{notice}
    public function update(Request $request, Notice $notice): NoticeResource
    {
        abort_unless($request->user()->hasPermissionTo('notices.manage'), 403);

        $data = $request->validate([
            'title'     => 'nullable|string|max:255',
            'message'   => 'sometimes|string|max:5000',
            'type'      => 'sometimes|string|in:info,warning,success,danger',
            'starts_at' => 'sometimes|date',
            'ends_at'   => 'sometimes|date',
            'duration'  => 'nullable|integer|min:1',
            'is_public' => 'boolean',
        ]);

        $notice->update($data);

        AuditLog::record('notice_updated', 'cms', $notice->title ?? $notice->message, $notice);

        return new NoticeResource($notice);
    }

    // DELETE /api/v1/admin/notices/{notice}
    public function destroy(Request $request, Notice $notice): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('notices.manage'), 403);

        $name = $notice->title ?? $notice->message;
        $notice->delete();

        AuditLog::record('notice_deleted', 'cms', $name, null);

        return response()->json(null, 204);
    }

    // GET /api/v1/admin/notices  — all notices (incl. past), for management
    public function adminIndex(Request $request): ResourceCollection
    {
        abort_unless($request->user()->hasPermissionTo('notices.manage'), 403);

        $notices = Notice::query()
            ->when($request->input('active_only'), fn ($q) => $q->active())
            ->orderByDesc('starts_at')
            ->get();

        return NoticeResource::collection($notices);
    }
}
