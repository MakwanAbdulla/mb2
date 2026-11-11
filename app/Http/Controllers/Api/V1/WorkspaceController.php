<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WorkspaceAccount;
use App\Models\WorkspaceDelegate;
use App\Models\WorkspaceSharedDrive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    // GET /api/v1/admin/workspace/accounts
    public function accounts(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('workspace.manage'), 403);

        $accounts = WorkspaceAccount::nonPersonal()
            ->with('accountable')
            ->orderBy('email')
            ->get()
            ->map(function (WorkspaceAccount $a) {
                $section = $a->accountable_type === 'section'   ? $a->accountable : null;
                $role    = $a->accountable_type === 'org_role'  ? $a->accountable : null;
                return [
                    'id'                           => $a->id,
                    'email'                        => $a->email,
                    'display_name'                 => $a->display_name,
                    'description'                  => $a->description,
                    'section_id'                   => $section?->id,
                    'section_name'                 => $section?->name,
                    'organizational_role_id'       => $role?->id,
                    'organizational_role_name'     => $role?->name,
                ];
            });

        return response()->json(['data' => $accounts]);
    }

    // GET /api/v1/admin/workspace/drives
    public function drives(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('workspace.manage'), 403);

        $drives = WorkspaceSharedDrive::with(['section', 'organizationalRole'])
            ->orderBy('name')
            ->get()
            ->map(fn ($d) => [
                'id'                   => $d->id,
                'google_drive_id'      => $d->google_drive_id,
                'name'                 => $d->name,
                'member_role'          => $d->member_role,
                'public_access'        => $d->public_access,
                'is_created'           => $d->isCreated(),
                'section_id'           => $d->section_id,
                'section_name'         => $d->section?->name,
                'organizational_role_id'   => $d->organizational_role_id,
                'organizational_role_name' => $d->organizationalRole?->title,
            ]);

        return response()->json(['data' => $drives]);
    }

    // GET /api/v1/admin/workspace/delegates
    public function delegates(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('workspace.manage'), 403);

        $delegates = WorkspaceDelegate::orderBy('mailbox')->orderBy('delegate_email')->get()
            ->map(fn ($d) => [
                'id'             => $d->id,
                'mailbox'        => $d->mailbox,
                'delegate_email' => $d->delegate_email,
                'status'         => $d->status,
                'source'         => $d->source,
            ]);

        return response()->json(['data' => $delegates]);
    }
}
