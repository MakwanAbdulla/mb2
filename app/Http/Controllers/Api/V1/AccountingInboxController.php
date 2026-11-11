<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Accounting\AccountingInboxItem;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingInboxController extends Controller
{
    // GET /api/v1/accounting/inbox
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.view'), 403);

        $items = AccountingInboxItem::query()
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('received_at')
            ->paginate($request->integer('per_page', 50));

        $data = $items->map(fn ($i) => [
            'id'                         => $i->id,
            'from_email'                 => $i->from_email,
            'from_name'                  => $i->from_name,
            'subject'                    => $i->subject,
            'filename'                   => $i->filename,
            'mime_type'                  => $i->mime_type,
            'size_bytes'                 => $i->size_bytes,
            'received_at'                => $i->received_at?->toIso8601String(),
            'status'                     => $i->status,
            'notes'                      => $i->notes,
            'accounting_document_id'     => $i->accounting_document_id,
            'accounting_inbox_email_id'  => $i->accounting_inbox_email_id,
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
                'per_page'     => $items->perPage(),
                'total'        => $items->total(),
            ],
        ]);
    }

    // PATCH /api/v1/accounting/inbox/{item}
    public function update(Request $request, AccountingInboxItem $accountingInboxItem): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'status' => 'sometimes|in:needs_approval,pending,linked,ignored',
            'notes'  => 'nullable|string|max:2000',
            'accounting_document_id' => 'nullable|exists:accounting_documents,id',
        ]);

        $accountingInboxItem->update($data);

        AuditLog::record('inbox_item_updated', 'accounting', $accountingInboxItem->subject ?? (string) $accountingInboxItem->id, $accountingInboxItem);

        return response()->json(['id' => $accountingInboxItem->id, 'status' => $accountingInboxItem->status]);
    }
}
