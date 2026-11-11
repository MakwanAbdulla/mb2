<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseClaimResource;
use App\Models\Accounting\ExpenseClaim;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Auth;

class ExpenseClaimController extends Controller
{
    public function index(Request $request): ResourceCollection
    {
        $user = Auth::user();

        $claims = ExpenseClaim::query()
            ->when(
                ! $user->hasAnyPermission(['expenses.view', 'expenses.manage']),
                fn ($q) => $q->whereHas('member', fn ($q) => $q->where('user_id', $user->id))
            )
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->with('member', 'costCenter')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return ExpenseClaimResource::collection($claims);
    }

    public function show(ExpenseClaim $expenseClaim): ExpenseClaimResource
    {
        $user = Auth::user();

        abort_unless(
            $expenseClaim->member?->user_id === $user->id
            || $user->hasAnyPermission(['expenses.view', 'expenses.manage']),
            403
        );

        $expenseClaim->load('member', 'costCenter');

        return new ExpenseClaimResource($expenseClaim);
    }

    public function store(Request $request): JsonResponse
    {
        $member = Auth::user()->member;
        abort_unless($member !== null, 422, 'No member profile linked to this account.');

        $data = $request->validate([
            'description'    => 'required|string|max:500',
            'amount_ore'     => 'required|integer|min:1',
            'claim_type'     => 'required|in:reimbursement,card_receipt',
            'purchased_at'   => 'required|date',
            'cost_center_id' => 'nullable|exists:cost_centers,id',
            'notes'          => 'nullable|string|max:2000',
        ]);

        $claim = ExpenseClaim::create([
            ...$data,
            'member_id'    => $member->id,
            'status'       => ExpenseClaim::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        AuditLog::record('expense_claim_submitted', 'accounting', $claim->description, $claim);

        return (new ExpenseClaimResource($claim))
            ->response()
            ->setStatusCode(201);
    }

    public function approve(Request $request, ExpenseClaim $expenseClaim): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('expenses.manage'), 403);
        abort_unless($expenseClaim->status === ExpenseClaim::STATUS_SUBMITTED, 422, 'Claim cannot be approved in its current state.');

        $expenseClaim->update([
            'status'      => ExpenseClaim::STATUS_APPROVED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $request->input('note'),
        ]);

        AuditLog::record('expense_claim_approved', 'accounting', $expenseClaim->description, $expenseClaim);

        return response()->json(['message' => 'Claim approved.', 'status' => $expenseClaim->status]);
    }

    public function reject(Request $request, ExpenseClaim $expenseClaim): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('expenses.manage'), 403);
        abort_unless($expenseClaim->status === ExpenseClaim::STATUS_SUBMITTED, 422, 'Claim cannot be rejected in its current state.');

        $request->validate(['note' => 'nullable|string|max:500']);

        $expenseClaim->update([
            'status'      => ExpenseClaim::STATUS_REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $request->input('note'),
        ]);

        AuditLog::record('expense_claim_rejected', 'accounting', $expenseClaim->description, $expenseClaim);

        return response()->json(['message' => 'Claim rejected.', 'status' => $expenseClaim->status]);
    }
}
