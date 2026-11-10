<?php

namespace App\Services;

use App\Enums\ChequeStatus;
use App\Enums\ReceiptStatus;
use App\Models\Cheque;
use App\Models\PrintJob;
use App\Models\PrintLog;
use App\Models\PrintTemplate;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrintingService
{
    public function __construct(private ChequeService $cheques) {}

    public function modelFor(string $category): string
    {
        return $category === 'cheque' ? Cheque::class : Receipt::class;
    }

    public function load(string $category, array $ids): Collection
    {
        $q = $category === 'cheque'
            ? Cheque::with('bankAccount.bank', 'bankAccount.template', 'payee', 'creator')
            : Receipt::with('payee', 'category', 'creator');

        return $q->whereIn('id', $ids)->get();
    }

    public function templateFor(Model $record): ?PrintTemplate
    {
        if ($record instanceof Cheque) {
            return $record->bankAccount?->template;
        }

        return PrintTemplate::where('kind', 'receipt')->where('is_active', true)->orderBy('id')->first();
    }

    /**
     * Eligibility per record. Returns [passes => Collection, failures => [id => message]].
     * A batch is only allowed when every record passes.
     */
    public function eligibility(string $category, Collection $records, bool $reprint, User $user): array
    {
        $failures = [];
        foreach ($records as $r) {
            $label = $r instanceof Cheque ? "Cheque {$r->cheque_number}" : 'Receipt '.($r->receipt_number ?: '#'.$r->id);
            if ($r instanceof Cheque) {
                $okStatuses = $reprint
                    ? [ChequeStatus::Printed, ChequeStatus::Released, ChequeStatus::ForClearing]
                    : [ChequeStatus::Prepared];
                if (! in_array($r->status, $okStatuses, true)) {
                    $failures[$r->id] = "{$label} is {$r->status->label()}".($reprint ? ' and cannot be reprinted.' : '; only Prepared cheques can be printed.');
                    continue;
                }
                if (! $r->bankAccount?->template?->is_active) {
                    $failures[$r->id] = "{$label}: no active print template is assigned to its bank account.";
                    continue;
                }
            } else {
                if (in_array($r->status, [ReceiptStatus::Draft, ReceiptStatus::Cancelled], true)) {
                    $failures[$r->id] = "{$label} is {$r->status->label()} and cannot be printed.";
                    continue;
                }
                if (! $this->templateFor($r)) {
                    $failures[$r->id] = 'No active receipt template is configured.';
                    continue;
                }
            }
            if (! $user->can($r instanceof Cheque ? 'view' : 'view', $r)) {
                $failures[$r->id] = "{$label}: you do not have access.";
            }
        }

        return ['failures' => $failures, 'ok' => empty($failures)];
    }

    /** Resolved values the print renderer places onto a template. */
    public function values(Model $record, User $user): array
    {
        $prepared = $user->name;
        if ($record instanceof Cheque) {
            $acct = $record->bankAccount;

            return [
                'payee_line' => $record->printed_payee,
                'amount_number' => number_format((float) $record->amount, 2, '.', ','),
                'amount_words' => $record->amount_words,
                'date' => $record->cheque_date->toDateString(),
                'check_no' => $record->cheque_number,
                'purpose' => (string) $record->purpose,
                'reference' => (string) $record->reference_no,
                'prepared_by' => $prepared,
                'printed_on' => now()->toDateString(),
                'bank_account_name' => $acct?->account_name,
            ];
        }

        return [
            'org_name' => Settings::get('org_name'),
            'org_address' => Settings::get('org_address'),
            'receipt_no' => (string) $record->receipt_number,
            'date' => $record->receipt_date?->toDateString(),
            'vendor' => $record->payee?->name,
            'description' => (string) $record->description,
            'amount_number' => number_format((float) $record->amount, 2, '.', ','),
            'tax' => Money::format($record->tax_amount),
            'reference' => (string) $record->reference_no,
            'prepared_by' => $prepared,
            'printed_on' => now()->toDateString(),
        ];
    }

    public function templatePayload(?PrintTemplate $t): ?array
    {
        return $t ? [
            'id' => $t->id, 'name' => $t->name, 'kind' => $t->kind, 'version' => $t->version,
            'paper_width_mm' => $t->paper_width_mm, 'paper_height_mm' => $t->paper_height_mm,
            'offset_x_mm' => $t->offset_x_mm, 'offset_y_mm' => $t->offset_y_mm,
            'background_url' => $t->background_url, 'layout' => $t->layout,
        ] : null;
    }

    /**
     * Initiate a print job. Writes a log per record (status "initiated").
     * Cheques move Prepared → Printed here; reprints never change status or create transactions.
     */
    public function start(User $user, string $category, array $ids, bool $reprint, ?string $reason, array $settings = []): PrintJob
    {
        $records = $this->load($category, $ids);
        if ($records->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['ids' => 'Some selected records no longer exist.']);
        }
        $check = $this->eligibility($category, $records, $reprint, $user);
        if (! $check['ok']) {
            throw ValidationException::withMessages(['ids' => implode(' ', $check['failures'])]);
        }
        if ($reprint && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required to reprint.']);
        }

        return DB::transaction(function () use ($user, $category, $records, $reprint, $reason, $settings) {
            $job = PrintJob::create([
                'user_id' => $user->id,
                'category' => $category,
                'is_reprint' => $reprint,
                'reason' => $reprint ? $reason : null,
                'status' => 'initiated',
                'item_count' => $records->count(),
                'settings' => $settings ?: null,
            ]);
            foreach ($records as $r) {
                $tpl = $this->templateFor($r);
                $alreadyPrinted = PrintLog::where('printable_type', $r->getMorphClass())->where('printable_id', $r->id)->exists();
                PrintLog::create([
                    'print_job_id' => $job->id,
                    'printable_type' => $r->getMorphClass(),
                    'printable_id' => $r->id,
                    'template_id' => $tpl?->id,
                    'template_version' => $tpl?->version,
                    'status' => 'initiated',
                    'is_reprint' => $reprint || $alreadyPrinted,
                    'user_id' => $user->id,
                ]);
                if ($r instanceof Cheque) {
                    $this->cheques->markPrinted($r, $user);
                }
            }
            ActivityLogService::log($reprint ? 'print.reprint' : 'print.initiated', 'printing',
                ($reprint ? 'Reprint' : 'Print').' job #'.$job->id.' initiated for '.$records->count().' '.$category.'(s)',
                $job, null, ['ids' => $records->pluck('id')->all()], $reason, $user);

            return $job;
        });
    }

    /**
     * The user confirms what physically happened.
     * $failedIds: log ids (printable ids) that had a problem; empty => all good.
     */
    public function confirm(PrintJob $job, User $user, array $failedIds = [], ?string $note = null): PrintJob
    {
        if ($job->status !== 'initiated') {
            throw ValidationException::withMessages(['job' => 'This print job has already been confirmed.']);
        }
        if ($failedIds && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Describe the problem so it can be followed up.']);
        }

        return DB::transaction(function () use ($job, $user, $failedIds, $note) {
            $failed = 0;
            foreach ($job->logs as $log) {
                $isFailed = in_array((int) $log->printable_id, array_map('intval', $failedIds), true);
                $failed += $isFailed ? 1 : 0;
                $log->update(['status' => $isFailed ? 'failed' : 'confirmed', 'note' => $isFailed ? $note : null]);
            }
            $status = $failed === 0 ? 'confirmed' : ($failed === $job->logs->count() ? 'failed' : 'partial');
            $job->update(['status' => $status, 'confirmed_at' => now(), 'note' => $note]);
            ActivityLogService::log('print.'.($failed ? 'problem' : 'confirmed'), 'printing',
                "Print job #{$job->id} ".($failed ? "reported with {$failed} problem(s)" : 'confirmed printed correctly'),
                $job, null, ['status' => $status], $note, $user);

            return $job;
        });
    }
}
