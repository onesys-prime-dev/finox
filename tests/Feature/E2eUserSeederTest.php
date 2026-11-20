<?php

namespace Tests\Feature;

use App\Models\Cheque;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\E2eUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class E2eUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_one_active_user_per_role(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(E2eUserSeeder::class);
        $this->seed(E2eUserSeeder::class); // idempotent

        foreach (array_keys(config('permissions.roles')) as $role) {
            $user = User::where('email', "e2e-{$role}@finox.test")->with('role')->sole();
            $this->assertSame($role, $user->role->name);
            $this->assertTrue($user->is_active);
            $this->assertFalse((bool) $user->must_change_password);
            $this->assertTrue(Hash::check(E2eUserSeeder::PASSWORD, $user->password));
        }
    }

    public function test_encoder_owns_only_the_first_demo_cheque(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);
        $this->seed(E2eUserSeeder::class);

        // Ids are not assumed: MySQL does not reset AUTO_INCREMENT when RefreshDatabase rolls back.
        // On the freshly migrated e2e database the first cheque is #1 (what the Playwright specs open).
        $encoder = User::where('email', 'e2e-encoder@finox.test')->sole();
        [$first, $second] = Cheque::query()->orderBy('id')->limit(2)->get()->all();
        $this->assertSame($encoder->id, $first->created_by);
        $this->assertNotSame($encoder->id, $second->created_by);
        $this->assertSame(1, Cheque::where('created_by', $encoder->id)->count());
    }

    public function test_password_matches_the_playwright_helper(): void
    {
        $helpers = file_get_contents(base_path('e2e/helpers.js'));

        $this->assertStringContainsString("PASSWORD = '".E2eUserSeeder::PASSWORD."'", $helpers);
    }

    public function test_refuses_to_run_outside_test_environments(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['production', 'staging', 'local'] as $env) {
            $this->app['env'] = $env;
            try {
                (new E2eUserSeeder)->run();
                $this->fail("E2eUserSeeder ran with APP_ENV={$env}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('only runs when APP_ENV is e2e or testing', $e->getMessage());
            }
        }

        $this->app['env'] = 'testing';
        $this->assertSame(0, User::where('email', 'like', 'e2e-%')->count());
    }
}
