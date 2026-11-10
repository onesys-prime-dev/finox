<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Payee;
use App\Services\ChequeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    private function readXlsx(string $bytes): array
    {
        $f = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($f, $bytes);
        $r = new Reader;
        $r->open($f);
        $rows = [];
        foreach ($r->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $r->close();
        unlink($f);

        return $rows;
    }

    public function test_xlsx_and_csv_exports(): void
    {
        $fin = $this->userWithRole('finance');
        app(ChequeService::class)->create(['bank_account_id' => BankAccount::first()->id, 'cheque_number' => '7000000001',
            'cheque_date' => now()->toDateString(), 'payee_id' => Payee::first()->id, 'amount' => '1234.50'], $fin);

        $x = $this->actingAs($fin)->get('/reports/cheque-register/export?format=xlsx');
        $x->assertOk();
        $rows = $this->readXlsx($x->streamedContent());
        $this->assertGreaterThanOrEqual(2, count($rows), 'header + one data row');
        $this->assertContains(1234.5, array_map(fn ($v) => is_numeric($v) ? (float) $v : $v, $rows[1]), 'amount is a number in Excel');

        $reg = $this->actingAs($fin)->get('/transactions/export?format=xlsx');
        $this->assertCount(2, $this->readXlsx($reg->streamedContent()));

        foreach (['/reports/cheque-register/export?format=pdf', '/transactions/export?format=pdf'] as $u) {
            $pdf = $this->actingAs($fin)->get($u);
            $pdf->assertOk();
            $this->assertStringStartsWith('%PDF', $pdf->streamedContent(), $u);
        }

        $csv = $this->actingAs($fin)->get('/transactions/export');
        $this->assertStringContainsString('7000000001', $csv->streamedContent());
    }
}
