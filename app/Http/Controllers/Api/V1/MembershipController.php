<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MemberResource;
use App\Models\AuditLog;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipController extends Controller
{
    /**
     * Get the authenticated user's membership profile.
     */
    public function show(Request $request): JsonResponse
    {
        $member = Member::where('user_id', $request->user()->id)
            ->with([
                'membershipPeriods' => fn ($q) => $q->orderByDesc('year'),
                'sections'          => fn ($q) => $q->wherePivotNull('left_at'),
                'sectionRoles'      => fn ($q) => $q->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now())),
            ])
            ->first();

        if (! $member) {
            return response()->json(['message' => 'No membership found.'], 404);
        }

        return (new MemberResource($member))->response();
    }

    /**
     * Apply for membership (self-registration).
     */
    public function store(Request $request): JsonResponse
    {
        if (Member::where('user_id', $request->user()->id)->exists()) {
            return response()->json(['message' => 'You already have a membership.'], 422);
        }

        $data = $request->validate([
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'required|string|max:100',
            'email'           => 'required|email|max:255',
            'phone'           => 'nullable|string|max:30',
            'address'         => 'nullable|string|max:255',
            'postal_code'     => 'nullable|string|max:10',
            'city'            => 'nullable|string|max:100',
            'date_of_birth'   => 'nullable|date|before:today',
            'membership_type' => 'required|in:single,family,student,honorary',
        ]);

        $member = Member::create([
            ...$data,
            'user_id' => $request->user()->id,
            'status'  => Member::STATUS_PENDING,
        ]);

        MembershipPeriod::create([
            'member_id'       => $member->id,
            'year'            => now()->year,
            'membership_type' => $data['membership_type'],
            'status'          => MembershipPeriod::STATUS_PENDING_PAYMENT,
        ]);

        AuditLog::record('membership_applied', 'members', $member->full_name, $member);

        return (new MemberResource($member->load('membershipPeriods')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update the authenticated user's own contact details.
     */
    public function update(Request $request): JsonResponse
    {
        $member = Member::where('user_id', $request->user()->id)->first();

        if (! $member) {
            return response()->json(['message' => 'No membership found.'], 404);
        }

        $data = $request->validate([
            'first_name'    => 'sometimes|required|string|max:100',
            'last_name'     => 'sometimes|required|string|max:100',
            'email'         => 'sometimes|required|email|max:255',
            'phone'         => 'nullable|string|max:30',
            'address'       => 'nullable|string|max:255',
            'postal_code'   => 'nullable|string|max:10',
            'city'          => 'nullable|string|max:100',
            'date_of_birth' => 'nullable|date|before:today',
        ]);

        $member->update($data);

        AuditLog::record('membership_updated', 'members', $member->full_name, $member);

        return (new MemberResource($member->fresh()))->response();
    }
}
