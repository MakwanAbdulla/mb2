<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Accounting\AccountingDocument;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\InvoiceLine;
use App\Models\AuditLog;
use App\Services\Accounting\InvoiceZipImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Storage;

class InvoiceController extends Controller
{
    public function index(Request $request): ResourceCollection
    {
        abort_unless($request->user()->hasAnyPermission(['accounting.view', 'accounting.manage']), 403);

        $invoices = Invoice::query()
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('vendor_id'), fn ($q, $v) => $q->where('vendor_id', $v))
            ->with('vendor', 'customer')
            ->orderByDesc('invoice_date')
            ->paginate($request->integer('per_page', 25));

        return InvoiceResource::collection($invoices);
    }

    public function show(Request $request, Invoice $invoice): InvoiceResource
    {
        abort_unless($request->user()->hasAnyPermission(['accounting.view', 'accounting.manage']), 403);

        $invoice->load('vendor', 'customer', 'lines');

        return new InvoiceResource($invoice);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'type'           => 'required|in:payable,receivable',
            'vendor_id'      => 'required_if:type,payable|nullable|exists:vendors,id',
            'customer_id'    => 'required_if:type,receivable|nullable|exists:customers,id',
            'invoice_number' => 'nullable|string|max:100',
            'invoice_date'   => 'required|date',
            'due_date'       => 'nullable|date|after_or_equal:invoice_date',
            'payment_method' => 'nullable|string|max:50',
            'ocr_number'     => 'nullable|string|max:50',
            'notes'          => 'nullable|string|max:2000',
            'lines'          => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:500',
            'lines.*.quantity'    => 'required|numeric|min:0',
            'lines.*.unit_price'  => 'required|integer',
            'lines.*.vat_rate'    => 'required|numeric|min:0|max:100',
            'lines.*.account_id'  => 'required|exists:accounts,id',
        ]);

        $invoice = Invoice::create([
            ...\Arr::except($data, ['lines']),
            'status'     => Invoice::STATUS_DRAFT,
            'currency'   => 'SEK',
            'created_by' => $request->user()->id,
        ]);

        foreach ($data['lines'] as $line) {
            $vatAmount   = (int) round($line['unit_price'] * $line['quantity'] * $line['vat_rate'] / 100);
            $total       = (int) round($line['unit_price'] * $line['quantity']) + $vatAmount;
            $invoice->lines()->create([...$line, 'total' => $total]);
        }

        $invoice->update([
            'amount_ex_vat' => $invoice->lines()->sum(\DB::raw('unit_price * quantity')),
            'vat_amount'    => $invoice->lines()->sum(\DB::raw('ROUND(unit_price * quantity * vat_rate / 100)')),
            'total'         => $invoice->lines()->sum('total'),
        ]);

        AuditLog::record('invoice_created', 'accounting', $invoice->invoice_number ?? (string) $invoice->id, $invoice);

        return (new InvoiceResource($invoice->load('lines')))->response()->setStatusCode(201);
    }

    public function approve(Request $request, Invoice $invoice): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.approve'), 403);
        abort_unless($invoice->status === Invoice::STATUS_PENDING_APPROVAL, 422, 'Invoice cannot be approved in its current state.');
        abort_if($invoice->created_by === $request->user()->id, 403, 'Du kan inte attestera en faktura du själv skickat in.');

        $invoice->update([
            'status'      => Invoice::STATUS_APPROVED,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        AuditLog::record('invoice_approved', 'accounting', $invoice->invoice_number ?? (string) $invoice->id, $invoice);

        return response()->json(['message' => 'Invoice approved.', 'status' => $invoice->status]);
    }

    public function markPaid(Request $request, Invoice $invoice): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);
        abort_unless(in_array($invoice->status, [Invoice::STATUS_APPROVED, 'overdue']), 422, 'Invoice cannot be marked as paid in its current state.');

        $data = $request->validate([
            'paid_at'        => 'nullable|date',
            'payment_method' => 'nullable|string|max:50',
        ]);

        $invoice->update([
            'status'         => 'paid',
            'paid_at'        => $data['paid_at'] ?? now(),
            'payment_method' => $data['payment_method'] ?? $invoice->payment_method,
        ]);

        AuditLog::record('invoice_paid', 'accounting', $invoice->invoice_number ?? (string) $invoice->id, $invoice);

        return response()->json(['message' => 'Invoice marked as paid.', 'status' => $invoice->status]);
    }

    public function cancel(Request $request, Invoice $invoice): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);
        abort_unless(in_array($invoice->status, [Invoice::STATUS_DRAFT, Invoice::STATUS_PENDING_APPROVAL, Invoice::STATUS_APPROVED]), 422, 'Invoice cannot be cancelled.');

        $invoice->update(['status' => 'cancelled']);

        AuditLog::record('invoice_cancelled', 'accounting', $invoice->invoice_number ?? (string) $invoice->id, $invoice);

        return response()->json(['message' => 'Invoice cancelled.', 'status' => $invoice->status]);
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        if (in_array($invoice->status, [Invoice::STATUS_PAID, Invoice::STATUS_CANCELLED])) {
            abort(422, 'Cannot edit a paid or cancelled invoice.');
        }

        $data = $request->validate([
            'vendor_id'      => 'sometimes|exists:vendors,id',
            'customer_id'    => 'sometimes|exists:customers,id',
            'due_date'       => 'sometimes|nullable|date',
            'invoice_number' => 'sometimes|nullable|string|max:100',
            'reference'      => 'sometimes|nullable|string|max:255',
            'notes'          => 'sometimes|nullable|string|max:2000',
            'amount_ore'     => 'sometimes|integer|min:0',
            'cost_center_id' => 'sometimes|nullable|exists:cost_centers,id',
        ]);

        $invoice->update($data);

        AuditLog::record('invoice_updated', 'accounting', $invoice->invoice_number ?? '#' . $invoice->id, $invoice);

        return (new InvoiceResource($invoice->fresh()->load('vendor', 'customer')))->response();
    }

    public function receiptScan(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $request->validate([
            'file' => 'required|file|mimes:jpeg,jpg,png,gif,webp,pdf|max:20480',
        ]);

        $path     = $request->file('file')->store('receipts/temp', 'local');
        $fullPath = storage_path('app/' . $path);
        $mime     = $request->file('file')->getMimeType();

        $result = app(\App\Services\Accounting\ReceiptScanner::class)->scan($fullPath, $mime);

        Storage::disk('local')->delete($path);

        if ($result['error']) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * POST /accounting/invoices/import-zip
     * Bulk-create draft payable invoices from a ZIP of invoice files (PDF/image).
     * Each PDF is read locally (pdftotext + regex — no external AI call) and
     * matched against the vendor register by organisationsnummer; the vendor's
     * default account is used for the line item. Rows without a vendor match,
     * amount, or default account are skipped (not created) and reported in
     * `skipped` — there is no interactive review at the API level, unlike the
     * admin "Importera ZIP" page. Image files can't be read locally (no OCR)
     * and are always skipped here.
     */
    public function importZip(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $request->validate([
            'file' => 'required|file|mimes:zip|max:46080',
        ]);

        $importer = app(InvoiceZipImporter::class);
        $result   = $importer->import($request->file('file')->getRealPath());

        $created = [];
        $skipped = $result['skipped'];

        foreach ($result['candidates'] as $row) {
            if ($row['error'] || ! $row['matched_vendor_id'] || ! $row['amount_ore'] || ! $row['suggested_account_id']) {
                $skipped[] = [
                    'original_name' => $row['original_name'],
                    'reason'        => $row['error']
                        ?? (! $row['matched_vendor_id'] ? 'Ingen leverantör matchade registret' : 'Belopp eller konto kunde inte tolkas'),
                ];
                continue;
            }

            $invoice = Invoice::create([
                'type'          => Invoice::TYPE_PAYABLE,
                'vendor_id'     => $row['matched_vendor_id'],
                'invoice_date'  => $row['date'] ?? now()->toDateString(),
                'amount_ex_vat' => $row['amount_ore'],
                'vat_amount'    => 0,
                'total'         => $row['amount_ore'],
                'currency'      => 'SEK',
                'status'        => Invoice::STATUS_DRAFT,
                'notes'         => 'Importerad från ZIP (' . $row['original_name'] . ')',
                'created_by'    => $request->user()->id,
            ]);

            InvoiceLine::create([
                'invoice_id'    => $invoice->id,
                'account_id'    => $row['suggested_account_id'],
                'description'   => $row['description'] ?: $row['original_name'],
                'amount_ex_vat' => $row['amount_ore'],
                'vat_rate'      => 0,
                'vat_amount'    => 0,
                'sort_order'    => 0,
            ]);

            if (Storage::disk('local')->exists($row['scratch_path'])) {
                $ext  = pathinfo($row['scratch_path'], PATHINFO_EXTENSION);
                $path = AccountingDocument::buildPath($ext);
                Storage::disk('local')->copy($row['scratch_path'], $path);

                AccountingDocument::create([
                    'type'          => AccountingDocument::TYPE_INVOICE_ATTACHMENT,
                    'invoice_id'    => $invoice->id,
                    'disk'          => 'local',
                    'path'          => $path,
                    'original_name' => $row['original_name'],
                    'mime_type'     => $row['mime_type'],
                    'size_bytes'    => $row['size_bytes'],
                    'uploaded_by'   => $request->user()->id,
                ]);
            }

            AuditLog::record('invoice_draft_saved', 'accounting', "#{$invoice->id} (ZIP-import)", $invoice);

            $created[] = ['id' => $invoice->id, 'vendor_id' => $invoice->vendor_id, 'total' => $invoice->total, 'file' => $row['original_name']];
        }

        $importer->discardBatch($result['batch_id']);

        return response()->json(['data' => ['created' => $created, 'skipped' => $skipped]]);
    }
}
