<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Roles', [
            'roles' => Role::withCount('users')->orderBy('id')->get()->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'label' => $r->label, 'description' => $r->description,
                'users_count' => $r->users_count, 'permissions' => $r->name === 'admin' ? Permission::pluck('key') : $r->permissionKeys(),
                'locked' => $r->name === 'admin',
            ]),
            'permissions' => Permission::orderBy('id')->get(['key', 'label', 'group'])->groupBy('group'),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->name === 'admin', 403, 'The Administrator role always has full access.');
        $data = $request->validate(['permissions' => 'array', 'permissions.*' => 'string|exists:permissions,key']);
        $old = $role->permissionKeys();
        $new = $data['permissions'] ?? [];

        DB::transaction(function () use ($role, $old, $new) {
            $role->syncPermissionKeys($new);
            ActivityLogService::log('role.permissions_changed', 'users', "Changed permissions for role {$role->label}", $role,
                ['permissions' => array_values(array_diff($old, $new))], ['permissions' => array_values(array_diff($new, $old))]);
        });

        return back()->with('success', 'Permissions saved. They apply from the next request.');
    }
}
