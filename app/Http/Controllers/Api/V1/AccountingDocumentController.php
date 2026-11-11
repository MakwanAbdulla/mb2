<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Accounting\AccountingDocument;
use App\Models\Accounting\FiscalYear;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingDocumentController extends Controller
{
    // GET /api/v1/accounting/documents
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $docs = AccountingDocument::query()
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->input('fiscal_year_id'), fn ($q, $v) => $q->where('fiscal_year_id', (int) $v))
            ->when($request->input('linked') === 'unlinked', fn ($q) => $q->whereNull('journal_entry_id'))
            ->when($request->input('linked') === 'linked',   fn ($q) => $q->whereNotNull('journal_entry_id'))
            ->latest()
            ->paginate(50);

        $data = $docs->map(fn ($d) => $this->toArray($d));

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $docs->currentPage(),
                'last_page'    => $docs->lastPage(),
                'per_page'     => $docs->perPage(),
                'total'        => $docs->total(),
            ],
        ]);
    }

    // POST /api/v1/accounting/documents
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'file'             => 'required|file|max:20480|mimes:pdf,jpg,jpeg,png,gif,webp',
            'type'             => 'required|in:invoice_attachment,receipt,contract,other',
            'description'      => 'nullable|string|max:500',
            'fiscal_year_id'   => 'nullable|exists:fiscal_years,id',
            'journal_entry_id' => 'nullable|exists:journal_entries,id',
        ]);

        $file = $request->file('file');
        $ext  = $file->getClientOriginalExtension();
        $year = isset($data['fiscal_year_id'])
            ? (FiscalYear::find($data['fiscal_year_id'])?->starts_at->year ?? now()->year)
            : now()->year;

        $path = AccountingDocument::buildPath($ext, $year);
        $file->storeAs(dirname($path), basename($path), 'local');

        $doc = AccountingDocument::create([
            'type'             => $data['type'],
            'description'      => $data['description'] ?? null,
            'fiscal_year_id'   => $data['fiscal_year_id'] ?? null,
            'journal_entry_id' => $data['journal_entry_id'] ?? null,
            'disk'             => 'local',
            'path'             => $path,
            'original_name'    => $file->getClientOriginalName(),
            'mime_type'        => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes'       => $file->getSize(),
            'uploaded_by'      => $request->user()->id,
            'linked_at'        => isset($data['journal_entry_id']) ? now() : null,
        ]);

        $filename = $file->getClientOriginalName();
        AuditLog::record('accounting_document_uploaded', 'accounting', $doc->description ?? $filename, $doc);

        return response()->json($this->toArray($doc), 201);
    }

    // POST /api/v1/accounting/documents/{document}/link
    public function link(Request $request, AccountingDocument $document): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'journal_entry_id' => 'required|exists:journal_entries,id',
        ]);

        $document->journal_entry_id = $data['journal_entry_id'];
        $document->linked_at        = now();
        $document->save();

        AuditLog::record('accounting_document_linked', 'accounting', $document->description, $document);

        return response()->json($this->toArray($document));
    }

    // DELETE /api/v1/accounting/documents/{document}/link
    public function unlink(Request $request, AccountingDocument $document): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $document->journal_entry_id = null;
        $document->linked_at        = null;
        $document->save();

        AuditLog::record('accounting_document_unlinked', 'accounting', $document->description, $document);

        return response()->json($this->toArray($document));
    }

    // DELETE /api/v1/accounting/documents/{document}
    public function destroy(Request $request, AccountingDocument $document): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $label = $document->description ?? $document->original_name;
        $document->delete(); // deletes file from disk + DB record

        AuditLog::record('accounting_document_deleted', 'accounting', $label);

        return response()->json(null, 204);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function toArray(AccountingDocument $doc): array
    {
        return [
            'id'               => $doc->id,
            'type'             => $doc->type,
            'description'      => $doc->description,
            'filename'         => basename($doc->path),
            'disk'             => $doc->disk,
            'path'             => $doc->path,
            'mime_type'        => $doc->mime_type,
            'size_bytes'       => $doc->size_bytes,
            'fiscal_year_id'   => $doc->fiscal_year_id,
            'journal_entry_id' => $doc->journal_entry_id,
            'created_at'       => $doc->created_at?->toIso8601String(),
        ];
    }
}
