<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\LoginHistory;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => false, // no outbound mail on the internal network; admins reset passwords
            'status' => session('status'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();
        $request->session()->put('last_activity', now()->timestamp);

        ActivityLogService::log('auth.login', 'auth', 'Signed in', null, user: $request->user());

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user) {
            LoginHistory::create(['user_id' => $user->id, 'email' => $user->email, 'event' => 'logout',
                'ip' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255)]);
            ActivityLogService::log('auth.logout', 'auth', 'Signed out', null, user: $user);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
