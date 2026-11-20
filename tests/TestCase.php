<?php

namespace Tests;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Payee;
use App\Models\PrintTemplate;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
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
        Payee::firstOrCreate(['name' => 'Test Payee']);
        BankAccount::firstOrCreate(['bank_id' => Bank::where('name', 'BDO Unibank')->value('id'), 'account_number' => '0000000000'], [
            'branch' => 'Test Branch', 'account_name' => 'TEST ACCOUNT', 'cheque_format' => 'BDO Corporate Check Printing',
            'print_template_id' => PrintTemplate::where('kind', 'cheque')->value('id'), 'starting_number' => '0000094658',
        ]);
    }

    protected function userWithRole(string $role): User
    {
        if (! Role::where('name', 'admin')->exists()) {
            $this->seed(DatabaseSeeder::class);
        }

        $this->fixtures();

        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
    }
}
