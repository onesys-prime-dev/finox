<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Blocks deactivated accounts, enforces idle timeout and forced password change. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if (! $user->is_active) {
            return $this->logout($request, 'Your account has been deactivated. Contact an administrator.');
        }

        $idle = (int) \App\Support\Settings::get('session_minutes', 60);
        $last = $request->session()->get('last_activity');
        if ($idle > 0 && $last && now()->timestamp - $last > $idle * 60) {
            return $this->logout($request, 'You were signed out after a period of inactivity.');
        }
        $request->session()->put('last_activity', now()->timestamp);

        if ($user->must_change_password && ! $request->routeIs('password.change', 'password.change.update', 'logout')) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }

    private function logout(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('error', $message);
    }
}
