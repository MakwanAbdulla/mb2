<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    // GET /api/v1/admin/audit-log
    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyPermission(['accounting.view', 'admin.access']),
            403
        );

        $entries = AuditLog::with('user')
            ->when($request->input('area'),    fn ($q, $v) => $q->where('area', $v))
            ->when($request->input('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->input('action'),  fn ($q, $v) => $q->where('action', $v))
            ->when($request->input('from'),    fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($request->input('to'),      fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->orderByDesc('created_at')
            ->paginate(50);

        $data = $entries->map(fn ($e) => [
            'id'             => $e->id,
            'user'           => $e->user ? ['id' => $e->user->id, 'name' => $e->user->name] : null,
            'action'         => $e->action,
            'area'           => $e->area,
            'subject'        => $e->subject,
            'auditable_type' => $e->auditable_type,
            'auditable_id'   => $e->auditable_id,
            'changes'        => $e->changes,
            'ip_address'     => $e->ip_address,
            'created_at'     => $e->created_at->toIso8601String(),
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page'    => $entries->lastPage(),
                'per_page'     => $entries->perPage(),
                'total'        => $entries->total(),
            ],
        ]);
    }
}
