<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Membership\MembershipFee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipFeeController extends Controller
{
    // GET /api/v1/membership-fees?year=2026  — public (needed for registration)
    public function index(Request $request): JsonResponse
    {
        $year = $request->integer('year', now()->year);

        $fees = MembershipFee::where('year', $year)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($f) => [
                'id'              => $f->id,
                'year'            => $f->year,
                'membership_type' => $f->membership_type,
                'amount'          => $f->amount,
                'min_age'         => $f->min_age,
                'max_age'         => $f->max_age,
                'age_label'       => $f->ageLabel(),
                'sort_order'      => $f->sort_order,
            ]);

        return response()->json(['data' => $fees, 'year' => $year]);
    }

    // GET /api/v1/admin/membership-fees?year=2026  — admin (all incl. inactive)
    public function adminIndex(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('members.manage'), 403);

        $year = $request->integer('year', now()->year);

        $fees = MembershipFee::where('year', $year)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($f) => [
                'id'              => $f->id,
                'year'            => $f->year,
                'membership_type' => $f->membership_type,
                'amount'          => $f->amount,
                'min_age'         => $f->min_age,
                'max_age'         => $f->max_age,
                'age_label'       => $f->ageLabel(),
                'sort_order'      => $f->sort_order,
                'is_active'       => $f->is_active,
            ]);

        return response()->json(['data' => $fees, 'year' => $year]);
    }

    // POST /api/v1/admin/membership-fees
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('members.manage'), 403);

        $data = $request->validate([
            'year'            => 'required|integer|min:2000|max:2100',
            'membership_type' => 'required|string|max:50',
            'amount'          => 'required|integer|min:0',
            'min_age'         => 'nullable|integer|min:0',
            'max_age'         => 'nullable|integer|min:0',
            'sort_order'      => 'integer|min:0',
            'is_active'       => 'boolean',
        ]);

        $fee = MembershipFee::create($data);

        AuditLog::record('membership_fee_created', 'system', "{$fee->membership_type} {$fee->year}", $fee);

        return response()->json(['id' => $fee->id], 201);
    }

    // PATCH /api/v1/admin/membership-fees/{fee}
    public function update(Request $request, MembershipFee $membershipFee): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('members.manage'), 403);

        $data = $request->validate([
            'amount'     => 'sometimes|integer|min:0',
            'min_age'    => 'nullable|integer|min:0',
            'max_age'    => 'nullable|integer|min:0',
            'sort_order' => 'integer|min:0',
            'is_active'  => 'boolean',
        ]);

        $membershipFee->update($data);

        AuditLog::record('membership_fee_updated', 'system', "{$membershipFee->membership_type} {$membershipFee->year}", $membershipFee);

        return response()->json(['id' => $membershipFee->id]);
    }

    // DELETE /api/v1/admin/membership-fees/{fee}
    public function destroy(Request $request, MembershipFee $membershipFee): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('members.manage'), 403);

        $subject = "{$membershipFee->membership_type} {$membershipFee->year}";
        $membershipFee->delete();

        AuditLog::record('membership_fee_deleted', 'system', $subject, null);

        return response()->json(null, 204);
    }
}
