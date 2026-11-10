<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Support\PasswordRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    /** Voluntary change from the profile page. */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', PasswordRules::make(), 'confirmed', 'different:current_password'],
        ]);

        $request->user()->update(['password' => Hash::make($validated['password']), 'must_change_password' => false]);
        ActivityLogService::log('auth.password_changed', 'auth', 'Changed own password');

        return back()->with('success', 'Password updated.');
    }

    /** Forced change (first login or after an admin reset). */
    public function showChange(): Response
    {
        return Inertia::render('Auth/ChangePassword');
    }

    public function change(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', PasswordRules::make(), 'confirmed', 'different:current_password'],
        ]);

        $request->user()->update(['password' => Hash::make($validated['password']), 'must_change_password' => false]);
        ActivityLogService::log('auth.password_changed', 'auth', 'Changed password (required change)');

        return redirect()->route('dashboard')->with('success', 'Password changed. Welcome to FINOX.');
    }
}
