<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    use PasswordValidationRules, ProfileValidationRules;

    public function index(Request $request): Response
    {
        $this->authorizeAccess($request);
        $search = $request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? '';
        $users = User::withTrashed()->with('roles:id,name')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('username', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%')))
            ->orderBy('name')->orderBy('id')->paginate(15)->withQueryString();

        return Inertia::render('users/index', [
            'users' => $users->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'deleted_at' => $user->deleted_at,
                'roles' => $user->roles->map->only(['id', 'name']),
            ]),
            'roles' => Role::orderBy('name')->get(['id', 'name']),
            'search' => $search,
            'canImpersonate' => (bool) $request->user()->is_admin,
            'currentUserId' => $request->user()->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            ...$this->profileRules(),
            'username' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')],
            'password' => $this->passwordRules(),
            ...$this->roleRules(),
        ]);
        DB::transaction(function () use ($data): void {
            $user = User::create([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_admin' => false,
            ]);
            $user->roles()->sync($data['role_ids']);
        });

        return back()->with('status', 'User created.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorizeAccess($request);
        $this->authorizeTarget($request, $user);
        $data = $request->validate([
            ...$this->profileRules($user->id),
            'username' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user)],
            'password' => ['nullable', 'string', Password::default(), 'confirmed'],
            ...$this->roleRules(),
        ]);
        DB::transaction(function () use ($user, $data): void {
            if ($user->email !== $data['email']) {
                $user->email_verified_at = null;
            }
            $user->name = $data['name'];
            $user->username = $data['username'];
            $user->email = $data['email'];
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();
            $user->roles()->sync($data['role_ids']);
        });

        return back()->with('status', 'User updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorizeAccess($request);
        $this->authorizeTarget($request, $user);
        abort_if($user->id === $request->user()->id || $user->is_admin, 403);
        $user->delete();

        return back()->with('status', 'User deactivated. Reports remain available.');
    }

    public function restore(Request $request, int $user): RedirectResponse
    {
        $this->authorizeAccess($request);
        $account = User::onlyTrashed()->findOrFail($user);
        $this->authorizeTarget($request, $account);
        $account->restore();

        return back()->with('status', 'User restored.');
    }

    /** @return array<string, array<int, mixed>> */
    private function roleRules(): array
    {
        return [
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['required', 'integer', 'distinct', Rule::exists('roles', 'id')],
        ];
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless(! $request->session()->has('impersonator_id') && $request->user()->hasPermission('manage_access'), 403);
    }

    private function authorizeTarget(Request $request, User $user): void
    {
        abort_if($user->is_admin && ! $request->user()->is_admin, 403);
    }
}
