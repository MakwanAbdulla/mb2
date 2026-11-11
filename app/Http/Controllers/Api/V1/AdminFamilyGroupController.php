<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Membership\FamilyGroup;
use App\Models\Membership\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminFamilyGroupController extends Controller
{
    // GET /api/v1/admin/family-groups
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('members.manage'), 403);

        $groups = FamilyGroup::with(['administrator', 'membersWithPivot'])
            ->paginate($request->integer('per_page', 50));

        $data = $groups->map(fn ($g) => [
            'id'                      => $g->id,
            'administrator_member_id' => $g->administrator_member_id,
            'administrator_name'      => $g->administrator?->full_name,
            'members'                 => $g->membersWithPivot->map(fn ($m) => [
                'id'              => $m->id,
                'full_name'       => $m->full_name,
                'role'            => $m->pivot->role,
                'has_own_account' => $m->pivot->has_own_account,
                'status'          => $m->pivot->status,
            ]),
            'created_at' => $g->created_at->toIso8601String(),
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $groups->currentPage(),
                'last_page'    => $groups->lastPage(),
                'per_page'     => $groups->perPage(),
                'total'        => $groups->total(),
            ],
        ]);
    }

    // GET /api/v1/admin/family-groups/{familyGroup}
    public function show(Request $request, FamilyGroup $familyGroup): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('members.manage'), 403);

        $familyGroup->load(['administrator', 'membersWithPivot', 'pendingInvitations', 'history']);

        return response()->json([
            'id'                      => $familyGroup->id,
            'administrator_member_id' => $familyGroup->administrator_member_id,
            'administrator_name'      => $familyGroup->administrator?->full_name,
            'members'                 => $familyGroup->membersWithPivot->map(fn ($m) => [
                'id'              => $m->id,
                'full_name'       => $m->full_name,
                'role'            => $m->pivot->role,
                'has_own_account' => $m->pivot->has_own_account,
                'status'          => $m->pivot->status,
            ]),
            'pending_invitations' => $familyGroup->pendingInvitations->map(fn ($m) => [
                'id'        => $m->id,
                'full_name' => $m->full_name,
                'role'      => $m->pivot->role,
            ]),
            'history' => $familyGroup->history->map(fn ($h) => [
                'action'     => $h->action,
                'member_id'  => $h->member_id,
                'year'       => $h->year,
                'notes'      => $h->notes,
                'created_at' => $h->created_at->toIso8601String(),
            ]),
            'created_at' => $familyGroup->created_at->toIso8601String(),
        ]);
    }

    // POST /api/v1/admin/family-groups/{familyGroup}/remove-member
    public function removeMember(Request $request, FamilyGroup $familyGroup): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('members.manage'), 403);

        $data = $request->validate([
            'member_id' => 'required|exists:members,id',
            'notes'     => 'nullable|string|max:500',
        ]);

        $member = Member::findOrFail($data['member_id']);
        abort_unless($familyGroup->isManagedBy($member) || $familyGroup->members()->where('id', $member->id)->exists(), 422, 'Member not in group.');

        $familyGroup->removeMember($member, $request->user(), $data['notes'] ?? null);

        return response()->json(null, 204);
    }
}
