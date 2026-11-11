<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginHistory;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Support\PasswordRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /** Only administrators may touch administrator accounts or hand out the administrator role. */
    private function guardAdmin(Request $request, ?User $target, ?int $newRoleId = null): void
    {
        if ($request->user()->isAdmin()) {
            return;
        }
        $adminRoleId = Role::where('name', 'admin')->value('id');
        abort_if(($target && $target->isAdmin()) || ($newRoleId !== null && $newRoleId === (int) $adminRoleId), 403, 'Only an administrator can manage administrator accounts.');
    }

    public function index(Request $request): Response
    {
        $users = User::with('role:id,label')->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")))
            ->orderBy('name')->paginate(25)->withQueryString()
            ->through(fn ($u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role?->label, 'role_id' => $u->role_id,
                'is_active' => $u->is_active, 'last_login_at' => $u->last_login_at?->toDateTimeString(),
                'locked' => (bool) ($u->locked_until && $u->locked_until->isFuture()),
            ]);

        return Inertia::render('Admin/Users', [
            'users' => $users, 'roles' => Role::orderBy('label')->get(['id', 'label']), 'filters' => $request->only('search'),
            'passwordMin' => (int) \App\Support\Settings::get('password_min_length', 10),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255', 'email' => 'required|email|lowercase|max:255|unique:users',
            'role_id' => 'required|exists:roles,id', 'password' => ['required', PasswordRules::make()],
        ]);
        $this->guardAdmin($request, null, (int) $data['role_id']);
        $user = DB::transaction(function () use ($data) {
            $u = User::create($data + ['must_change_password' => true, 'is_active' => true]);
            ActivityLogService::log('user.created', 'users', "Created user {$u->name}", $u, new: ['role_id' => $u->role_id, 'email' => $u->email]);

            return $u;
        });

        return back()->with('success', "User {$user->name} created. They must change the password at first sign-in.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255', 'email' => ['required', 'email', 'lowercase', 'max:255', Rule::unique('users')->ignore($user->id)],
            'role_id' => 'required|exists:roles,id',
        ]);
        $this->guardAdmin($request, $user, (int) $data['role_id']);
        if ($user->id === $request->user()->id && (int) $data['role_id'] !== $user->role_id) {
            return back()->with('error', 'You cannot change your own role.');
        }
        DB::transaction(function () use ($user, $data) {
            $user->fill($data)->save();
            [$old, $new] = ActivityLogService::diff($user);
            if ($new) {
                ActivityLogService::log('user.updated', 'users', "Updated user {$user->name}", $user, $old, $new);
            }
        });

        return back()->with('success', 'User updated.');
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        $this->guardAdmin($request, $user);
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }
        DB::transaction(function () use ($user) {
            $user->update(['is_active' => ! $user->is_active]);
            ActivityLogService::log($user->is_active ? 'user.activated' : 'user.deactivated', 'users', ($user->is_active ? 'Activated ' : 'Deactivated ')."user {$user->name}", $user);
        });

        return back()->with('success', $user->is_active ? 'User activated.' : 'User deactivated.');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->guardAdmin($request, $user);
        $temp = Str::password(12, symbols: false).'a1A';
        DB::transaction(function () use ($user, $temp) {
            $user->forceFill(['password' => $temp, 'must_change_password' => true, 'failed_login_count' => 0, 'locked_until' => null])->save();
            ActivityLogService::log('user.password_reset', 'users', "Reset password for {$user->name}", $user);
        });

        return back()->with('success', "Temporary password for {$user->name}: {$temp}  (shown once — they must change it at next sign-in)");
    }

    public function unlock(Request $request, User $user): RedirectResponse
    {
        $this->guardAdmin($request, $user);
        $user->forceFill(['failed_login_count' => 0, 'locked_until' => null])->save();
        ActivityLogService::log('user.unlocked', 'users', "Unlocked account of {$user->name}", $user);

        return back()->with('success', 'Account unlocked.');
    }

    public function show(User $user): Response
    {
        return Inertia::render('Admin/UserShow', [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role?->label, 'is_active' => $user->is_active, 'last_login_at' => $user->last_login_at?->toDateTimeString()],
            'logins' => LoginHistory::where('user_id', $user->id)->latest('id')->limit(30)->get(['event', 'ip', 'user_agent', 'created_at']),
            'activity' => \App\Models\ActivityLog::where('user_id', $user->id)->latest('id')->limit(40)->get(['id', 'module', 'action', 'description', 'created_at']),
        ]);
    }
}
