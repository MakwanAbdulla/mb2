<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Accounting\ApprovalRule;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalRuleController extends Controller
{
    private function ruleToArray(ApprovalRule $rule): array
    {
        return [
            'id'                  => $rule->id,
            'name'                => $rule->name,
            'invoice_type'        => $rule->invoice_type,
            'amount_min'          => $rule->amount_min,
            'amount_max'          => $rule->amount_max,
            'required_approvals'  => $rule->required_approvals,
            'approver_permission' => $rule->approver_permission,
            'block_submitter'     => $rule->block_submitter,
            'block_beneficiary'   => $rule->block_beneficiary,
            'is_active'           => $rule->is_active,
            'sort_order'          => $rule->sort_order,
        ];
    }

    // GET /api/v1/accounting/approval-rules
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.admin'), 403);

        $rules = ApprovalRule::orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($rule) => $this->ruleToArray($rule));

        return response()->json(['data' => $rules]);
    }

    // POST /api/v1/accounting/approval-rules
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.admin'), 403);

        $data = $request->validate([
            'name'                => 'required|string|max:100',
            'invoice_type'        => 'nullable|in:payable,receivable,expense',
            'amount_min'          => 'nullable|integer|min:0',
            'amount_max'          => 'nullable|integer|min:0',
            'required_approvals'  => 'required|integer|min:1|max:10',
            'approver_permission' => 'required|string|max:100',
            'block_submitter'     => 'boolean',
            'block_beneficiary'   => 'boolean',
            'is_active'           => 'boolean',
            'sort_order'          => 'integer|min:0',
        ]);

        $data['block_submitter']   = $data['block_submitter']   ?? true;
        $data['block_beneficiary'] = $data['block_beneficiary'] ?? true;
        $data['is_active']         = $data['is_active']         ?? true;
        $data['sort_order']        = $data['sort_order']        ?? 0;

        $rule = ApprovalRule::create($data);

        AuditLog::record('approval_rule_created', 'accounting', $rule->name, $rule);

        return response()->json(['data' => $this->ruleToArray($rule)], 201);
    }

    // PATCH /api/v1/accounting/approval-rules/{approvalRule}
    public function update(Request $request, ApprovalRule $approvalRule): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.admin'), 403);

        $data = $request->validate([
            'name'                => 'sometimes|required|string|max:100',
            'invoice_type'        => 'sometimes|nullable|in:payable,receivable,expense',
            'amount_min'          => 'sometimes|nullable|integer|min:0',
            'amount_max'          => 'sometimes|nullable|integer|min:0',
            'required_approvals'  => 'sometimes|required|integer|min:1|max:10',
            'approver_permission' => 'sometimes|required|string|max:100',
            'block_submitter'     => 'sometimes|boolean',
            'block_beneficiary'   => 'sometimes|boolean',
            'is_active'           => 'sometimes|boolean',
            'sort_order'          => 'sometimes|integer|min:0',
        ]);

        $approvalRule->update($data);

        AuditLog::record('approval_rule_updated', 'accounting', $approvalRule->name, $approvalRule);

        return response()->json(['data' => $this->ruleToArray($approvalRule)]);
    }

    // DELETE /api/v1/accounting/approval-rules/{approvalRule}
    public function destroy(Request $request, ApprovalRule $approvalRule): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.admin'), 403);

        $name = $approvalRule->name;
        $approvalRule->delete();

        AuditLog::record('approval_rule_deleted', 'accounting', $name);

        return response()->json(null, 204);
    }

    // POST /api/v1/accounting/approval-rules/{approvalRule}/toggle
    public function toggle(Request $request, ApprovalRule $approvalRule): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.admin'), 403);

        $approvalRule->update(['is_active' => ! $approvalRule->is_active]);

        AuditLog::record('approval_rule_toggled', 'accounting', $approvalRule->name, $approvalRule);

        return response()->json(['data' => $this->ruleToArray($approvalRule)]);
    }
}
