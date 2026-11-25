<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Payee;
use App\Services\ChequeService;
use App\Services\PrintingService;
use App\Support\Money;
use App\Support\TableExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private function data(): array
    {
        return ['bank_account_id' => BankAccount::first()->id, 'cheque_number' => '0000094701', 'cheque_date' => now()->toDateString(),
            'payee_id' => Payee::first()->id, 'amount' => '100.00'];
    }

    public function test_cancel_only_before_print_and_void_only_after(): void
    {
        $fin = $this->userWithRole('finance');
        $s = app(ChequeService::class);
        $c = $s->create($this->data(), $fin);
        $s->prepare($c, $fin);
        try {
            $s->stop($c->refresh(), 'void', 'nope', null, $fin);
            $this->fail('void of never-printed cheque should be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('kind', $e->errors());
        }
        app(PrintingService::class)->start($fin, 'cheque', [$c->id], false, null);
        $s->release($c->refresh(), ['released_at' => now()->toDateString(), 'released_to' => 'x', 'released_by' => 'y'], $fin);
        $this->expectException(ValidationException::class);
        $s->stop($c->refresh(), 'cancel', 'sneaky', null, $fin);
    }

    public function test_csv_cells_starting_with_formula_chars_are_neutralised(): void
    {
        $m = new \ReflectionMethod(TableExport::class, 'safeCell');
        $m->setAccessible(true);
        $this->assertSame("'=SUM(A1)", $m->invoke(null, '=SUM(A1)'));
        $this->assertSame("'@x", $m->invoke(null, '@x'));
        $this->assertSame('-12.50', $m->invoke(null, '-12.50'));
        $this->assertSame('Normal', $m->invoke(null, 'Normal'));
    }

    public function test_money_parse_rejects_ambiguous_commas(): void
    {
        $this->assertSame('1234.50', Money::parse('1,234.5'));
        $this->assertSame('1234567.00', Money::parse('₱ 1,234,567'));
        $this->assertSame('15.00', Money::parse('15'));
        foreach (['1,5', '12,34', '1,2345', ',5', '1,,000'] as $bad) {
            $this->assertNull(Money::parse($bad), $bad);
        }
    }

    public function test_background_ajax_calls_do_not_swallow_a_pending_flash_message(): void
    {
        $u = $this->userWithRole('finance');
        $this->actingAs($u)->withSession(['_flash' => ['old' => [], 'new' => ['success']], 'success' => 'Saved.']);
        // a background JSON call between the redirect and the page load
        $this->getJson('/payees/search?q=a')->assertOk();
        $this->assertSame('Saved.', session('success'));
        $this->get('/dashboard')->assertOk();
    }
}
