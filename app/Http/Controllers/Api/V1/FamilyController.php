<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\FamilyInvitationMail;
use App\Models\Membership\FamilyGroup;
use App\Models\Membership\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class FamilyController extends Controller
{
    /**
     * Return the authenticated member's active family group,
     * or a pending_invitation block if they have a pending invite.
     */
    public function show(Request $request): JsonResponse
    {
        $member = Member::where('user_id', $request->user()->id)->first();

        if (! $member?->family_group_id) {
            return response()->json(['data' => null]);
        }

        $pivot = DB::table('family_members')
            ->where('family_group_id', $member->family_group_id)
            ->where('member_id', $member->id)
            ->first();

        if (! $pivot) {
            return response()->json(['data' => null]);
        }

        $family = FamilyGroup::with(['membersWithPivot', 'pendingInvitations', 'administrator'])
            ->find($member->family_group_id);

        if (! $family) {
            return response()->json(['data' => null]);
        }

        if ($pivot->status === FamilyGroup::STATUS_PENDING) {
            return response()->json([
                'data' => null,
                'pending_invitation' => [
                    'family_group_id' => $family->id,
                    'invited_by'      => $family->administrator?->full_name,
                    'invited_at'      => $pivot->invited_at,
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'id'            => $family->id,
                'administrator' => $family->administrator ? [
                    'id'        => $family->administrator->id,
                    'full_name' => $family->administrator->full_name,
                ] : null,
                'members'             => $family->membersWithPivot->map(fn ($m) => [
                    'id'                 => $m->id,
                    'full_name'          => $m->full_name,
                    'role'               => $m->pivot->role,
                    'has_own_account'    => (bool) $m->pivot->has_own_account,
                    'is_billing_contact' => $family->administrator_member_id === $m->id,
                ])->values(),
                'pending_invitations' => $family->pendingInvitations->map(fn ($m) => [
                    'id'         => $m->id,
                    'full_name'  => $m->full_name,
                    'invited_at' => $m->pivot->invited_at,
                ])->values(),
            ],
        ]);
    }

    /**
     * Accept the authenticated member's pending family invitation.
     */
    public function acceptInvitation(Request $request): JsonResponse
    {
        $member = Member::where('user_id', $request->user()->id)->first();

        if (! $member?->family_group_id) {
            return response()->json(['message' => 'No pending invitation found.'], 422);
        }

        $isPending = DB::table('family_members')
            ->where('family_group_id', $member->family_group_id)
            ->where('member_id', $member->id)
            ->where('status', FamilyGroup::STATUS_PENDING)
            ->exists();

        if (! $isPending) {
            return response()->json(['message' => 'No pending invitation found.'], 422);
        }

        $family = FamilyGroup::find($member->family_group_id);
        $family->acceptInvitation($member);

        return response()->json(['message' => 'Invitation accepted.']);
    }

    /**
     * Decline the authenticated member's pending family invitation.
     */
    public function declineInvitation(Request $request): JsonResponse
    {
        $member = Member::where('user_id', $request->user()->id)->first();

        if (! $member?->family_group_id) {
            return response()->json(['message' => 'No pending invitation found.'], 422);
        }

        $isPending = DB::table('family_members')
            ->where('family_group_id', $member->family_group_id)
            ->where('member_id', $member->id)
            ->where('status', FamilyGroup::STATUS_PENDING)
            ->exists();

        if (! $isPending) {
            return response()->json(['message' => 'No pending invitation found.'], 422);
        }

        $family = FamilyGroup::find($member->family_group_id);
        $family->cancelInvitation($member, $request->user());

        return response()->json(['message' => 'Invitation declined.']);
    }

    /**
     * Leave the authenticated member's family group.
     * Billing contacts must transfer the role first.
     * The current-year membership period is unaffected.
     */
    public function leave(Request $request): JsonResponse
    {
        $member = Member::where('user_id', $request->user()->id)->first();

        if (! $member?->family_group_id) {
            return response()->json(['message' => 'Not a member of any family group.'], 422);
        }

        $isActive = DB::table('family_members')
            ->where('family_group_id', $member->family_group_id)
            ->where('member_id', $member->id)
            ->where('status', FamilyGroup::STATUS_ACTIVE)
            ->exists();

        if (! $isActive) {
            return response()->json(['message' => 'Not an active member. Use decline-invitation instead.'], 422);
        }

        $family = FamilyGroup::find($member->family_group_id);

        if ($family->isBillingContact($member)) {
            return response()->json([
                'message' => 'Billing contact cannot leave. Transfer the role to another adult first.',
            ], 422);
        }

        $family->leave($member, $request->user());

        return response()->json(['message' => 'Left family group. Membership remains active.']);
    }
}
