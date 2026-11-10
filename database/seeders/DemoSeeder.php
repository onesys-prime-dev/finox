<?php

namespace Database\Seeders;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Cheque;
use App\Models\Payee;
use App\Models\PrintTemplate;
use App\Models\User;
use App\Services\ChequeService;
use Illuminate\Database\Seeder;

/**
 * Fake sample data for trying FINOX. Not run by db:seed.
 * Run:    php artisan db:seed --class=DemoSeeder
 * Remove: delete the records whose names start with "DEMO" (payees, account) and their cheques,
 *         or run migrate:fresh --seed on a system with no real data.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Refusing to add demo data in production.');

            return;
        }

        $user = User::where('email', 'admin@finox.test')->firstOrFail();

        $payees = collect(['DEMO Office Supplies Co.', 'DEMO Landlord Realty Inc.', 'DEMO Juan Dela Cruz', 'DEMO Telecom Services Inc.'])
            ->map(fn ($n) => Payee::firstOrCreate(['name' => $n]));

        $acct = BankAccount::firstOrCreate(
            ['bank_id' => Bank::where('name', 'BDO Unibank')->value('id'), 'account_number' => '0000000000'],
            [
                'branch' => 'DEMO Branch', 'account_name' => 'DEMO COMPANY INC', 'account_reference' => 'DEMO ONLY',
                'cheque_format' => 'BDO Corporate Check Printing',
                'print_template_id' => PrintTemplate::where('kind', 'cheque')->value('id'),
                'starting_number' => '0000000001',
            ]
        );

        if (Cheque::where('bank_account_id', $acct->id)->exists()) {
            return;
        }

        $svc = app(ChequeService::class);
        foreach ([[1, 0, '12500.00', 'DEMO office supplies'], [2, 1, '45000.50', 'DEMO monthly rent'], [3, 2, '3200.00', 'DEMO reimbursement']] as [$i, $p, $amt, $purpose]) {
            $svc->create([
                'bank_account_id' => $acct->id, 'cheque_number' => sprintf('%010d', $i), 'cheque_date' => now()->toDateString(),
                'payee_id' => $payees[$p]->id, 'amount' => $amt, 'purpose' => $purpose,
            ], $user);
        }
    }
}
