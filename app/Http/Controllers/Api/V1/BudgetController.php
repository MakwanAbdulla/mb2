<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Accounting\Budget;
use App\Models\Accounting\BudgetLine;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    // GET /api/v1/accounting/budgets
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['accounting.view', 'accounting.manage']), 403);

        $budgets = Budget::with('fiscalYear')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($b) => $this->budgetToArray($b));

        return response()->json(['data' => $budgets]);
    }

    // POST /api/v1/accounting/budgets
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'fiscal_year_id' => 'required|exists:fiscal_years,id',
            'name'           => 'required|string|max:255',
            'is_active'      => 'boolean',
            'is_locked'      => 'boolean',
            'notes'          => 'nullable|string',
        ]);

        $budget = Budget::create(array_merge($data, ['created_by' => $request->user()->id]));

        AuditLog::record('budget_created', 'accounting', $budget->name, $budget);

        return response()->json(['data' => $this->budgetToArray($budget->load('fiscalYear'))], 201);
    }

    // GET /api/v1/accounting/budgets/{budget}
    public function show(Request $request, Budget $budget): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['accounting.view', 'accounting.manage']), 403);

        $budget->load('fiscalYear', 'lines.account');

        $data = $this->budgetToArray($budget);
        $data['lines'] = $budget->lines->map(fn ($l) => $this->lineToArray($l));

        return response()->json(['data' => $data]);
    }

    // PATCH /api/v1/accounting/budgets/{budget}
    public function update(Request $request, Budget $budget): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);
        abort_if($budget->is_locked, 422, 'Cannot update a locked budget.');

        $data = $request->validate([
            'name'      => 'sometimes|string|max:255',
            'is_active' => 'boolean',
            'is_locked' => 'boolean',
            'notes'     => 'nullable|string',
        ]);

        $budget->update($data);

        AuditLog::record('budget_updated', 'accounting', $budget->name, $budget);

        return response()->json(['data' => $this->budgetToArray($budget->load('fiscalYear'))]);
    }

    // POST /api/v1/accounting/budgets/{budget}/lines
    public function storeLine(Request $request, Budget $budget): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);
        abort_if($budget->is_locked, 422, 'Cannot add lines to a locked budget.');

        $data = $request->validate([
            'account_id'  => 'required|exists:accounts,id',
            'annual_ore'  => 'required|integer',
            'jan_pct'     => 'nullable|integer|min:0',
            'feb_pct'     => 'nullable|integer|min:0',
            'mar_pct'     => 'nullable|integer|min:0',
            'apr_pct'     => 'nullable|integer|min:0',
            'maj_pct'     => 'nullable|integer|min:0',
            'jun_pct'     => 'nullable|integer|min:0',
            'jul_pct'     => 'nullable|integer|min:0',
            'aug_pct'     => 'nullable|integer|min:0',
            'sep_pct'     => 'nullable|integer|min:0',
            'okt_pct'     => 'nullable|integer|min:0',
            'nov_pct'     => 'nullable|integer|min:0',
            'dec_pct'     => 'nullable|integer|min:0',
        ]);

        $line = $budget->lines()->create(array_merge(
            ['budget_id' => $budget->id],
            $data
        ));

        AuditLog::record('budget_line_created', 'accounting', $budget->name . ' rad ' . $line->id, $line);

        return response()->json(['data' => $this->lineToArray($line->load('account'))], 201);
    }

    // PATCH /api/v1/accounting/budgets/{budget}/lines/{line}
    public function updateLine(Request $request, Budget $budget, BudgetLine $line): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);
        abort_if($budget->is_locked, 422, 'Cannot update lines on a locked budget.');
        abort_unless($line->budget_id === $budget->id, 404);

        $data = $request->validate([
            'annual_ore'  => 'sometimes|integer',
            'jan_pct'     => 'nullable|integer|min:0',
            'feb_pct'     => 'nullable|integer|min:0',
            'mar_pct'     => 'nullable|integer|min:0',
            'apr_pct'     => 'nullable|integer|min:0',
            'maj_pct'     => 'nullable|integer|min:0',
            'jun_pct'     => 'nullable|integer|min:0',
            'jul_pct'     => 'nullable|integer|min:0',
            'aug_pct'     => 'nullable|integer|min:0',
            'sep_pct'     => 'nullable|integer|min:0',
            'okt_pct'     => 'nullable|integer|min:0',
            'nov_pct'     => 'nullable|integer|min:0',
            'dec_pct'     => 'nullable|integer|min:0',
        ]);

        $line->update($data);

        AuditLog::record('budget_line_updated', 'accounting', $budget->name . ' rad ' . $line->id, $line);

        return response()->json(['data' => $this->lineToArray($line->load('account'))]);
    }

    // DELETE /api/v1/accounting/budgets/{budget}/lines/{line}
    public function destroyLine(Request $request, Budget $budget, BudgetLine $line): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);
        abort_if($budget->is_locked, 422, 'Cannot delete lines from a locked budget.');
        abort_unless($line->budget_id === $budget->id, 404);

        $label = $budget->name . ' rad ' . $line->id;
        $line->delete();

        AuditLog::record('budget_line_deleted', 'accounting', $label);

        return response()->json(null, 204);
    }

    private function budgetToArray(Budget $b): array
    {
        return [
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
        ];
    }

    private function lineToArray(BudgetLine $l): array
    {
        return [
            'id'          => $l->id,
            'budget_id'   => $l->budget_id,
            'account_id'  => $l->account_id,
            'account'     => $l->account ? [
                'id'   => $l->account->id,
                'code' => $l->account->code,
                'name' => $l->account->name,
            ] : null,
            'annual_ore'  => $l->annual_ore,
            'jan_pct'     => $l->jan_pct,
            'feb_pct'     => $l->feb_pct,
            'mar_pct'     => $l->mar_pct,
            'apr_pct'     => $l->apr_pct,
            'maj_pct'     => $l->maj_pct,
            'jun_pct'     => $l->jun_pct,
            'jul_pct'     => $l->jul_pct,
            'aug_pct'     => $l->aug_pct,
            'sep_pct'     => $l->sep_pct,
            'okt_pct'     => $l->okt_pct,
            'nov_pct'     => $l->nov_pct,
            'dec_pct'     => $l->dec_pct,
        ];
    }
}
