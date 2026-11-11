<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization\OrganizationalRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrgRoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['members.view', 'members.manage']), 403);

        $roles = OrganizationalRole::with(['periods' => fn ($q) => $q->orderByDesc('active_start')])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $roles->map(fn ($r) => [
            'id'          => $r->id,
            'name'        => $r->name,
            'slug'        => $r->slug,
            'description' => $r->description,
            'periods'     => $r->periods->map(fn ($p) => [
                'id'             => $p->id,
                'status'         => $p->status,
                'active_start'   => $p->active_start?->toDateString(),
                'active_end'     => $p->active_end?->toDateString(),
                'reporting_end'  => $p->reporting_end?->toDateString(),
                'member_count'   => $p->members()->count(),
            ]),
        ])]);
    }

    public function show(Request $request, OrganizationalRole $orgRole): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['members.view', 'members.manage']), 403);

        $orgRole->load(['periods.members.member']);

        return response()->json(['data' => [
            'id'          => $orgRole->id,
            'name'        => $orgRole->name,
            'slug'        => $orgRole->slug,
            'description' => $orgRole->description,
            'periods'     => $orgRole->periods->map(fn ($p) => [
                'id'            => $p->id,
                'status'        => $p->status,
                'active_start'  => $p->active_start?->toDateString(),
                'active_end'    => $p->active_end?->toDateString(),
                'reporting_end' => $p->reporting_end?->toDateString(),
                'members'       => $p->members->map(fn ($m) => [
                    'id'        => $m->id,
                    'role'      => $m->role,
                    'member_id' => $m->member_id,
                    'full_name' => $m->member?->full_name,
                ]),
            ]),
        ]]);
    }
}
