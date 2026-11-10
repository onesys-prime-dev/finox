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

    /** Test-only payee and bank account (the seeder creates none). */
    protected function fixtures(): void
    {
        \App\Models\Payee::firstOrCreate(['name' => 'Test Payee']);
        \App\Models\BankAccount::firstOrCreate(['bank_id' => \App\Models\Bank::where('name', 'BDO Unibank')->value('id'), 'account_number' => '0000000000'], [
            'branch' => 'Test Branch', 'account_name' => 'TEST ACCOUNT', 'cheque_format' => 'BDO Corporate Check Printing',
            'print_template_id' => \App\Models\PrintTemplate::where('kind', 'cheque')->value('id'), 'starting_number' => '0000094658',
        ]);
    }

    protected function userWithRole(string $role): User
    {
        if (! Role::where('name', 'admin')->exists()) {
            $this->seed(\Database\Seeders\DatabaseSeeder::class);
        }

        $this->fixtures();

        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
    }
}
