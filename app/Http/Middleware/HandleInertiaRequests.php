<?php

namespace App\Http\Middleware;

use App\Models\PrintJob;
use App\Support\Settings;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role?->label,
                    'is_admin' => $user->isAdmin(),
                    'permissions' => $user->isAdmin()
                        ? array_merge(...array_map('array_keys', array_values(config('permissions.permissions'))))
                        : $user->permissionKeys(),
                ] : null,
            ],
            'app' => [
                'name' => Settings::get('system_name', 'FINOX'),
                'org' => Settings::get('org_name'),
                'currency' => Settings::get('currency_symbol', '₱'),
                'date_format' => Settings::get('date_format', 'M d, Y'),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
            // Unconfirmed print jobs follow the user around until resolved.
            'pendingPrints' => fn () => $user && $user->hasPermission('print.print')
                ? PrintJob::where('status', 'initiated')->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))->count()
                : 0,
        ];
    }
}
