<?php

namespace Database\Seeders;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Cheque;
use App\Models\Payee;
use App\Models\PrintTemplate;
use App\Models\Receipt;
use App\Models\ReceiptCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\ChequeService;
use App\Services\DocumentService;
use App\Services\PrintingService;
use App\Services\ReceiptService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * Made-up sample data for presentations and training. Every name, number and amount is fictional.
 * Not run by db:seed.
 *
 *   Add:    php artisan db:seed --class=DemoSeeder
 *   Remove: php artisan migrate:fresh --seed        (wipes EVERYTHING, so only before real data exists)
 *
 * Sample logins (password Demo#Pass2026): demo-finance@, demo-encoder@, demo-printing@, demo-management@finox.test
 */
class DemoSeeder extends Seeder
{
    private ChequeService $cheques;

    private User $admin;

    private User $finance;

    private User $encoder;

    private User $printing;

    private int $n = 100000;

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Refusing to add sample data in production.');

            return;
        }

        $this->admin = User::where('email', 'admin@finox.test')->firstOrFail();
        $make = fn (string $role, string $name) => User::firstOrCreate(['email' => "demo-{$role}@finox.test"], [
            'name' => $name, 'password' => 'Demo#Pass2026', 'role_id' => Role::where('name', $role)->value('id'),
            'must_change_password' => false, 'is_active' => true,
        ]);
        $this->finance = $make('finance', 'Maria Santos');
        $this->encoder = $make('encoder', 'Paolo Reyes');
        $this->printing = $make('printing', 'Liza Garcia');
        $make('management', 'Ramon Villanueva');
        $acct = BankAccount::firstOrCreate(
            ['bank_id' => Bank::where('name', 'BDO Unibank')->value('id'), 'account_number' => '001234567890'],
            [
                'branch' => 'Makati Main Branch', 'account_name' => 'SAMPLE TRADING CORPORATION', 'account_reference' => 'SAMPLE ONLY',
                'cheque_format' => 'BDO Corporate Check Printing', 'print_template_id' => PrintTemplate::where('kind', 'cheque')->value('id'),
                'starting_number' => '0000100001',
            ]
        );
        if (Cheque::where('bank_account_id', $acct->id)->exists()) {
            return;
        }

        $p = collect([
            'meralco' => 'Manila Electric Company', 'pldt' => 'PLDT Inc.', 'water' => 'Maynilad Water Services, Inc.',
            'office' => 'Metro Office Supplies Inc.', 'realty' => 'Greenfield Realty Corporation', 'law' => 'Santos & Reyes Law Office',
            'juan' => 'Juan A. Dela Cruz', 'maria' => 'Maria L. Mendoza', 'courier' => 'Swift Courier Services',
        ])->map(fn ($n) => Payee::firstOrCreate(['name' => $n]));
        $bir = Payee::firstOrCreate(['name' => 'Bureau of Internal Revenue'], [
            'default_payee_line' => 'BPI Ayala II Branch FAO Bureau of Internal Revenue',
        ]);

        $this->cheques = app(ChequeService::class);
        $print = app(PrintingService::class);

        // [payee, amount, purpose, reference, days ago (cheque date), final stage]
        $plan = [
            [$p['realty'], '85000.00', 'Office rent, October', 'INV-2210', 38, 'cleared'],
            [$p['meralco'], '42318.75', 'Electricity, September', 'BILL-0921', 35, 'cleared'],
            [$p['pldt'], '12480.00', 'Internet and phone lines', 'ACCT-5521', 33, 'cleared'],
            [$p['office'], '18950.50', 'Printer paper and toner', 'PO-3381', 30, 'cleared'],
            [$p['law'], '60000.00', 'Retainer fee, Q4', 'RET-004', 28, 'bounced'],
            [$p['water'], '3875.20', 'Water bill, September', 'BILL-0922', 24, 'for_clearing'],
            [$p['courier'], '2640.00', 'Courier charges', 'SCS-8841', 21, 'for_clearing'],
            [$p['juan'], '15000.00', 'Consultancy fee', 'CF-017', 18, 'released'],
            [$p['realty'], '85000.00', 'Office rent, November', 'INV-2211', 8, 'released'],
            [$p['office'], '7420.00', 'Pantry and cleaning supplies', 'PO-3402', 6, 'printed'],
            [$p['meralco'], '39765.40', 'Electricity, October', 'BILL-1021', 4, 'printed_failed'],
            [$p['pldt'], '12480.00', 'Internet and phone lines, Nov', 'ACCT-5521', 3, 'prepared'],
            [$p['maria'], '5200.00', 'Reimbursement: client lunch', 'RMB-091', 2, 'prepared'],
            [$p['water'], '4012.65', 'Water bill, October', 'BILL-1022', 1, 'draft'],
            [$bir, '28750.00', 'Withholding tax remittance', 'BIR-1601', 1, 'draft'],
            [$p['courier'], '1850.00', 'Courier charges (wrong amount)', 'SCS-8860', 12, 'cancelled'],
            [$p['juan'], '9000.00', 'Duplicate payment', 'CF-018', 15, 'voided'],
            [$p['law'], '25000.00', 'Contract review', 'LR-221', 200, 'stale'],
        ];

        foreach ($plan as [$payee, $amount, $purpose, $ref, $ago, $stage]) {
            $date = now()->subDays($ago)->toDateString();
            $c = $this->cheques->create([
                'bank_account_id' => $acct->id, 'cheque_number' => sprintf('%010d', ++$this->n), 'cheque_date' => $date,
                'payee_id' => $payee->id, 'amount' => $amount, 'purpose' => $purpose, 'reference_no' => $ref,
            ], $stage === 'draft' ? $this->encoder : $this->finance);
            Cheque::whereKey($c->id)->update(['created_at' => now()->subDays($ago)->setTime(9, 15), 'updated_at' => now()->subDays($ago)->setTime(9, 15)]);

            if (in_array($stage, ['draft', 'voided'], true)) { // voided one is finished after the loop
                continue;
            }
            if ($stage === 'cancelled') {
                $this->cheques->stop($c, 'cancel', 'Amount keyed incorrectly; to be reissued', null, $this->finance);

                continue;
            }
            $this->cheques->prepare($c, $this->finance);
            if ($stage === 'prepared') {
                continue;
            }

            $job = $print->start($this->printing, 'cheque', [$c->id], false, null);
            if ($stage === 'printed_failed') {
                $print->confirm($job, $this->printing, [$c->id], 'Printed too low on the paper; reprinting');
                $print->start($this->printing, 'cheque', [$c->id], true, 'Paper misaligned');

                continue;
            }
            $print->confirm($job, $this->printing);
            if ($stage === 'printed') {
                continue;
            }

            $released = now()->subDays(max(0, $ago - 1))->toDateString();
            $this->cheques->release($c->refresh(), [
                'released_at' => $released, 'released_to' => $payee->name, 'released_by' => $this->finance->name, 'remarks' => 'Received by authorized representative',
            ], $this->finance);
            if ($stage === 'released') {
                continue;
            }
            if ($stage === 'stale') {
                \DB::table('cheque_releases')->where('cheque_id', $c->id)->update(['released_at' => now()->subDays(195)->toDateString()]);
                $this->cheques->flagStale();

                continue;
            }

            $this->cheques->markForClearing($c->refresh(), $this->finance);
            if ($stage === 'for_clearing') {
                continue;
            }
            $this->cheques->recordClearing($c->refresh(), [
                'result' => $stage === 'bounced' ? 'bounced' : 'cleared',
                'cleared_at' => now()->subDays(max(0, $ago - 4))->toDateString(),
                'remarks' => $stage === 'bounced' ? 'Returned: account closed (entered from bank advice)' : 'Posted on bank statement',
            ], $this->finance);
        }

        // Void one that was released, with a stop-payment note.
        $v = Cheque::where('purpose', 'Duplicate payment')->first();
        $this->cheques->prepare($v, $this->finance);
        $job = $print->start($this->printing, 'cheque', [$v->id], false, null);
        $print->confirm($job, $this->printing);
        $this->cheques->release($v->refresh(), ['released_at' => now()->subDays(14)->toDateString(), 'released_to' => 'Juan A. Dela Cruz', 'released_by' => 'Maria Santos'], $this->finance);
        $this->cheques->stop($v->refresh(), 'void', 'Paid twice by mistake', 'Bank notified to stop payment on this cheque', $this->finance);

        $this->receipts($p);
    }

    private function receipts($p): void
    {
        $svc = app(ReceiptService::class);
        $docs = app(DocumentService::class);
        $cat = fn ($n) => ReceiptCategory::where('name', $n)->value('id');
        $pdf = function (string $no, string $vendor, string $amount) {
            $path = tempnam(sys_get_temp_dir(), 'rcpt').'.pdf';
            file_put_contents($path, Pdf::loadHTML("<h2 style='font-family:sans-serif'>Official Receipt No. {$no}</h2><p style='font-family:sans-serif'>Vendor: {$vendor}<br>Amount: PHP {$amount}<br><br><i>Sample document for demonstration only.</i></p>")->output());

            return new UploadedFile($path, "receipt-{$no}.pdf", 'application/pdf', null, true);
        };

        // [receipt no, vendor, amount, category, days ago, final stage, tax rate]
        $rows = [
            ['OR-23-0451', $p['office'], '18950.50', 'Supplies', 30, 'archived', 12],
            ['OR-23-0462', $p['meralco'], '42318.75', 'Utilities', 34, 'archived', 12],
            ['SI-88213', $p['pldt'], '12480.00', 'Utilities', 32, 'processed', 12],
            ['OR-23-0517', $p['law'], '60000.00', 'Professional Fees', 27, 'processed', null],
            ['SI-88399', $p['water'], '3875.20', 'Utilities', 23, 'verified', null],
            ['OR-23-0530', $p['courier'], '2640.00', 'Other', 20, 'recorded', null],
            ['OR-23-0541', $p['realty'], '85000.00', 'Other', 7, 'recorded', 12],
            ['OR-23-0552', $p['office'], '7420.00', 'Supplies', 5, 'recorded', 12],
            ['DUP-0001', $p['courier'], '500.00', 'Other', 10, 'cancelled', null],
            [null, $p['juan'], '1250.00', 'Other', 1, 'draft', null],
        ];
        foreach ($rows as [$no, $payee, $amt, $category, $ago, $stage, $rate]) {
            $user = in_array($stage, ['draft', 'recorded'], true) ? $this->encoder : $this->finance;
            $r = $svc->create([
                'receipt_number' => $no, 'receipt_date' => now()->subDays($ago)->toDateString(), 'payee_id' => $payee->id, 'amount' => $amt,
                'category_id' => $cat($category), 'tax_rate' => $rate, 'tax_amount' => $rate ? round((float) $amt * $rate / (100 + $rate), 2) : null,
                'description' => 'Sample receipt for '.strtolower($category),
            ], $user, record: $stage !== 'draft');
            if ($no) {
                $docs->store($pdf($no, $payee->name, number_format((float) $amt, 2)), 'receipt', $r, $user);
            }
            Receipt::whereKey($r->id)->update(['created_at' => now()->subDays($ago)->setTime(10, 30)]);
            match ($stage) {
                'verified' => $svc->verify($r, $this->finance),
                'processed' => $svc->process($svc->verify($r, $this->finance), $this->finance),
                'archived' => $svc->archive($svc->process($svc->verify($r, $this->finance), $this->finance), $this->finance),
                'cancelled' => $svc->cancel($r, 'Entered twice by mistake', $this->finance),
                default => null,
            };
        }

        // Link two receipts to the cheques they support.
        foreach ([['OR-23-0451', 'Printer paper and toner'], ['OR-23-0462', 'Electricity, September']] as [$no, $purpose]) {
            Receipt::where('receipt_number', $no)->update(['cheque_id' => Cheque::where('purpose', $purpose)->value('id')]);
        }
    }
}
