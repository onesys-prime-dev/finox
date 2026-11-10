<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'preferences' => $request->user()->preferences ?? [],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ]);
        $user->fill($data)->save();
        ActivityLogService::log('user.profile_updated', 'users', 'Updated own profile', $user);

        return back()->with('success', 'Profile saved.');
    }

    /** Per-user UI preferences (page size, hidden columns). */
    public function preferences(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:60'],
            'value' => ['nullable'],
        ]);
        $prefs = $request->user()->preferences ?? [];
        $prefs[$data['key']] = $data['value'];
        $request->user()->update(['preferences' => $prefs]);

        return back();
    }
}
