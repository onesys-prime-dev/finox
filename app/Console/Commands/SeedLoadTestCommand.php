<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\Payee;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Bulk-generate realistic volume for performance testing (Phase 8). NOT for production. */
class SeedLoadTestCommand extends Command
{
    protected $signature = 'finox:seed-loadtest {count=50000 : Total cheques + receipts} {--force}';

    protected $description = 'Generate cheques and receipts to measure performance';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Refusing to run in production without --force.');

            return self::FAILURE;
        }
        $n = (int) $this->argument('count');
        $user = User::first() ?? abort(1, 'Run db:seed first.');
        $acct = BankAccount::first() ?? abort(1, 'Add a bank account in the app first.');
        $payees = collect(range(1, 300))->map(fn ($i) => ['name' => "Load Test Payee {$i} Corporation", 'created_at' => now(), 'updated_at' => now()]);
        Payee::insert($payees->all());
        $ids = Payee::pluck('id')->all();
        $statuses = ['draft', 'prepared', 'printed', 'released', 'for_clearing', 'cleared', 'cleared', 'cleared', 'bounced', 'cancelled'];
        $rstat = ['recorded', 'verified', 'processed', 'archived', 'archived'];
        $start = (int) DB::table('cheques')->max('id') + 1;

        $this->output->progressStart($n);
        foreach (array_chunk(range(1, $n), 1000) as $chunk) {
            $ch = $rc = [];
            foreach ($chunk as $i) {
                $d = now()->subDays(random_int(0, 700))->toDateString();
                $amt = number_format(random_int(100, 99999999) / 100, 2, '.', '');
                if ($i % 2) {
                    $ch[] = ['bank_account_id' => $acct->id, 'cheque_number' => sprintf('9%09d', $start++), 'cheque_date' => $d, 'payee_id' => $ids[array_rand($ids)],
                        'amount' => $amt, 'amount_words' => 'LOAD TEST', 'purpose' => 'Load test '.$i, 'reference_no' => 'LT-'.$i, 'status' => $statuses[array_rand($statuses)],
                        'created_by' => $user->id, 'created_at' => $d.' 09:00:00', 'updated_at' => $d.' 09:00:00'];
                } else {
                    $rc[] = ['receipt_number' => 'LT-R'.$i, 'receipt_date' => $d, 'payee_id' => $ids[array_rand($ids)], 'description' => 'Load test receipt',
                        'amount' => $amt, 'status' => $rstat[array_rand($rstat)], 'created_by' => $user->id, 'created_at' => $d.' 09:00:00', 'updated_at' => $d.' 09:00:00'];
                }
            }
            $ch && DB::table('cheques')->insert($ch);
            $rc && DB::table('receipts')->insert($rc);
            $this->output->progressAdvance(count($chunk));
        }
        $this->output->progressFinish();
        $this->info('Done. Remove with: php artisan migrate:fresh --seed');

        return self::SUCCESS;
    }
}
