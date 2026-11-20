<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Creates one browser-test user per role (e2e-<role>@finox.test) for the Playwright suite.
 *
 * Run after DatabaseSeeder:  php artisan db:seed --class=E2eUserSeeder
 *
 * It refuses to run unless APP_ENV is "e2e" or "testing", so it can never add
 * accounts with a published password to a staging or production database.
 */
class E2eUserSeeder extends Seeder
{
    /** Must match PASSWORD in e2e/helpers.js. */
    public const PASSWORD = 'E2e#Password2026';

    public const ALLOWED_ENVIRONMENTS = ['e2e', 'testing'];

    public function run(): void
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            throw new RuntimeException('E2eUserSeeder only runs when APP_ENV is e2e or testing (current: '.app()->environment().').');
        }

        foreach (array_keys(config('permissions.roles')) as $role) {
            User::updateOrCreate(['email' => "e2e-{$role}@finox.test"], [
                'name' => 'E2E '.ucfirst($role),
                'password' => self::PASSWORD,
                'role_id' => Role::where('name', $role)->value('id'),
                'is_active' => true,
                'must_change_password' => false,
                'failed_login_count' => 0,
                'locked_until' => null,
            ]);
        }
    }
}
