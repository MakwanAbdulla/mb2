<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use App\Services\MemberCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberCardController extends Controller
{
    /**
     * POST /api/v1/verify-member-qr
     *
     * Public endpoint — no auth required (HMAC is the security).
     * Used by kiosk (Pi) to verify a scanned member QR code.
     *
     * Request body:
     *   member_id  int     required | must exist in members table
     *   token      string  required | 64-char hex HMAC string
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'member_id' => 'required|integer|exists:members,id',
            'token'     => 'required|string|size:64',
        ]);

        $memberId = (int) $data['member_id'];
        $token    = $data['token'];

        if (! MemberCardService::verifyToken($memberId, $token, tolerance: 2)) {
            return response()->json(['error' => 'Ogiltig eller utgången QR-kod'], 401);
        }

        $member = Member::with('user', 'sections', 'membershipPeriods')
            ->find($memberId);

        if (! $member) {
            return response()->json(['error' => 'Ogiltig eller utgången QR-kod'], 401);
        }

        $currentPeriod = $member->membershipPeriods
            ->where('year', now()->year)
            ->where('status', MembershipPeriod::STATUS_ACTIVE)
            ->first();

        return response()->json([
            'id'             => $member->id,
            'name'           => $member->full_name,
            'member_number'  => $member->membership_number,
            'avatar_url'     => $member->user?->avatarUrl(80),
            'current_period' => $currentPeriod ? [
                'year'            => $currentPeriod->year,
                'membership_type' => $currentPeriod->membership_type,
                'status'          => $currentPeriod->status,
            ] : null,
            'sections'  => $member->sections->map(fn ($s) => [
                'id'   => $s->id,
                'name' => $s->name,
            ])->values(),
            'is_active' => $currentPeriod !== null,
        ]);
    }
}
