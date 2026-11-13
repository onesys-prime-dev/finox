<?php

namespace Tests\Feature;

use App\Http\Controllers\PrintingController;
use App\Models\BankAccount;
use App\Models\Cheque;
use App\Models\Payee;
use App\Models\PrintTemplate;
use App\Models\Receipt;
use App\Services\ChequeService;
use App\Services\ReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Regression tests for the second code review (breakdown feature). */
class BreakdownReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 96100;

    private function data(array $o = []): array
    {
        return $o + ['bank_account_id' => BankAccount::first()->id, 'cheque_number' => (string) (++$this->n), 'cheque_date' => now()->toDateString(),
            'payee_id' => Payee::first()->id, 'amount' => '100'];
    }

    private function receipt($user, string $no, string $status = 'recorded'): Receipt
    {
        return Receipt::create(['receipt_number' => $no, 'receipt_date' => now()->toDateString(), 'payee_id' => Payee::first()->id,
            'amount' => '100', 'status' => $status, 'created_by' => $user->id]);
    }

    private function expectInvalid(callable $fn, string $key = 'lines'): ValidationException
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());

            return $e;
        }
        $this->fail("Expected a validation error on {$key}");
    }

    // 1. One receipt, one active cheque — across the receipt form and the breakdown.
    public function test_receipt_linked_from_the_receipt_form_cannot_be_added_to_another_breakdown(): void
    {
        $fin = $this->userWithRole('finance');
        $s = app(ChequeService::class);
        $a = $s->create($this->data(), $fin);
        $r = $this->receipt($fin, 'OR-LINK');
        $r->forceFill(['cheque_id' => $a->id])->save(); // linked from the receipt form

        $this->actingAs($fin)->getJson('/cheques/available-receipts')->assertOk()->assertJsonMissing(['id' => $r->id]);
        $this->expectInvalid(fn () => $s->create($this->data(['lines' => [['receipt_id' => $r->id, 'amount' => '100']]]), $fin));
        $this->assertSame($a->id, $r->refresh()->cheque_id, 'The receipt must stay on cheque A');

        // Once A is cancelled the receipt is free again.
        $s->stop($a, 'cancel', 'test', null, $fin);
        $b = $s->create($this->data(['lines' => [['receipt_id' => $r->id, 'amount' => '100']]]), $fin);
        $this->assertSame($b->id, $r->refresh()->cheque_id);
    }

    public function test_receipt_on_a_breakdown_cannot_be_moved_from_the_receipt_form(): void
    {
        $fin = $this->userWithRole('finance');
        $s = app(ChequeService::class);
        $r = $this->receipt($fin, 'OR-BD');
        $a = $s->create($this->data(['lines' => [['receipt_id' => $r->id, 'amount' => '100']]]), $fin);
        $other = $s->create($this->data(), $fin);

        $this->expectInvalid(fn () => app(ReceiptService::class)->update($r, ['cheque_id' => $other->id, 'amount' => '100'], $fin), 'cheque_id');
        $this->expectInvalid(fn () => app(ReceiptService::class)->update($r, ['cheque_id' => null, 'amount' => '100'], $fin), 'cheque_id');
        // Saving the receipt with its own cheque is fine.
        app(ReceiptService::class)->update($r, ['cheque_id' => $a->id, 'amount' => '100', 'remarks' => 'ok'], $fin);
        $this->assertSame($a->id, $r->refresh()->cheque_id);
    }

    // 2. Only visible, usable receipts can be added.
    public function test_breakdown_rejects_receipts_the_user_cannot_see_or_that_are_not_ready(): void
    {
        $fin = $this->userWithRole('finance');
        $enc = $this->userWithRole('encoder');
        $s = app(ChequeService::class);

        $notMine = $this->receipt($fin, 'OR-FIN');
        $this->expectInvalid(fn () => $s->create($this->data(['lines' => [['receipt_id' => $notMine->id, 'amount' => '100']]]), $enc));

        $archived = $this->receipt($fin, 'OR-ARC', 'archived');
        $this->expectInvalid(fn () => $s->create($this->data(['lines' => [['receipt_id' => $archived->id, 'amount' => '100']]]), $fin));
        $draft = $this->receipt($enc, 'OR-DRAFT', 'draft');
        $this->expectInvalid(fn () => $s->create($this->data(['lines' => [['receipt_id' => $draft->id, 'amount' => '100']]]), $enc));

        $this->assertNull($notMine->refresh()->cheque_id);
        $this->assertNull($archived->refresh()->cheque_id);
    }

    public function test_receipt_already_on_the_cheque_can_be_kept_after_its_status_moves_on(): void
    {
        $fin = $this->userWithRole('finance');
        $s = app(ChequeService::class);
        $r = $this->receipt($fin, 'OR-KEEP');
        $c = $s->create($this->data(['lines' => [['receipt_id' => $r->id, 'amount' => '100']]]), $fin);
        $r->forceFill(['status' => 'archived'])->save();

        $s->syncLines($c, [['receipt_id' => $r->id, 'amount' => '100', 'particulars' => 'edited']], $fin);
        $this->assertSame('edited', $c->lines()->first()->particulars);
    }

    // 3. A non-scalar amount is a validation error, not a 500.
    public function test_array_amount_in_a_row_is_a_validation_error(): void
    {
        $fin = $this->userWithRole('finance');
        $this->actingAs($fin)->post('/cheques', $this->data(['lines' => [['amount' => ['1']]]]))
            ->assertSessionHasErrors('lines.0.amount');
    }

    // 4 + 5. Overflow count matches what prints, and shrink keeps rows that fit.
    private function overflow(Cheque $c): int
    {
        $m = new \ReflectionMethod(PrintingController::class, 'overflowRows');

        return $m->invoke(app(PrintingController::class), $c->load('lines'));
    }

    private function setTable(array $patch): void
    {
        $t = PrintTemplate::find(BankAccount::first()->print_template_id);
        $layout = $t->layout;
        foreach ($layout['fields'] as &$f) {
            if (($f['type'] ?? '') === 'table') {
                $f = $patch + $f;
            }
        }
        $t->update(['layout' => $layout]);
    }

    public function test_overflow_count_matches_the_rows_sent_to_the_schedule(): void
    {
        $fin = $this->userWithRole('finance');
        $rows = fn (int $n) => array_map(fn ($i) => ['reference_no' => "R{$i}", 'amount' => '1'], range(1, $n));
        $s = app(ChequeService::class);

        $this->setTable(['max_rows' => 15, 'overflow' => 'schedule']);
        $this->assertSame(0, $this->overflow($s->create($this->data(['lines' => $rows(15)]), $fin)));
        // 16 rows on 15 slots: 14 print + "…and 2 more".
        $this->assertSame(2, $this->overflow($s->create($this->data(['lines' => $rows(16)]), $fin)));

        // Shrink: 23 slots at 10pt down to 7pt fits 30 rows (23 / (23/30) is 29.999… in floating point).
        $this->setTable(['max_rows' => 23, 'font_size' => 10, 'min_font_size' => 7, 'overflow' => 'shrink']);
        $this->assertSame(0, $this->overflow($s->create($this->data(['lines' => $rows(30)]), $fin)));
        // 40 rows: cap is floor(23 / 0.7) = 32, so 31 print + "…and 9 more".
        $this->assertSame(9, $this->overflow($s->create($this->data(['lines' => $rows(40)]), $fin)));
    }

    // 7. "breakdown" belongs to table fields only.
    public function test_text_field_cannot_show_the_breakdown(): void
    {
        $admin = $this->userWithRole('admin');
        $t = PrintTemplate::where('kind', 'cheque')->first();
        $layout = $t->layout;
        $layout['fields'][] = ['key' => 'oops', 'source' => 'breakdown', 'x' => 10, 'y' => 10, 'width' => 50, 'font_size' => 9, 'align' => 'left', 'uppercase' => false];
        $this->actingAs($admin)->post("/admin/templates/{$t->id}", ['name' => $t->name, 'kind' => 'cheque', 'paper_width_mm' => 200, 'paper_height_mm' => 280,
            'offset_x_mm' => 0, 'offset_y_mm' => 0, 'is_active' => 1, 'layout' => $layout])->assertSessionHasErrors();
        $this->assertNull(collect($t->fresh()->layout['fields'])->firstWhere('key', 'oops'));
    }

    // 8. The BDO upgrade keeps advice fields an admin added.
    public function test_bdo_upgrade_migration_keeps_custom_advice_fields(): void
    {
        $this->userWithRole('admin');
        $t = PrintTemplate::where('name', 'BDO Corporate Check Printing (3-part)')->first();
        $old = $t->layout;
        $old['fields'] = array_values(array_filter($old['fields'], fn ($f) => ($f['region'] ?? null) !== 'advice'));
        $old['fields'][] = ['key' => 'adv_payee', 'source' => 'payee_line', 'x' => 20, 'y' => 100, 'width' => 150, 'font_size' => 11, 'align' => 'left', 'region' => 'advice'];
        $old['fields'][] = ['key' => 'adv_rcp_refs', 'source' => 'receipt_refs', 'x' => 20, 'y' => 168, 'width' => 120, 'font_size' => 9, 'align' => 'left', 'region' => 'advice'];
        $old['fields'][] = ['key' => 'acct_code', 'source' => 'free_text', 'text' => 'Acct code 5100', 'x' => 150, 'y' => 195, 'width' => 40, 'font_size' => 9, 'align' => 'left', 'region' => 'advice'];
        $t->update(['layout' => $old]);
        $nonAdvice = count(array_filter($old['fields'], fn ($f) => ($f['region'] ?? null) !== 'advice'));

        (require database_path('migrations/2026_11_11_000002_upgrade_bdo_template_advice.php'))->up();

        $keys = array_column($t->fresh()->layout['fields'], 'key');
        $this->assertContains('breakdown', $keys);
        $this->assertContains('acct_code', $keys, 'The admin-added advice field must survive');
        $this->assertNotContains('adv_payee', $keys);
        $this->assertNotContains('adv_rcp_refs', $keys);
        $after = array_filter($t->fresh()->layout['fields'], fn ($f) => ($f['region'] ?? null) !== 'advice');
        $this->assertCount($nonAdvice, $after, 'Cheque-face fields are untouched');
    }
}
