<?php

namespace App\Services;

use App\Enums\ReceiptStatus;
use App\Models\ChequeLine;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiptService
{
    /** Create a draft (possibly empty — e.g. from an uploaded file) or a full recorded receipt. */
    public function create(array $data, User $user, bool $record = false): Receipt
    {
        return DB::transaction(function () use ($data, $user, $record) {
            $data = $this->normalise($data);
            $receipt = Receipt::create($data + [
                'status' => ReceiptStatus::Draft,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            ActivityLogService::status($receipt, null, 'draft', null, $user);
            ActivityLogService::log('receipt.created', 'receipts', 'Created receipt draft #'.$receipt->id, $receipt, user: $user);

            return $record ? $this->record($receipt, $user, $data['duplicate_override_reason'] ?? null) : $receipt;
        });
    }

    public function update(Receipt $receipt, array $data, User $user, bool $record = false): Receipt
    {
        return DB::transaction(function () use ($receipt, $data, $user, $record) {
            $receipt = Receipt::lockForUpdate()->findOrFail($receipt->id);
            if (! $receipt->isEditable()) {
                throw ValidationException::withMessages(['status' => "A {$receipt->status->label()} receipt can no longer be edited."]);
            }
            $data = $this->normalise($data);
            if (array_key_exists('cheque_id', $data)) {
                $this->guardChequeLink($receipt, $data['cheque_id']);
            }
            $receipt->fill($data + ['updated_by' => $user->id])->save();
            [$old, $new] = ActivityLogService::diff($receipt);
            if ($new) {
                ActivityLogService::log('receipt.updated', 'receipts', "Updated receipt {$receipt->receipt_number}", $receipt, $old, $new, user: $user);
                // Editing a verified receipt sends it back for re-verification.
                if ($receipt->status === ReceiptStatus::Verified) {
                    $this->move($receipt, ReceiptStatus::Recorded, $user, 'Edited after verification', 'receipt.reopened', "Receipt {$receipt->receipt_number} edited; verification reset");
                }
            }

            return ($record && $receipt->status === ReceiptStatus::Draft)
                ? $this->record($receipt, $user, $data['duplicate_override_reason'] ?? null)
                : $receipt;
        });
    }

    /** Fields required before a receipt can be Recorded. */
    public function recordErrors(Receipt $r): array
    {
        $e = [];
        if (! $r->receipt_number) {
            $e['receipt_number'] = 'Receipt number is required.';
        }
        if (! $r->receipt_date) {
            $e['receipt_date'] = 'A valid receipt date is required.';
        }
        if (! $r->payee_id) {
            $e['payee_id'] = 'Vendor / payee is required.';
        }
        if ($r->amount === null || (float) $r->amount <= 0) {
            $e['amount'] = 'Amount must be greater than zero.';
        }

        return $e;
    }

    /** @return array{exact: ?Receipt, similar: Collection} */
    public function duplicates(?string $number, ?int $payeeId, ?int $ignoreId = null): array
    {
        if (! $number) {
            return ['exact' => null, 'similar' => collect()];
        }
        $same = Receipt::with('payee:id,name')->where('receipt_number', trim($number))
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('status', '!=', ReceiptStatus::Cancelled->value)->get();

        return [
            'exact' => $same->firstWhere('payee_id', $payeeId),
            'similar' => $same->where('payee_id', '!=', $payeeId)->values(),
        ];
    }

    public function record(Receipt $r, User $user, ?string $overrideReason = null): Receipt
    {
        if ($r->status !== ReceiptStatus::Draft) {
            return $r;
        }
        if ($errors = $this->recordErrors($r)) {
            throw ValidationException::withMessages($errors);
        }
        $dup = $this->duplicates($r->receipt_number, $r->payee_id, $r->id)['exact'];
        if ($dup) {
            if (! $overrideReason || ! $user->hasPermission('receipts.override_duplicate')) {
                throw ValidationException::withMessages([
                    'receipt_number' => "Receipt {$r->receipt_number} from this vendor already exists (record #{$dup->id}).".
                        ($user->hasPermission('receipts.override_duplicate') ? ' Provide an override reason to save anyway.' : ''),
                ]);
            }
            $r->forceFill(['duplicate_override_reason' => $overrideReason])->save();
        }

        return $this->move($r, ReceiptStatus::Recorded, $user, null, 'receipt.recorded', "Recorded receipt {$r->receipt_number}");
    }

    public function verify(Receipt $r, User $user): Receipt
    {
        return $this->step($r, ReceiptStatus::Recorded, ReceiptStatus::Verified, $user, 'receipt.verified', 'Verified receipt');
    }

    public function process(Receipt $r, User $user): Receipt
    {
        return $this->step($r, ReceiptStatus::Verified, ReceiptStatus::Processed, $user, 'receipt.processed', 'Processed receipt');
    }

    public function archive(Receipt $r, User $user): Receipt
    {
        return $this->step($r, ReceiptStatus::Processed, ReceiptStatus::Archived, $user, 'receipt.archived', 'Archived receipt');
    }

    public function cancel(Receipt $r, string $reason, User $user): Receipt
    {
        return DB::transaction(function () use ($r, $reason, $user) {
            $r = Receipt::lockForUpdate()->findOrFail($r->id);
            if (in_array($r->status, [ReceiptStatus::Archived, ReceiptStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['status' => "A {$r->status->label()} receipt cannot be cancelled."]);
            }
            if (trim($reason) === '') {
                throw ValidationException::withMessages(['reason' => 'A reason is required.']);
            }

            return $this->move($r, ReceiptStatus::Cancelled, $user, $reason, 'receipt.cancelled', "Cancelled receipt {$r->receipt_number}");
        });
    }

    private function step(Receipt $r, ReceiptStatus $from, ReceiptStatus $to, User $user, string $action, string $desc): Receipt
    {
        return DB::transaction(function () use ($r, $from, $to, $user, $action, $desc) {
            $r = Receipt::lockForUpdate()->findOrFail($r->id);
            if ($r->status !== $from) {
                throw ValidationException::withMessages(['status' => "Only a {$from->label()} receipt can move to {$to->label()}."]);
            }

            return $this->move($r, $to, $user, null, $action, "{$desc} {$r->receipt_number}");
        });
    }

    private function move(Receipt $r, ReceiptStatus $to, User $user, ?string $reason, string $action, string $desc): Receipt
    {
        $from = $r->status;
        $r->forceFill(['status' => $to, 'updated_by' => $user->id])->save();
        ActivityLogService::status($r, $from->value, $to->value, $reason, $user);
        ActivityLogService::log($action, 'receipts', $desc, $r, ['status' => $from->value], ['status' => $to->value], $reason, $user);

        return $r;
    }

    /**
     * One receipt, one active cheque. If the receipt is on an active cheque's breakdown, that breakdown owns the link;
     * otherwise it may only be linked to a cheque that has not already got it on another active breakdown.
     */
    private function guardChequeLink(Receipt $r, mixed $chequeId): void
    {
        $chequeId = $chequeId ? (int) $chequeId : null;
        $line = ChequeLine::where('receipt_id', $r->id)
            ->whereHas('cheque', fn ($q) => $q->whereNotIn('status', ['cancelled', 'voided']))
            ->with('cheque:id,cheque_number')->first();
        if ($line && $chequeId !== (int) $line->cheque_id) {
            throw ValidationException::withMessages(['cheque_id' => "This receipt is on the breakdown of cheque {$line->cheque->cheque_number}. Remove it from that breakdown first."]);
        }
    }

    private function normalise(array $d): array
    {
        foreach (['amount', 'tax_amount'] as $f) {
            if (array_key_exists($f, $d) && $d[$f] !== null && $d[$f] !== '') {
                $m = Money::parse($d[$f]);
                if ($m === null) {
                    throw ValidationException::withMessages([$f => 'Enter a valid amount (up to 2 decimals).']);
                }
                $d[$f] = $m;
            } else {
                $d[$f] = $f === 'tax_amount' ? '0.00' : null;
            }
        }
        $d['receipt_number'] = isset($d['receipt_number']) ? (trim($d['receipt_number']) ?: null) : null;

        return array_intersect_key($d, array_flip([
            'receipt_number', 'receipt_date', 'payee_id', 'description', 'amount', 'tax_amount', 'tax_rate',
            'category_id', 'cheque_id', 'reference_no', 'remarks', 'duplicate_override_reason',
        ]));
    }
}
