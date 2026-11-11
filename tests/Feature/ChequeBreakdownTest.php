<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Payee;
use App\Models\Receipt;
use App\Services\ChequeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChequeBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 94800;

    private function data(array $o = []): array
    {
        return $o + ['bank_account_id' => BankAccount::first()->id, 'cheque_number' => (string) (++$this->n), 'cheque_date' => now()->toDateString(),
            'payee_id' => Payee::first()->id, 'amount' => '35,500.50'];
    }

    private function receipt($user, string $no, string $amount): Receipt
    {
        return Receipt::create(['receipt_number' => $no, 'receipt_date' => now()->toDateString(), 'payee_id' => Payee::first()->id,
            'amount' => $amount, 'status' => 'recorded', 'created_by' => $user->id]);
    }

    public function test_rows_are_saved_in_order_with_total_and_receipt_links(): void
    {
        $fin = $this->userWithRole('finance');
        $r1 = $this->receipt($fin, 'OR-1', '10000');
        $c = app(ChequeService::class)->create($this->data(['lines' => [
            ['receipt_id' => $r1->id, 'reference_no' => 'OR-1', 'vendor' => 'A Corp', 'particulars' => 'Supplies', 'amount' => '10,000.00'],
            ['reference_no' => 'INV-2', 'vendor' => 'B Corp', 'particulars' => 'Toner', 'amount' => '25500.50', 'line_date' => '2026-11-03'],
            ['amount' => '', 'reference_no' => ''], // blank rows are ignored
        ]]), $fin);

        $c->load('lines');
        $this->assertCount(2, $c->lines);
        $this->assertSame([1, 2], $c->lines->pluck('line_no')->all());
        $this->assertSame('35500.50', $c->linesTotal());
        $this->assertSame($c->id, $r1->refresh()->cheque_id);
        $this->assertTrue(ActivityLog::where('action', 'cheque.breakdown_updated')->exists());
    }

    public function test_receipt_can_be_on_only_one_active_cheque_and_is_freed_when_voided(): void
    {
        $fin = $this->userWithRole('finance');
        $s = app(ChequeService::class);
        $r = $this->receipt($fin, 'OR-9', '100');
        $a = $s->create($this->data(['amount' => '100', 'lines' => [['receipt_id' => $r->id, 'amount' => '100']]]), $fin);

        try {
            $s->create($this->data(['amount' => '100', 'lines' => [['receipt_id' => $r->id, 'amount' => '100']]]), $fin);
            $this->fail('A receipt on an active cheque must not be reusable');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
        }

        $s->stop($a->refresh(), 'cancel', 'wrong cheque', null, $fin);
        $this->assertNull($r->refresh()->cheque_id);
        $b = $s->create($this->data(['amount' => '100', 'lines' => [['receipt_id' => $r->id, 'amount' => '100']]]), $fin);
        $this->assertSame($b->id, $r->refresh()->cheque_id);
    }

    public function test_rows_lock_when_prepared_and_a_mismatch_only_warns(): void
    {
        $fin = $this->userWithRole('finance');
        $s = app(ChequeService::class);
        $c = $s->create($this->data(['amount' => '500', 'lines' => [['amount' => '200', 'particulars' => 'short']]]), $fin);

        $s->prepare($c, $fin); // total 200 != 500: allowed, but logged
        $this->assertSame('prepared', $c->refresh()->status->value);
        $this->assertTrue(ActivityLog::where('action', 'cheque.breakdown_mismatch')->exists());

        $s->update($c, ['purpose' => 'x', 'lines' => [['amount' => '999']]], $fin);
        $this->assertSame('200.00', $c->refresh()->load('lines')->linesTotal());
    }

    public function test_duplicate_copies_typed_rows_but_not_receipt_links(): void
    {
        $fin = $this->userWithRole('finance');
        $s = app(ChequeService::class);
        $r = $this->receipt($fin, 'OR-5', '50');
        $c = $s->create($this->data(['amount' => '50', 'lines' => [['receipt_id' => $r->id, 'reference_no' => 'OR-5', 'amount' => '50']]]), $fin);

        $attrs = $s->duplicateAttributes($c->load('lines'));
        $this->assertCount(1, $attrs['lines']);
        $this->assertNull($attrs['lines'][0]['receipt_id']);
        $this->assertSame('OR-5', $attrs['lines'][0]['reference_no']);
    }

    public function test_bad_amount_in_a_row_is_rejected(): void
    {
        $fin = $this->userWithRole('finance');
        $this->expectException(ValidationException::class);
        app(ChequeService::class)->create($this->data(['lines' => [['reference_no' => 'X', 'amount' => '1,5']]]), $fin);
    }
}
