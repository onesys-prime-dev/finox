<?php

namespace App\Services;

use App\Enums\ChequeStatus;
use App\Models\BankAccount;
use App\Models\Cheque;
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
            $data = $this->normalise($data);
            $this->assertNoDuplicate($data['bank_account_id'], $data['cheque_number']);

            $cheque = Cheque::create($data + [
                'status' => ChequeStatus::Draft,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            ActivityLogService::status($cheque, null, 'draft', null, $user);
            ActivityLogService::log('cheque.created', 'cheques', "Created cheque draft {$cheque->cheque_number}", $cheque, user: $user);

            return $cheque;
        });
    }

    public function update(Cheque $cheque, array $data, User $user): Cheque
    {
        return DB::transaction(function () use ($cheque, $data, $user) {
            $cheque = Cheque::lockForUpdate()->findOrFail($cheque->id);

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

            return $this->move($cheque, ChequeStatus::Prepared, $user, null, [
                'prepared_at' => now(),
            ], 'cheque.prepared', "Prepared cheque {$cheque->cheque_number}");
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
            if ($kind === 'void' && $cheque->status === ChequeStatus::Released && trim((string) $stopNote) === '') {
                throw ValidationException::withMessages(['stop_payment_note' => 'A stop-payment note is required when voiding a released cheque.']);
            }
            $to = $kind === 'void' ? ChequeStatus::Voided : ChequeStatus::Cancelled;

            return $this->move($cheque, $to, $user, $reason, ['stop_payment_note' => $stopNote ?: null],
                "cheque.{$to->value}", ucfirst($to->value)." cheque {$cheque->cheque_number}");
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
        ];
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
