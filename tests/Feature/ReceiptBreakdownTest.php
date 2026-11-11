<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Payee;
use App\Models\Receipt;
use App\Services\ChequeService;
use App\Services\PrintingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptBreakdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_receipts_become_a_breakdown_for_the_advice_area(): void
    {
        $fin = $this->userWithRole('finance');
        $payee = Payee::first();
        $c = app(ChequeService::class)->create(['bank_account_id' => BankAccount::first()->id, 'cheque_number' => '0000094777',
            'cheque_date' => now()->toDateString(), 'payee_id' => $payee->id, 'amount' => '5000.50'], $fin);
        foreach ([['OR-1', '1000.25', 'recorded'], ['OR-2', '4000.25', 'recorded'], ['OR-3', '999', 'cancelled']] as [$no, $amt, $st]) {
            Receipt::create(['receipt_number' => $no, 'receipt_date' => now()->toDateString(), 'payee_id' => $payee->id, 'amount' => $amt,
                'status' => $st, 'cheque_id' => $c->id, 'created_by' => $fin->id]);
        }
        $svc = app(PrintingService::class);
        $v = $svc->values($svc->load('cheque', [$c->id])->first(), $fin);

        $this->assertSame("1,000.25\n4,000.25", $v['receipt_amounts']);
        $this->assertSame('5,000.50', $v['receipt_total']);
        $this->assertStringStartsWith('OR-1 · ', explode("\n", $v['receipt_refs'])[0]);
        $this->assertStringNotContainsString('OR-3', $v['receipt_refs']);
    }
}
