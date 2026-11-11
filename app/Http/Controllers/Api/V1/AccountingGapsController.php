<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Accounting\BankAccount;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\Budget;
use App\Models\Accounting\CostCenter;
use App\Models\AuditLog;
use App\Services\Accounting\BankReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingGapsController extends Controller
{
    // GET /api/v1/accounting/bank-accounts
    public function bankAccounts(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.view'), 403);

        $accounts = BankAccount::orderBy('name')->get()
            ->map(fn ($a) => [
                'id'              => $a->id,
                'name'            => $a->name,
                'account_number'  => $a->account_number,
                'clearing_number' => $a->clearing_number,
                'bank_name'       => $a->bank_name,
                'currency'        => $a->currency,
                'is_active'       => $a->is_active,
                'bankgiro'        => $a->bankgiro,
                'plusgiro'        => $a->plusgiro,
                'swish_number'    => $a->swish_number,
                'swish_type'      => $a->swish_type,
                'iban'            => $a->iban,
                'account_id'      => $a->account_id,
                'cost_center_id'  => $a->cost_center_id,
            ]);

        return response()->json(['data' => $accounts]);
    }

    // GET /api/v1/accounting/bank-transactions
    public function bankTransactions(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.view'), 403);

        $txns = BankTransaction::query()
            ->when($request->input('bank_account_id'), fn ($q, $v) => $q->where('bank_account_id', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('from'), fn ($q, $v) => $q->where('booking_date', '>=', $v))
            ->when($request->input('to'), fn ($q, $v) => $q->where('booking_date', '<=', $v))
            ->orderByDesc('booking_date')
            ->paginate($request->integer('per_page', 50));

        $data = $txns->map(fn ($t) => [
            'id'               => $t->id,
            'bank_account_id'  => $t->bank_account_id,
            'booking_date'     => $t->booking_date->toDateString(),
            'transaction_date' => $t->transaction_date?->toDateString(),
            'value_date'       => $t->value_date?->toDateString(),
            'amount'           => $t->amount,
            'balance_ore'      => $t->balance_ore,
            'description'      => $t->description,
            'reference'        => $t->reference,
            'status'           => $t->status,
            'vendor_id'        => $t->vendor_id,
            'invoice_id'       => $t->invoice_id,
            'journal_entry_id' => $t->journal_entry_id,
            'notes'            => $t->notes,
        ]);

        return response()->json([
            'data'  => $data,
            'meta'  => [
                'current_page' => $txns->currentPage(),
                'last_page'    => $txns->lastPage(),
                'per_page'     => $txns->perPage(),
                'total'        => $txns->total(),
            ],
        ]);
    }

    // GET /api/v1/accounting/cost-centers
    public function costCenters(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.view'), 403);

        $centers = CostCenter::with('children')
            ->whereNull('parent_id')
            ->orderBy('code')
            ->get()
            ->map(fn ($c) => $this->costCenterToArray($c));

        return response()->json(['data' => $centers]);
    }

    private function costCenterToArray(CostCenter $c): array
    {
        $data = [
            'id'         => $c->id,
            'code'       => $c->code,
            'name'       => $c->name,
            'type'       => $c->type,
            'is_active'  => $c->is_active,
            'starts_at'  => $c->starts_at?->toDateString(),
            'ends_at'    => $c->ends_at?->toDateString(),
            'parent_id'  => $c->parent_id,
            'notes'      => $c->notes,
        ];

        if ($c->relationLoaded('children')) {
            $data['children'] = $c->children->map(fn ($ch) => $this->costCenterToArray($ch));
        }

        return $data;
    }

    // POST /api/v1/accounting/bank-accounts
    public function storeBankAccount(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'account_id'      => 'required|exists:accounts,id',
            'account_number'  => 'nullable|string|max:50',
            'clearing_number' => 'nullable|string|max:10',
            'bank_name'       => 'nullable|string|max:100',
            'currency'        => 'string|in:SEK',
            'bankgiro'        => 'nullable|string|max:20',
            'plusgiro'        => 'nullable|string|max:20',
            'swish_number'    => 'nullable|string|max:20',
            'swish_type'      => 'nullable|string|max:20',
            'cost_center_id'  => 'nullable|exists:cost_centers,id',
            'iban'            => 'nullable|string|max:34',
            'notes'           => 'nullable|string|max:2000',
            'is_active'       => 'boolean',
        ]);

        $ba = BankAccount::create([
            'name'            => $data['name'],
            'account_id'      => $data['account_id'],
            'account_number'  => $data['account_number'] ?? null,
            'clearing_number' => $data['clearing_number'] ?? null,
            'bank_name'       => $data['bank_name'] ?? null,
            'currency'        => $data['currency'] ?? 'SEK',
            'bankgiro'        => $data['bankgiro'] ?? null,
            'plusgiro'        => $data['plusgiro'] ?? null,
            'swish_number'    => $data['swish_number'] ?? null,
            'swish_type'      => $data['swish_type'] ?? null,
            'cost_center_id'  => $data['cost_center_id'] ?? null,
            'iban'            => $data['iban'] ?? null,
            'notes'           => $data['notes'] ?? null,
            'is_active'       => $data['is_active'] ?? true,
        ]);

        AuditLog::record('bank_account_created', 'accounting', $ba->name, $ba);

        return response()->json(['data' => $this->bankAccountToArray($ba)], 201);
    }

    // PATCH /api/v1/accounting/bank-accounts/{bankAccount}
    public function updateBankAccount(Request $request, BankAccount $bankAccount): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'name'            => 'sometimes|required|string|max:255',
            'account_id'      => 'sometimes|required|exists:accounts,id',
            'account_number'  => 'sometimes|nullable|string|max:50',
            'clearing_number' => 'sometimes|nullable|string|max:10',
            'bank_name'       => 'sometimes|nullable|string|max:100',
            'currency'        => 'sometimes|string|in:SEK',
            'bankgiro'        => 'sometimes|nullable|string|max:20',
            'plusgiro'        => 'sometimes|nullable|string|max:20',
            'swish_number'    => 'sometimes|nullable|string|max:20',
            'swish_type'      => 'sometimes|nullable|string|max:20',
            'cost_center_id'  => 'sometimes|nullable|exists:cost_centers,id',
            'iban'            => 'sometimes|nullable|string|max:34',
            'notes'           => 'sometimes|nullable|string|max:2000',
            'is_active'       => 'sometimes|boolean',
        ]);

        $bankAccount->update($data);

        AuditLog::record('bank_account_updated', 'accounting', $bankAccount->name, $bankAccount);

        return response()->json(['data' => $this->bankAccountToArray($bankAccount)]);
    }

    // DELETE /api/v1/accounting/bank-accounts/{bankAccount}
    public function destroyBankAccount(Request $request, BankAccount $bankAccount): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        if ($bankAccount->transactions()->exists()) {
            abort(422, 'Cannot delete bank account with transactions');
        }

        $name = $bankAccount->name;
        $bankAccount->delete();

        AuditLog::record('bank_account_deleted', 'accounting', $name);

        return response()->json(null, 204);
    }

    private function bankAccountToArray(BankAccount $ba): array
    {
        return [
            'id'              => $ba->id,
            'name'            => $ba->name,
            'account_number'  => $ba->account_number,
            'clearing_number' => $ba->clearing_number,
            'bank_name'       => $ba->bank_name,
            'currency'        => $ba->currency,
            'is_active'       => $ba->is_active,
            'bankgiro'        => $ba->bankgiro,
            'plusgiro'        => $ba->plusgiro,
            'swish_number'    => $ba->swish_number,
            'swish_type'      => $ba->swish_type,
            'iban'            => $ba->iban,
            'notes'           => $ba->notes,
            'account_id'      => $ba->account_id,
            'cost_center_id'  => $ba->cost_center_id,
        ];
    }

    // GET /api/v1/accounting/bank-transactions/{bankTransaction}
    public function showBankTransaction(Request $request, BankTransaction $bankTransaction): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.view'), 403);

        $t = $bankTransaction;
        $t->load(['bankAccount', 'vendor', 'journalEntry']);

        return response()->json(['data' => [
            'id'               => $t->id,
            'bank_account_id'  => $t->bank_account_id,
            'bank_account'     => $t->bankAccount ? [
                'id'   => $t->bankAccount->id,
                'name' => $t->bankAccount->name,
            ] : null,
            'booking_date'     => $t->booking_date?->toDateString(),
            'transaction_date' => $t->transaction_date?->toDateString(),
            'value_date'       => $t->value_date?->toDateString(),
            'amount'           => $t->amount,
            'balance_ore'      => $t->balance_ore,
            'description'      => $t->description,
            'reference'        => $t->reference,
            'status'           => $t->status,
            'vendor_id'        => $t->vendor_id,
            'vendor'           => $t->vendor ? [
                'id'   => $t->vendor->id,
                'name' => $t->vendor->name,
            ] : null,
            'invoice_id'       => $t->invoice_id,
            'journal_entry_id' => $t->journal_entry_id,
            'journal_entry'    => $t->journalEntry ? [
                'id'          => $t->journalEntry->id,
                'description' => $t->journalEntry->description,
                'date'        => $t->journalEntry->date?->toDateString(),
                'series'      => $t->journalEntry->series,
                'number'      => $t->journalEntry->number,
            ] : null,
            'notes'            => $t->notes,
        ]]);
    }

    // PATCH /api/v1/accounting/bank-transactions/{bankTransaction}
    public function updateBankTransaction(Request $request, BankTransaction $bankTransaction): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'notes'     => 'nullable|string|max:2000',
            'vendor_id' => 'nullable|exists:vendors,id',
        ]);

        $bankTransaction->update($data);

        AuditLog::record('bank_transaction_updated', 'accounting', $bankTransaction->description, $bankTransaction);

        return response()->json(['data' => [
            'id'        => $bankTransaction->id,
            'notes'     => $bankTransaction->notes,
            'vendor_id' => $bankTransaction->vendor_id,
            'status'    => $bankTransaction->status,
        ]]);
    }

    // GET /api/v1/accounting/bank-transactions/reconcile-suggestions
    public function reconcileSuggestions(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $validated = $request->validate([
            'bank_account_id' => 'required|exists:bank_accounts,id',
        ]);

        $v = $validated['bank_account_id'];

        $txs = BankTransaction::with(['bankAccount.account'])
            ->where('bank_account_id', $v)
            ->where('status', BankTransaction::STATUS_UNMATCHED)
            ->orderBy('booking_date')
            ->get();

        $service     = new BankReconciliationService();
        $suggestions = $service->suggestBatch($txs);

        $result = $txs->map(function (BankTransaction $tx) use ($suggestions) {
            $s = $suggestions[$tx->id] ?? null;

            return [
                'transaction' => [
                    'id'               => $tx->id,
                    'bank_account_id'  => $tx->bank_account_id,
                    'booking_date'     => $tx->booking_date?->toDateString(),
                    'transaction_date' => $tx->transaction_date?->toDateString(),
                    'amount'           => $tx->amount,
                    'description'      => $tx->description,
                    'reference'        => $tx->reference,
                    'status'           => $tx->status,
                ],
                'suggestion' => $s ? [
                    'entry_id'          => $s['entry']->id,
                    'entry_description' => $s['entry']->description,
                    'entry_date'        => $s['entry']->date?->toDateString(),
                    'series_number'     => ($s['entry']->series ?? '') . ($s['entry']->number ?? ''),
                    'confidence'        => $s['confidence'],
                    'reason'            => $s['reason'],
                    'total_candidates'  => $s['total_candidates'],
                ] : null,
            ];
        });

        return response()->json(['data' => $result]);
    }

    // POST /api/v1/accounting/bank-transactions/{bankTransaction}/confirm-match
    public function confirmMatch(Request $request, BankTransaction $bankTransaction): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $tx = $bankTransaction;

        if ($tx->status !== BankTransaction::STATUS_UNMATCHED) {
            abort(422, 'Transaction is not in unmatched status');
        }

        $service    = new BankReconciliationService();
        $suggestion = $service->suggest($tx);

        if (! $suggestion) {
            abort(422, 'No suggestion available for this transaction');
        }

        // Guard: entry must not have been claimed by another transaction in the meantime
        if (BankTransaction::where('journal_entry_id', $suggestion['entry']->id)
                ->where('id', '!=', $tx->id)->exists()) {
            abort(409, 'Journal entry has already been matched to another transaction');
        }

        $tx->update([
            'journal_entry_id' => $suggestion['entry']->id,
            'status'           => BankTransaction::STATUS_MATCHED,
        ]);

        AuditLog::record('bank_transaction_matched', 'accounting', $tx->description, $tx);

        $tx->refresh();

        return response()->json(['data' => [
            'id'               => $tx->id,
            'status'           => $tx->status,
            'journal_entry_id' => $tx->journal_entry_id,
            'matched_entry'    => [
                'id'          => $suggestion['entry']->id,
                'description' => $suggestion['entry']->description,
                'date'        => $suggestion['entry']->date?->toDateString(),
                'series'      => $suggestion['entry']->series,
                'number'      => $suggestion['entry']->number,
            ],
        ]]);
    }

    // POST /api/v1/accounting/cost-centers
    public function storeCostCenter(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'code'      => 'required|string|max:20|unique:cost_centers,code',
            'name'      => 'required|string|max:255',
            'type'      => 'required|string|in:cost_center,project',
            'parent_id' => 'nullable|exists:cost_centers,id',
            'is_active' => 'boolean',
            'starts_at' => 'nullable|date',
            'ends_at'   => 'nullable|date|after_or_equal:starts_at',
            'notes'     => 'nullable|string|max:2000',
        ]);

        $cc = CostCenter::create($data);

        AuditLog::record('cost_center_created', 'accounting', $cc->code . ' ' . $cc->name, $cc);

        return response()->json(['data' => $this->costCenterToArray($cc)], 201);
    }

    // PATCH /api/v1/accounting/cost-centers/{costCenter}
    public function updateCostCenter(Request $request, CostCenter $costCenter): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'code'      => 'sometimes|string|max:20|unique:cost_centers,code,' . $costCenter->id,
            'name'      => 'sometimes|string|max:255',
            'type'      => 'sometimes|string|in:cost_center,project',
            'parent_id' => 'nullable|exists:cost_centers,id',
            'is_active' => 'boolean',
            'starts_at' => 'nullable|date',
            'ends_at'   => 'nullable|date|after_or_equal:starts_at',
            'notes'     => 'nullable|string|max:2000',
        ]);

        $costCenter->update($data);

        AuditLog::record('cost_center_updated', 'accounting', $costCenter->code . ' ' . $costCenter->name, $costCenter);

        return response()->json(['data' => $this->costCenterToArray($costCenter)]);
    }

    // DELETE /api/v1/accounting/cost-centers/{costCenter}
    public function destroyCostCenter(Request $request, CostCenter $costCenter): \Illuminate\Http\Response
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        if ($costCenter->children()->exists()) {
            abort(422, 'Kostnadsstället har underkonton och kan inte tas bort.');
        }

        $label = $costCenter->code . ' ' . $costCenter->name;
        $costCenter->delete();

        AuditLog::record('cost_center_deleted', 'accounting', $label);

        return response()->noContent();
    }

    // GET /api/v1/accounting/budgets
    public function budgets(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.view'), 403);

        $budgets = Budget::with('fiscalYear')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($b) => [
                'id'             => $b->id,
                'name'           => $b->name,
                'is_active'      => $b->is_active,
                'is_locked'      => $b->is_locked,
                'notes'          => $b->notes,
                'fiscal_year_id' => $b->fiscal_year_id,
                'fiscal_year'    => $b->fiscalYear ? [
                    'id'   => $b->fiscalYear->id,
                    'name' => $b->fiscalYear->name ?? $b->fiscalYear->year,
                ] : null,
                'created_by'     => $b->created_by,
                'created_at'     => $b->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => $budgets]);
    }
}
