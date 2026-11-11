<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(Request $request): ResourceCollection
    {
        abort_unless($request->user()->hasPermissionTo('roles.manage'), 403);

        $roles = Role::with('permissions')->orderBy('name')->get();

        return RoleResource::collection($roles);
    }

    public function show(Request $request, Role $role): RoleResource
    {
        abort_unless($request->user()->hasPermissionTo('roles.manage'), 403);

        $role->load('permissions');

        return new RoleResource($role);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('roles.manage'), 403);

        $data = $request->validate([
            'name'        => 'required|string|max:125|unique:roles,name',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);

        if (! empty($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }

        $role->load('permissions');

        AuditLog::record('role_created', 'members', $role->name, $role);

        return (new RoleResource($role))->response()->setStatusCode(201);
    }

    public function update(Request $request, Role $role): RoleResource
    {
        abort_unless($request->user()->hasPermissionTo('roles.manage'), 403);

        $data = $request->validate([
            'name'        => "sometimes|required|string|max:125|unique:roles,name,{$role->id}",
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        if (isset($data['name'])) {
            $role->update(['name' => $data['name']]);
        }

        if (array_key_exists('permissions', $data)) {
            $role->syncPermissions($data['permissions'] ?? []);
        }

        $role->load('permissions');

        AuditLog::record('role_updated', 'members', $role->name, $role);

        return new RoleResource($role);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('roles.manage'), 403);

        $name = $role->name;
        $role->delete();

        AuditLog::record('role_deleted', 'members', $name, null);

        return response()->json(['message' => 'Role deleted.']);
    }

    // PUT /users/{user}/roles
    public function syncUserRoles(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('roles.manage'), 403);

        $data = $request->validate([
            'roles'   => 'required|array',
            'roles.*' => 'string|exists:roles,name',
        ]);

        $user->syncRoles($data['roles']);

        AuditLog::record('role_assigned', 'members', $user->name, $user);

        return response()->json([
            'message' => 'Roles updated.',
            'roles'   => $user->getRoleNames(),
        ]);
    }
}
