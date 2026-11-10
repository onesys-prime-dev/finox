<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function userWithRole(string $role): User
    {
        if (! Role::where('name', 'admin')->exists()) {
            $this->seed(\Database\Seeders\DatabaseSeeder::class);
        }

        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
    }
}
