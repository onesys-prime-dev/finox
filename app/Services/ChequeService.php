<?php

namespace App\Services;

use App\Enums\ChequeStatus;
use App\Models\BankAccount;
use App\Models\Cheque;
use App\Models\ChequeLine;
use App\Models\Receipt;
use App\Models\User;
use App\Support\AmountInWords;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChequeService
{
    /** Allowed forward moves (cancel/void handled by stop()). */
    private const FLOW = [
        'draft' => ['prepared'],
        'prepared' => ['printed'],
        'printed' => ['released'],
        'released' => ['for_clearing', 'stale'],
        'for_clearing' => ['cleared', 'bounced', 'stale'],
    ];

    public function create(array $data, User $user): Cheque
    {
        return DB::transaction(function () use ($data, $user) {
            $lines = $data['lines'] ?? null;
            $data = $this->normalise($data);
            $this->assertNoDuplicate($data['bank_account_id'], $data['cheque_number']);

            $cheque = Cheque::create($data + [
                'status' => ChequeStatus::Draft,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            if (is_array($lines)) {
                $this->syncLines($cheque, $lines, $user);
            }
            ActivityLogService::status($cheque, null, 'draft', null, $user);
            ActivityLogService::log('cheque.created', 'cheques', "Created cheque draft {$cheque->cheque_number}", $cheque, user: $user);

            return $cheque;
        });
    }

    public function update(Cheque $cheque, array $data, User $user): Cheque
    {
        return DB::transaction(function () use ($cheque, $data, $user) {
            $cheque = Cheque::lockForUpdate()->findOrFail($cheque->id);
            $lines = $data['lines'] ?? null;

            if ($cheque->isLocked()) {
                // After Prepared only descriptive fields may change; number/payee/amount/date are frozen.
                $data = array_intersect_key($data, array_flip(['purpose', 'reference_no', 'remarks']));
                if (in_array($cheque->status, [ChequeStatus::Cancelled, ChequeStatus::Voided], true)) {
                    throw ValidationException::withMessages(['status' => 'Cancelled or voided cheques cannot be edited.']);
                }
            } else {
                $data = $this->normalise($data);
                $this->assertNoDuplicate($data['bank_account_id'], $data['cheque_number'], $cheque->id);
            }

            $cheque->fill($data + ['updated_by' => $user->id])->save();
            [$old, $new] = ActivityLogService::diff($cheque);
            // Breakdown rows follow the same lock as the amount: editable only while Draft.
            if (! $cheque->isLocked() && is_array($lines)) {
                $this->syncLines($cheque, $lines, $user);
            }
            if ($new) {
                ActivityLogService::log('cheque.updated', 'cheques', "Updated cheque {$cheque->cheque_number}", $cheque, $old, $new, user: $user);
            }

            return $cheque;
        });
    }

    /** Problems that block preparation. Keyed by field. */
    public function preparationErrors(Cheque $c): array
    {
        $e = [];
        if (! $c->payee_id || ! $c->payee) {
            $e['payee_id'] = 'Payee is required.';
        }
        if (Money::parse($c->amount) === null || (float) $c->amount <= 0) {
            $e['amount'] = 'Amount must be greater than zero.';
        }
        if (! $c->cheque_date) {
            $e['cheque_date'] = 'A valid cheque date is required.';
        }
        $account = $c->bankAccount;
        if (! $account || ! $account->is_active || ! $account->bank?->is_active) {
            $e['bank_account_id'] = 'An active bank account is required.';
        }
        if (! $e && $c->amount_words !== AmountInWords::convert((string) $c->amount)) {
            $e['amount'] = 'Amount in words does not match the amount.';
        }
        if (Cheque::where('bank_account_id', $c->bank_account_id)->where('cheque_number', $c->cheque_number)
            ->where('id', '!=', $c->id)->exists()) {
            $e['cheque_number'] = 'This cheque number already exists for the selected bank account.';
        }

        return $e;
    }

    public function prepare(Cheque $cheque, User $user): Cheque
    {
        return DB::transaction(function () use ($cheque, $user) {
            $cheque = Cheque::lockForUpdate()->findOrFail($cheque->id);
            $this->assertMove($cheque, 'prepared');

            // Recompute words from the stored decimal; never trust a typed value.
            $cheque->amount_words = AmountInWords::convert((string) $cheque->amount);
            if ($errors = $this->preparationErrors($cheque->load('bankAccount.bank', 'payee'))) {
                throw ValidationException::withMessages($errors);
            }

            $moved = $this->move($cheque, ChequeStatus::Prepared, $user, null, [
                'prepared_at' => now(),
            ], 'cheque.prepared', "Prepared cheque {$cheque->cheque_number}");

            // Warn-only rule: a breakdown that does not add up is allowed, but leaves a trace.
            $cheque->load('lines');
            if ($cheque->lines->isNotEmpty() && $cheque->linesTotal() !== Money::parse((string) $cheque->amount)) {
                ActivityLogService::log('cheque.breakdown_mismatch', 'cheques',
                    "Cheque {$cheque->cheque_number} prepared with a breakdown total of {$cheque->linesTotal()} against amount {$cheque->amount}",
                    $cheque, user: $user);
            }

            return $moved;
        });
    }

    /** Called by the printing service when a print job is initiated. */
    public function markPrinted(Cheque $cheque, User $user): Cheque
    {
        $cheque->print_count = $cheque->print_count + 1;
        if ($cheque->status === ChequeStatus::Prepared) {
            return $this->move($cheque, ChequeStatus::Printed, $user, null, ['printed_at' => now(), 'print_count' => $cheque->print_count],
                'cheque.printed', "Printed cheque {$cheque->cheque_number}");
        }
        $cheque->save();

        return $cheque;
    }

    public function release(Cheque $cheque, array $data, User $user): Cheque
    {
        return DB::transaction(function () use ($cheque, $data, $user) {
            $cheque = Cheque::lockForUpdate()->findOrFail($cheque->id);
            $this->assertMove($cheque, 'released');
            if ($cheque->release()->exists()) {
                throw ValidationException::withMessages(['released_at' => 'This cheque already has a release record.']);
            }
            $cheque->release()->create([
                'released_at' => $data['released_at'],
                'released_to' => $data['released_to'],
                'released_by' => $data['released_by'],
                'remarks' => $data['remarks'] ?? null,
                'supporting_reference' => $data['supporting_reference'] ?? null,
                'recorded_by' => $user->id,
            ]);

            return $this->move($cheque, ChequeStatus::Released, $user, null, [], 'cheque.released',
                "Recorded release of cheque {$cheque->cheque_number} to {$data['released_to']}",
                new: ['released_to' => $data['released_to'], 'released_at' => $data['released_at']]);
        });
    }

    public function markForClearing(Cheque $cheque, User $user): Cheque
    {
        return DB::transaction(function () use ($cheque, $user) {
            $cheque = Cheque::lockForUpdate()->findOrFail($cheque->id);
            $this->assertMove($cheque, 'for_clearing');

            return $this->move($cheque, ChequeStatus::ForClearing, $user, null, [], 'cheque.for_clearing',
                "Marked cheque {$cheque->cheque_number} as awaiting clearing");
        });
    }

    /** Manually recorded clearing result (never verified with a bank). */
    public function recordClearing(Cheque $cheque, array $data, User $user): Cheque
    {
        return DB::transaction(function () use ($cheque, $data, $user) {
            $cheque = Cheque::lockForUpdate()->findOrFail($cheque->id);
            $to = $data['result'] === 'bounced' ? 'bounced' : 'cleared';
            $this->assertMove($cheque, $to);
            $cheque->clearing()->create([
                'result' => $to,
                'cleared_at' => $data['cleared_at'],
                'remarks' => $data['remarks'] ?? null,
                'document_id' => $data['document_id'] ?? null,
                'recorded_by' => $user->id,
            ]);

            return $this->move($cheque, ChequeStatus::from($to), $user, $data['remarks'] ?? null, [],
                'cheque.'.$to, "Recorded cheque {$cheque->cheque_number} as {$to} (manual entry)",
                new: ['result' => $to, 'cleared_at' => $data['cleared_at']]);
        });
    }

    public function markStale(Cheque $cheque, User $user): Cheque
    {
        return DB::transaction(function () use ($cheque, $user) {
            $cheque = Cheque::lockForUpdate()->findOrFail($cheque->id);
            $this->assertMove($cheque, 'stale');

            return $this->move($cheque, ChequeStatus::Stale, $user, 'Stale period reached', ['stale_flagged_at' => null],
                'cheque.stale', "Marked cheque {$cheque->cheque_number} as stale");
        });
    }

    /** Cancel (never printed) or void (paper exists). A reason is mandatory; released voids need a stop-payment note. */
    public function stop(Cheque $cheque, string $kind, string $reason, ?string $stopNote, User $user): Cheque
    {
        return DB::transaction(function () use ($cheque, $kind, $reason, $stopNote, $user) {
            $cheque = Cheque::lockForUpdate()->findOrFail($cheque->id);
            if (! in_array($cheque->status, ChequeStatus::stoppable(), true)) {
                throw ValidationException::withMessages(['status' => "A {$cheque->status->label()} cheque can no longer be cancelled or voided."]);
            }
            if (trim($reason) === '') {
                throw ValidationException::withMessages(['reason' => 'A reason is required.']);
            }
            // Cancel = never printed (no paper exists); void = paper exists.
            $neverPrinted = in_array($cheque->status, [ChequeStatus::Draft, ChequeStatus::Prepared], true);
            if ($kind === 'cancel' && ! $neverPrinted) {
                throw ValidationException::withMessages(['kind' => 'A printed or released cheque has paper in existence and must be voided, not cancelled.']);
            }
            if ($kind === 'void' && $neverPrinted) {
                throw ValidationException::withMessages(['kind' => 'A cheque that was never printed should be cancelled, not voided.']);
            }
            if ($kind === 'void' && $cheque->status === ChequeStatus::Released && trim((string) $stopNote) === '') {
                throw ValidationException::withMessages(['stop_payment_note' => 'A stop-payment note is required when voiding a released cheque.']);
            }
            $to = $kind === 'void' ? ChequeStatus::Voided : ChequeStatus::Cancelled;

            $moved = $this->move($cheque, $to, $user, $reason, ['stop_payment_note' => $stopNote ?: null],
                "cheque.{$to->value}", ucfirst($to->value)." cheque {$cheque->cheque_number}");
            // Receipts on a cancelled/voided cheque are free to be paid by another cheque.
            Receipt::where('cheque_id', $cheque->id)->update(['cheque_id' => null]);

            return $moved;
        });
    }

    /** Flag cheques that passed the stale period (a user confirms the actual status change). */
    public function flagStale(): int
    {
        $cutoff = now()->subDays((int) \App\Support\Settings::get('stale_days', 180))->toDateString();

        return Cheque::whereIn('status', ['released', 'for_clearing'])
            ->whereNull('stale_flagged_at')
            ->whereHas('release', fn ($q) => $q->where('released_at', '<=', $cutoff))
            ->update(['stale_flagged_at' => now()]);
    }

    /** Attributes for a new draft based on an existing cheque (number left for the user). */
    public function duplicateAttributes(Cheque $c): array
    {
        return [
            'bank_account_id' => $c->bank_account_id,
            'payee_id' => $c->payee_id,
            'payee_line' => $c->payee_line,
            'is_cash' => $c->is_cash,
            'amount' => (string) $c->amount,
            'purpose' => $c->purpose,
            'reference_no' => $c->reference_no,
            'remarks' => $c->remarks,
            // Typed rows are copied; receipt links are not (those receipts are already paid).
            'lines' => $c->lines->map(fn ($l) => [
                'line_date' => $l->line_date?->toDateString(), 'reference_no' => $l->reference_no, 'vendor' => $l->vendor,
                'particulars' => $l->particulars, 'amount' => (string) $l->amount, 'receipt_id' => null,
            ])->values()->all(),
        ];
    }

    /**
     * Replace the breakdown rows of a Draft cheque. Rows with a receipt link that receipt to the cheque.
     * A receipt may be on only one active (not cancelled/voided) cheque.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function syncLines(Cheque $cheque, array $rows, User $user): void
    {
        $clean = [];
        foreach (array_values($rows) as $i => $r) {
            $blank = trim((string) ($r['amount'] ?? '')) === '' && trim((string) ($r['reference_no'] ?? '')) === ''
                && trim((string) ($r['particulars'] ?? '')) === '' && trim((string) ($r['vendor'] ?? '')) === '' && empty($r['receipt_id']);
            if ($blank) {
                continue;
            }
            $amount = Money::parse($r['amount'] ?? null);
            if ($amount === null || (float) $amount <= 0) {
                throw ValidationException::withMessages(["lines.{$i}.amount" => 'Enter an amount greater than zero for this row.']);
            }
            $clean[] = [
                'line_date' => ($r['line_date'] ?? null) ?: null,
                'reference_no' => $this->nullIfBlank($r['reference_no'] ?? null),
                'vendor' => $this->nullIfBlank($r['vendor'] ?? null),
                'particulars' => $this->nullIfBlank($r['particulars'] ?? null),
                'amount' => $amount,
                'receipt_id' => ! empty($r['receipt_id']) ? (int) $r['receipt_id'] : null,
            ];
        }

        $receiptIds = array_values(array_filter(array_column($clean, 'receipt_id')));
        if (count($receiptIds) !== count(array_unique($receiptIds))) {
            throw ValidationException::withMessages(['lines' => 'The same receipt is listed twice.']);
        }
        if ($receiptIds) {
            $taken = ChequeLine::whereIn('receipt_id', $receiptIds)->where('cheque_id', '!=', $cheque->id)
                ->whereHas('cheque', fn ($q) => $q->whereNotIn('status', ['cancelled', 'voided']))
                ->with('cheque:id,cheque_number', 'receipt:id,receipt_number')->first();
            if ($taken) {
                throw ValidationException::withMessages(['lines' => 'Receipt '.($taken->receipt?->receipt_number ?: '#'.$taken->receipt_id)." is already on cheque {$taken->cheque->cheque_number}."]);
            }
        }

        $before = $cheque->lines()->get();
        $oldTotal = Money::sum($before->pluck('amount'));
        $oldReceipts = $before->pluck('receipt_id')->filter()->all();

        $cheque->lines()->delete();
        foreach ($clean as $n => $row) {
            $cheque->lines()->create($row + ['line_no' => $n + 1]);
        }
        $cheque->unsetRelation('lines');

        // Keep receipts.cheque_id in step with the rows.
        $removed = array_diff($oldReceipts, $receiptIds);
        if ($removed) {
            Receipt::whereIn('id', $removed)->where('cheque_id', $cheque->id)->update(['cheque_id' => null]);
        }
        if ($receiptIds) {
            Receipt::whereIn('id', $receiptIds)->update(['cheque_id' => $cheque->id]);
        }

        $newTotal = Money::sum(array_column($clean, 'amount'));
        if (count($before) !== count($clean) || $oldTotal !== $newTotal
            || $before->pluck('reference_no')->all() !== array_column($clean, 'reference_no')) {
            ActivityLogService::log('cheque.breakdown_updated', 'cheques', "Updated breakdown of cheque {$cheque->cheque_number} (".count($clean).' row(s))',
                $cheque, ['rows' => count($before), 'total' => $oldTotal], ['rows' => count($clean), 'total' => $newTotal], user: $user);
        }
    }

    private function nullIfBlank(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    // ---- internals -------------------------------------------------------------------

    private function normalise(array $d): array
    {
        $amount = Money::parse($d['amount'] ?? null);
        if ($amount === null) {
            throw ValidationException::withMessages(['amount' => 'Enter a valid amount (up to 2 decimals).']);
        }
        $d['amount'] = $amount;
        $d['amount_words'] = (float) $amount > 0 ? AmountInWords::convert($amount) : null;
        $d['cheque_number'] = trim($d['cheque_number']);
        $d['is_cash'] = (bool) ($d['is_cash'] ?? false);
        $d['payee_line'] = ($d['payee_line'] ?? null) ?: null;

        return array_intersect_key($d, array_flip([
            'bank_account_id', 'cheque_number', 'cheque_date', 'payee_id', 'payee_line', 'is_cash', 'amount',
            'amount_words', 'purpose', 'reference_no', 'remarks',
        ]));
    }

    private function assertNoDuplicate(int $accountId, string $number, ?int $ignore = null): void
    {
        $existing = Cheque::where('bank_account_id', $accountId)->where('cheque_number', trim($number))
            ->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'cheque_number' => "Cheque number {$number} already exists for this bank account (record #{$existing->id}).",
            ]);
        }
    }

    private function assertMove(Cheque $c, string $to): void
    {
        $allowed = self::FLOW[$c->status->value] ?? [];
        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "A {$c->status->label()} cheque cannot move to ".ChequeStatus::from($to)->label().'.',
            ]);
        }
    }

    private function move(Cheque $c, ChequeStatus $to, User $user, ?string $reason, array $extra, string $action, string $desc, ?array $new = null): Cheque
    {
        $from = $c->status;
        $c->forceFill($extra + ['status' => $to, 'updated_by' => $user->id])->save();
        ActivityLogService::status($c, $from->value, $to->value, $reason, $user);
        ActivityLogService::log($action, 'cheques', $desc, $c, ['status' => $from->value], ($new ?? []) + ['status' => $to->value], $reason, $user);

        return $c;
    }
}
