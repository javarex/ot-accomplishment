<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccessControlController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeAccess($request);

        return Inertia::render('access-control/index', [
            'availablePermissions' => Permission::query()->orderBy('label')->get(['id', 'key', 'label']),
            'roles' => Role::query()->with('permissions:id,key,label')->withCount('users')->orderBy('name')->get(),
            'users' => User::query()->with('roles:id,name')->orderBy('name')->get(['id', 'name', 'email', 'is_admin']),
            'canEditAdmins' => (bool) $request->user()->is_admin,
        ]);
    }

    public function storeRole(Request $request): RedirectResponse
    {
        $this->authorizeAccess($request);
        $data = $this->validatedRole($request);
        DB::transaction(function () use ($data): void {
            $role = Role::create(['name' => $data['name']]);
            $role->permissions()->sync($data['permission_ids']);
        });

        return back()->with('status', 'Role created.');
    }

    public function updateRole(Request $request, Role $role): RedirectResponse
    {
        $this->authorizeAccess($request);
        $data = $this->validatedRole($request, $role);
        if ($role->name === 'Staff' && $data['name'] !== 'Staff') {
            abort(422, 'The default Staff role cannot be renamed.');
        }
        DB::transaction(function () use ($role, $data): void {
            $role->update(['name' => $data['name']]);
            $role->permissions()->sync($data['permission_ids']);
        });

        return back()->with('status', 'Role updated.');
    }

    public function destroyRole(Request $request, Role $role): RedirectResponse
    {
        $this->authorizeAccess($request);
        if ($role->name === 'Staff') {
            abort(422, 'The default Staff role cannot be deleted.');
        }
        $role->delete();

        return back()->with('status', 'Role deleted.');
    }

    public function updateUserRoles(Request $request, User $user): RedirectResponse
    {
        $this->authorizeAccess($request);
        abort_if($user->is_admin && ! $request->user()->is_admin, 403);
        $data = $request->validate([
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['required', 'integer', 'distinct', Rule::exists('roles', 'id')],
        ]);
        $user->roles()->sync($data['role_ids']);

        return back()->with('status', 'User roles updated.');
    }

    /** @return array{name: string, permission_ids: array<int, int>} */
    private function validatedRole(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($role?->id)],
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['required', 'integer', 'distinct', Rule::exists('permissions', 'id')],
        ]);
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless(! $request->session()->has('impersonator_id') && $request->user()->hasPermission('manage_access'), 403);
    }
}
