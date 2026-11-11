<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Accounting\CorporateCard;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalSeries;
use App\Models\Accounting\JournalTemplate;
use App\Models\Accounting\PaymentProvider;
use App\Models\Accounting\PaymentTerm;
use App\Models\Accounting\PostingRule;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingConfigController extends Controller
{
    // GET /api/v1/accounting/posting-rules
    public function postingRules(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.view'), 403);

        $rules = PostingRule::with(['vendor', 'bankAccount', 'counterAccount', 'debitAccount', 'creditAccount', 'costCenter'])
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($r) => [
                'id'                   => $r->id,
                'name'                 => $r->name,
                'trigger'              => $r->trigger,
                'bank_account_id'      => $r->bank_account_id,
                'vendor_id'            => $r->vendor_id,
                'vendor_name'          => $r->vendor?->name,
                'from_email_pattern'   => $r->from_email_pattern,
                'transaction_type'     => $r->transaction_type,
                'transaction_type_label' => $r->transactionTypeLabel(),
                'amount_min_ore'       => $r->amount_min_ore,
                'amount_max_ore'       => $r->amount_max_ore,
                'keyword'              => $r->keyword,
                'valid_from'           => $r->valid_from?->toDateString(),
                'valid_to'             => $r->valid_to?->toDateString(),
                'counter_account_id'   => $r->counter_account_id,
                'debit_account_id'     => $r->debit_account_id,
                'credit_account_id'    => $r->credit_account_id,
                'cost_center_id'       => $r->cost_center_id,
                'series'               => $r->series,
                'is_active'            => $r->is_active,
                'requires_receipt'     => $r->requires_receipt,
                'sort_order'           => $r->sort_order,
            ]);

        return response()->json(['data' => $rules]);
    }

    // POST /api/v1/accounting/posting-rules
    public function storePostingRule(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'name'                 => 'required|string|max:255',
            'trigger'              => 'required|in:bank_transaction,ap_invoice,inbox_email',
            'bank_account_id'      => 'nullable|exists:bank_accounts,id',
            'vendor_id'            => 'nullable|exists:vendors,id',
            'from_email_pattern'   => 'nullable|string|max:255',
            'transaction_type'     => 'nullable|in:credit,debit,any',
            'amount_min_ore'       => 'nullable|integer',
            'amount_max_ore'       => 'nullable|integer',
            'keyword'              => 'nullable|string|max:255',
            'valid_from'           => 'nullable|date',
            'valid_to'             => 'nullable|date',
            'counter_account_id'   => 'nullable|exists:accounts,id',
            'debit_account_id'     => 'nullable|exists:accounts,id',
            'credit_account_id'    => 'nullable|exists:accounts,id',
            'cost_center_id'       => 'nullable|exists:cost_centers,id',
            'series'               => 'nullable|string|max:10',
            'is_active'            => 'boolean',
            'requires_receipt'     => 'boolean',
            'sort_order'           => 'integer|min:0',
        ]);

        $rule = PostingRule::create($data);

        AuditLog::record('posting_rule_created', 'accounting', $rule->name, $rule);

        return response()->json(['id' => $rule->id, 'name' => $rule->name], 201);
    }

    // PATCH /api/v1/accounting/posting-rules/{rule}
    public function updatePostingRule(Request $request, PostingRule $postingRule): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'name'               => 'sometimes|string|max:255',
            'trigger'            => 'sometimes|in:bank_transaction,ap_invoice,inbox_email',
            'bank_account_id'    => 'nullable|exists:bank_accounts,id',
            'vendor_id'          => 'nullable|exists:vendors,id',
            'from_email_pattern' => 'nullable|string|max:255',
            'transaction_type'   => 'nullable|in:credit,debit,any',
            'amount_min_ore'     => 'nullable|integer',
            'amount_max_ore'     => 'nullable|integer',
            'keyword'            => 'nullable|string|max:255',
            'valid_from'         => 'nullable|date',
            'valid_to'           => 'nullable|date',
            'counter_account_id' => 'nullable|exists:accounts,id',
            'debit_account_id'   => 'nullable|exists:accounts,id',
            'credit_account_id'  => 'nullable|exists:accounts,id',
            'cost_center_id'     => 'nullable|exists:cost_centers,id',
            'series'             => 'nullable|string|max:10',
            'is_active'          => 'boolean',
            'requires_receipt'   => 'boolean',
            'sort_order'         => 'integer|min:0',
        ]);

        $postingRule->update($data);

        AuditLog::record('posting_rule_updated', 'accounting', $postingRule->name, $postingRule);

        return response()->json(['id' => $postingRule->id]);
    }

    // DELETE /api/v1/accounting/posting-rules/{rule}
    public function destroyPostingRule(Request $request, PostingRule $postingRule): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);
        $name = $postingRule->name;
        $postingRule->delete();
        AuditLog::record('posting_rule_deleted', 'accounting', $name, null);
        return response()->json(null, 204);
    }

    // GET /api/v1/accounting/journal-series
    public function journalSeries(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.view'), 403);

        $series = JournalSeries::orderBy('sort_order')->get()
            ->map(fn ($s) => [
                'id'          => $s->id,
                'code'        => $s->code,
                'name'        => $s->name,
                'is_manual'   => $s->is_manual,
                'is_lockable' => $s->is_lockable,
                'is_system'   => $s->is_system,
                'default_for' => $s->default_for,
                'sort_order'  => $s->sort_order,
            ]);

        return response()->json(['data' => $series]);
    }

    // POST /api/v1/accounting/journal-series
    public function storeJournalSeries(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'code'        => 'required|string|max:10|unique:journal_series,code',
            'name'        => 'required|string|max:255',
            'is_manual'   => 'boolean',
            'is_lockable' => 'boolean',
            'default_for' => 'nullable|string|max:50',
            'sort_order'  => 'integer|min:0',
        ]);

        $s = JournalSeries::create($data);
        AuditLog::record('journal_series_created', 'accounting', $s->name, $s);
        return response()->json(['id' => $s->id, 'code' => $s->code], 201);
    }

    // PATCH /api/v1/accounting/journal-series/{series}
    public function updateJournalSeries(Request $request, JournalSeries $journalSeries): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);
        abort_if($journalSeries->is_system, 422, 'Cannot edit system series.');

        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'is_manual'   => 'boolean',
            'is_lockable' => 'boolean',
            'default_for' => 'nullable|string|max:50',
            'sort_order'  => 'integer|min:0',
        ]);

        $journalSeries->update($data);
        AuditLog::record('journal_series_updated', 'accounting', $journalSeries->name, $journalSeries);
        return response()->json(['id' => $journalSeries->id]);
    }

    // GET /api/v1/accounting/journal-templates
    public function journalTemplates(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.view'), 403);

        $templates = JournalTemplate::orderBy('name')->get()
            ->map(fn ($t) => [
                'id'          => $t->id,
                'name'        => $t->name,
                'description' => $t->description,
                'series'      => $t->series,
                'lines'       => $t->lines,
                'is_active'   => $t->is_active,
            ]);

        return response()->json(['data' => $templates]);
    }

    // POST /api/v1/accounting/journal-templates
    public function storeJournalTemplate(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'series'      => 'nullable|string|max:10',
            'lines'       => 'nullable|array',
            'is_active'   => 'boolean',
        ]);

        $t = JournalTemplate::create($data);
        AuditLog::record('journal_template_created', 'accounting', $t->name, $t);
        return response()->json(['id' => $t->id, 'name' => $t->name], 201);
    }

    // PATCH /api/v1/accounting/journal-templates/{template}
    public function updateJournalTemplate(Request $request, JournalTemplate $journalTemplate): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:2000',
            'series'      => 'nullable|string|max:10',
            'lines'       => 'nullable|array',
            'is_active'   => 'boolean',
        ]);

        $journalTemplate->update($data);
        AuditLog::record('journal_template_updated', 'accounting', $journalTemplate->name, $journalTemplate);
        return response()->json(['id' => $journalTemplate->id]);
    }

    // DELETE /api/v1/accounting/journal-templates/{template}
    public function destroyJournalTemplate(Request $request, JournalTemplate $journalTemplate): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);
        $name = $journalTemplate->name;
        $journalTemplate->delete();
        AuditLog::record('journal_template_deleted', 'accounting', $name, null);
        return response()->json(null, 204);
    }

    // GET /api/v1/accounting/payment-providers
    public function paymentProviders(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.view'), 403);

        $providers = PaymentProvider::orderBy('name')->get()
            ->map(fn ($p) => [
                'id'                       => $p->id,
                'name'                     => $p->name,
                'type'                     => $p->type,
                'type_label'               => $p->typeLabel(),
                'account_id'               => $p->account_id,
                'settlement_bank_account_id' => $p->settlement_bank_account_id,
                'is_active'                => $p->is_active,
                'notes'                    => $p->notes,
            ]);

        return response()->json(['data' => $providers]);
    }

    // POST /api/v1/accounting/payment-providers
    public function storePaymentProvider(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'name'                       => 'required|string|max:255',
            'type'                       => 'required|in:online,card_terminal',
            'account_id'                 => 'nullable|exists:accounts,id',
            'settlement_bank_account_id' => 'nullable|exists:bank_accounts,id',
            'is_active'                  => 'boolean',
            'notes'                      => 'nullable|string|max:2000',
        ]);

        $p = PaymentProvider::create($data);
        AuditLog::record('payment_provider_created', 'accounting', $p->name, $p);
        return response()->json(['id' => $p->id, 'name' => $p->name], 201);
    }

    // PATCH /api/v1/accounting/payment-providers/{provider}
    public function updatePaymentProvider(Request $request, PaymentProvider $paymentProvider): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'name'                       => 'sometimes|string|max:255',
            'type'                       => 'sometimes|in:online,card_terminal',
            'account_id'                 => 'nullable|exists:accounts,id',
            'settlement_bank_account_id' => 'nullable|exists:bank_accounts,id',
            'is_active'                  => 'boolean',
            'notes'                      => 'nullable|string|max:2000',
        ]);

        $paymentProvider->update($data);
        AuditLog::record('payment_provider_updated', 'accounting', $paymentProvider->name, $paymentProvider);
        return response()->json(['id' => $paymentProvider->id]);
    }

    // GET /api/v1/accounting/payment-terms
    public function paymentTerms(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.view'), 403);

        $terms = PaymentTerm::orderBy('sort_order')->get()
            ->map(fn ($t) => [
                'id'         => $t->id,
                'code'       => $t->code,
                'name'       => $t->name,
                'due_days'   => $t->due_days,
                'is_active'  => $t->is_active,
                'sort_order' => $t->sort_order,
            ]);

        return response()->json(['data' => $terms]);
    }

    // POST /api/v1/accounting/payment-terms
    public function storePaymentTerm(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'code'       => 'required|string|max:20|unique:payment_terms,code',
            'name'       => 'required|string|max:255',
            'due_days'   => 'required|integer|min:0',
            'is_active'  => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $t = PaymentTerm::create($data);
        AuditLog::record('payment_term_created', 'accounting', $t->name, $t);
        return response()->json(['id' => $t->id, 'code' => $t->code], 201);
    }

    // PATCH /api/v1/accounting/payment-terms/{term}
    public function updatePaymentTerm(Request $request, PaymentTerm $paymentTerm): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'name'       => 'sometimes|string|max:255',
            'due_days'   => 'sometimes|integer|min:0',
            'is_active'  => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $paymentTerm->update($data);
        AuditLog::record('payment_term_updated', 'accounting', $paymentTerm->name, $paymentTerm);
        return response()->json(['id' => $paymentTerm->id]);
    }

    // GET /api/v1/accounting/fiscal-years (admin CRUD extending base read)
    public function storeFiscalYear(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'name'      => 'required|string|max:50',
            'starts_at' => 'required|date',
            'ends_at'   => 'required|date|after:starts_at',
        ]);

        $fy = FiscalYear::create($data);
        AuditLog::record('fiscal_year_created', 'accounting', $fy->name, $fy);
        return response()->json(['id' => $fy->id, 'name' => $fy->name], 201);
    }

    // PATCH /api/v1/accounting/fiscal-years/{fiscalYear}
    public function updateFiscalYear(Request $request, FiscalYear $fiscalYear): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);
        abort_if($fiscalYear->is_locked, 422, 'Fiscal year is locked.');

        $data = $request->validate([
            'name'      => 'sometimes|string|max:50',
            'starts_at' => 'sometimes|date',
            'ends_at'   => 'sometimes|date',
        ]);

        $fiscalYear->update($data);
        AuditLog::record('fiscal_year_updated', 'accounting', $fiscalYear->name, $fiscalYear);
        return response()->json(['id' => $fiscalYear->id]);
    }

    // GET /api/v1/accounting/corporate-cards
    public function corporateCards(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.view'), 403);

        $cards = CorporateCard::with('member')->orderBy('card_reference')->get()
            ->map(fn ($c) => [
                'id'             => $c->id,
                'card_reference' => $c->card_reference,
                'card_number'    => $c->maskedNumber(),
                'issuer'         => $c->issuer,
                'label'          => $c->label,
                'is_active'      => $c->is_active,
                'expires_at'     => $c->expires_at?->toDateString(),
                'member_id'      => $c->member_id,
                'member_name'    => $c->member?->full_name,
            ]);

        return response()->json(['data' => $cards]);
    }

    // POST /api/v1/accounting/corporate-cards
    public function storeCorporateCard(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'member_id'      => 'required|exists:members,id',
            'card_reference' => 'required|string|max:50',
            'card_number'    => 'nullable|string|max:50',
            'issuer'         => 'nullable|string|max:100',
            'label'          => 'nullable|string|max:100',
            'is_active'      => 'boolean',
            'expires_at'     => 'nullable|date',
        ]);

        $card = CorporateCard::create($data);
        AuditLog::record('corporate_card_created', 'accounting', $card->card_reference, $card);
        return response()->json(['id' => $card->id], 201);
    }

    // PATCH /api/v1/accounting/corporate-cards/{card}
    public function updateCorporateCard(Request $request, CorporateCard $corporateCard): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'card_reference' => 'sometimes|string|max:50',
            'card_number'    => 'nullable|string|max:50',
            'issuer'         => 'nullable|string|max:100',
            'label'          => 'nullable|string|max:100',
            'is_active'      => 'boolean',
            'expires_at'     => 'nullable|date',
            'member_id'      => 'sometimes|exists:members,id',
        ]);

        $corporateCard->update($data);
        AuditLog::record('corporate_card_updated', 'accounting', $corporateCard->card_reference, $corporateCard);
        return response()->json(['id' => $corporateCard->id]);
    }
}
