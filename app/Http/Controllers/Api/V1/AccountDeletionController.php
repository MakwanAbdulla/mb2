<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\AccountDeletionApproved;
use App\Mail\AccountDeletionReceived;
use App\Mail\AccountDeletionRejected;
use App\Models\Membership\AccountDeletionRequest;
use App\Models\AuditLog;
use App\Models\Membership\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class AccountDeletionController extends Controller
{
    /**
     * Submit a GDPR deletion request.
     */
    public function store(Request $request): JsonResponse
    {
        $member = Member::where('user_id', $request->user()->id)->firstOrFail();

        if (AccountDeletionRequest::where('member_id', $member->id)
            ->where('status', AccountDeletionRequest::STATUS_PENDING)
            ->exists()
        ) {
            return response()->json(['message' => 'A pending deletion request already exists.'], 409);
        }

        $data = $request->validate(['reason' => 'nullable|string|max:2000']);

        $req = AccountDeletionRequest::create([
            'member_id' => $member->id,
            'user_id'   => $request->user()->id,
            'reason'    => $data['reason'] ?? null,
            'status'    => AccountDeletionRequest::STATUS_PENDING,
        ]);

        if ($member->email) {
            Mail::to($member->email)->queue(new AccountDeletionReceived($req));
        }

        return response()->json([
            'data' => ['id' => $req->id, 'status' => $req->status],
        ], 201);
    }

    /**
     * Cancel a pending deletion request.
     */
    public function cancel(Request $request): JsonResponse
    {
        $member = Member::where('user_id', $request->user()->id)->firstOrFail();

        $pending = AccountDeletionRequest::where('member_id', $member->id)
            ->where('status', AccountDeletionRequest::STATUS_PENDING)
            ->firstOrFail();

        $pending->delete();

        return response()->json(['message' => 'Deletion request cancelled.']);
    }

    /**
     * [Admin] List all deletion requests (paginated, filterable by status).
     */
    public function index(Request $request): JsonResponse
    {
        $requests = AccountDeletionRequest::with(['member', 'user', 'actionedBy'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => $requests->map(fn ($r) => $this->formatRequest($r)),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page'    => $requests->lastPage(),
                'total'        => $requests->total(),
                'per_page'     => $requests->perPage(),
            ],
        ]);
    }

    /**
     * [Admin] Approve a deletion request — anonymizes the member.
     */
    public function approve(Request $request, AccountDeletionRequest $deletionRequest): JsonResponse
    {
        if (! $deletionRequest->isPending()) {
            return response()->json(['message' => 'Request is not pending.'], 422);
        }

        $member = $deletionRequest->member;
        $subject = $member->full_name . ' #' . $member->membership_number;

        // Capture contact info before anonymize() wipes it
        $memberEmail     = $member->email;
        $memberFirstName = $member->first_name;

        $deletionRequest->approve($request->user());

        if ($memberEmail) {
            Mail::to($memberEmail)->queue(new AccountDeletionApproved($memberFirstName));
        }

        AuditLog::record(
            action: 'membership_deactivated',
            area: 'members',
            subject: $subject,
            changes: ['reason' => 'gdpr_erasure'],
        );

        return response()->json(['message' => 'Deletion request approved. Member anonymized.']);
    }

    /**
     * [Admin] Reject a deletion request.
     */
    public function reject(Request $request, AccountDeletionRequest $deletionRequest): JsonResponse
    {
        if (! $deletionRequest->isPending()) {
            return response()->json(['message' => 'Request is not pending.'], 422);
        }

        $data = $request->validate(['admin_note' => 'required|string|max:2000']);

        $deletionRequest->reject($request->user(), $data['admin_note']);

        $deletionRequest->loadMissing('member');
        if ($deletionRequest->member?->email) {
            Mail::to($deletionRequest->member->email)->queue(new AccountDeletionRejected($deletionRequest));
        }

        AuditLog::record(
            action: 'gdpr_request_rejected',
            area: 'members',
            subject: $deletionRequest->member?->full_name ?? 'Okänd',
            auditable: $deletionRequest->member,
        );

        return response()->json(['message' => 'Deletion request rejected.']);
    }

    private function formatRequest(AccountDeletionRequest $r): array
    {
        return [
            'id'          => $r->id,
            'status'      => $r->status,
            'reason'      => $r->reason,
            'admin_note'  => $r->admin_note,
            'created_at'  => $r->created_at->toIso8601String(),
            'actioned_at' => $r->actioned_at?->toIso8601String(),
            'member'      => $r->member ? [
                'id'               => $r->member->id,
                'full_name'        => $r->member->full_name,
                'membership_number' => $r->member->membership_number,
                'email'            => $r->member->email,
            ] : null,
            'actioned_by' => $r->actionedBy ? [
                'id'   => $r->actionedBy->id,
                'name' => $r->actionedBy->name,
            ] : null,
        ];
    }
}
