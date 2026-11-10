<?php

namespace Tests\Feature;

use App\Enums\ChequeStatus;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Cheque;
use App\Models\Payee;
use App\Services\ChequeService;
use App\Services\PrintingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChequeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function data(array $o = []): array
    {
        return $o + [
            'bank_account_id' => BankAccount::first()->id, 'cheque_number' => '0000094700', 'cheque_date' => now()->toDateString(),
            'payee_id' => Payee::first()->id, 'amount' => '1,250.50', 'purpose' => 'Test',
        ];
    }

    public function test_full_lifecycle_logs_every_step(): void
    {
        $fin = $this->userWithRole('finance');
        $s = app(ChequeService::class);
        $c = $s->create($this->data(), $fin);
        $this->assertSame('ONE THOUSAND TWO HUNDRED FIFTY AND 50/100 PESOS ONLY', $c->amount_words);

        $s->prepare($c, $fin);
        app(PrintingService::class)->start($fin, 'cheque', [$c->id], false, null);
        $c->refresh();
        $this->assertSame(ChequeStatus::Printed, $c->status);

        $s->release($c, ['released_at' => now()->toDateString(), 'released_to' => 'Payee', 'released_by' => 'Me'], $fin);
        $s->markForClearing($c->refresh(), $fin);
        $s->recordClearing($c->refresh(), ['result' => 'cleared', 'cleared_at' => now()->toDateString()], $fin);

        $this->assertSame(ChequeStatus::Cleared, $c->refresh()->status);
        $this->assertSame(6, $c->histories()->count()); // draft, prepared, printed, released, for_clearing, cleared
        $this->assertGreaterThanOrEqual(6, ActivityLog::where('subject_type', 'cheque')->where('subject_id', $c->id)->count());
    }

    public function test_duplicate_number_blocked_per_bank_account(): void
    {
        $fin = $this->userWithRole('finance');
        $s = app(ChequeService::class);
        $s->create($this->data(), $fin);
        $this->expectException(ValidationException::class);
        $s->create($this->data(), $fin);
    }

    public function test_cannot_skip_steps_or_edit_locked_fields(): void
    {
        $fin = $this->userWithRole('finance');
        $s = app(ChequeService::class);
        $c = $s->create($this->data(), $fin);
        try {
            $s->release($c, ['released_at' => now()->toDateString(), 'released_to' => 'x', 'released_by' => 'y'], $fin);
            $this->fail('Draft cheque must not be releasable');
        } catch (ValidationException) {
        }
        $s->prepare($c, $fin);
        $s->update($c->refresh(), ['amount' => '999999', 'purpose' => 'changed'], $fin);
        $c->refresh();
        $this->assertSame('1250.50', (string) $c->amount, 'amount is locked after Prepared');
        $this->assertSame('changed', $c->purpose);
    }

    public function test_void_requires_reason_and_stop_note_when_released(): void
    {
        $fin = $this->userWithRole('finance');
        $s = app(ChequeService::class);
        $c = $s->create($this->data(), $fin);
        $s->prepare($c, $fin);
        app(PrintingService::class)->start($fin, 'cheque', [$c->id], false, null);
        $s->release($c->refresh(), ['released_at' => now()->toDateString(), 'released_to' => 'x', 'released_by' => 'y'], $fin);

        try {
            $s->stop($c->refresh(), 'void', 'Wrong amount', null, $fin);
            $this->fail('Stop-payment note should be required');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stop_payment_note', $e->errors());
        }
        $s->stop($c->refresh(), 'void', 'Wrong amount', 'Bank notified', $fin);
        $this->assertSame(ChequeStatus::Voided, $c->refresh()->status);
    }

    public function test_reprint_needs_reason_and_changes_nothing(): void
    {
        $fin = $this->userWithRole('finance');
        $s = app(ChequeService::class);
        $p = app(PrintingService::class);
        $c = $s->create($this->data(), $fin);
        $s->prepare($c, $fin);
        $p->start($fin, 'cheque', [$c->id], false, null);

        try {
            $p->start($fin, 'cheque', [$c->id], true, '  ');
            $this->fail('Reason required');
        } catch (ValidationException) {
        }
        $job = $p->start($fin, 'cheque', [$c->id], true, 'Paper jam');
        $c->refresh();
        $this->assertSame(ChequeStatus::Printed, $c->status);
        $this->assertSame(2, $c->print_count);
        $this->assertTrue($job->logs()->first()->is_reprint);
        $this->assertSame(1, Cheque::count());
    }

    public function test_prepared_cheque_cannot_be_printed_without_template(): void
    {
        $fin = $this->userWithRole('finance');
        $s = app(ChequeService::class);
        $c = $s->create($this->data(), $fin);
        $s->prepare($c, $fin);
        BankAccount::query()->update(['print_template_id' => null]);
        $this->expectException(ValidationException::class);
        app(PrintingService::class)->start($fin, 'cheque', [$c->id], false, null);
    }

    public function test_activity_log_is_immutable(): void
    {
        $fin = $this->userWithRole('finance');
        app(ChequeService::class)->create($this->data(), $fin);
        $log = ActivityLog::first();
        $this->expectException(\LogicException::class);
        $log->update(['description' => 'tampered']);
    }
}
