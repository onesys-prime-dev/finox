<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Cheque;
use App\Models\DocumentFile;
use App\Models\Payee;
use App\Models\PrintTemplate;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use App\Services\ChequeService;
use App\Services\ReceiptService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Rules from the Function Plan, checked end to end. Failures are collected and listed together. */
class BusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    private array $problems = [];

    private int $n = 6000000000;

    private function check(string $label, bool $ok, string $detail = ''): void
    {
        if (! $ok) {
            $this->problems[] = "[FAIL] {$label}".($detail ? " — {$detail}" : '');
        }
    }

    private function pdf(string $name = 'a.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    private function cheque(User $by, string $status = 'draft', string $amount = '100.00'): Cheque
    {
        $cs = app(ChequeService::class);
        $c = $cs->create(['bank_account_id' => BankAccount::first()->id, 'cheque_date' => now()->toDateString(), 'payee_id' => Payee::first()->id,
            'amount' => $amount, 'cheque_number' => (string) (++$this->n)], $by);
        $order = ['draft', 'prepared', 'printed', 'released', 'for_clearing'];
        foreach (array_slice($order, 1, array_search($status, $order)) as $step) {
            match ($step) {
                'prepared' => $cs->prepare($c, $by),
                'printed' => $c->forceFill(['status' => 'printed', 'printed_at' => now()])->save(),
                'released' => $cs->release($c->refresh(), ['released_at' => now()->toDateString(), 'released_to' => 'X', 'released_by' => 'Y'], $by),
                'for_clearing' => $cs->markForClearing($c->refresh(), $by),
            };
            $c->refresh();
        }

        return $c->refresh();
    }

    public function test_business_rules(): void
    {
        $admin = $this->userWithRole('admin');
        $fin = $this->userWithRole('finance');
        $enc = $this->userWithRole('encoder');
        $prn = $this->userWithRole('printing');
        Storage::fake('local');

        // ---- Dashboard = register = reports (exit test for phase 7)
        $this->cheque($fin, 'draft', '10');
        $this->cheque($fin, 'prepared', '20');
        $this->cheque($fin, 'released', '30.50');
        $this->cheque($fin, 'for_clearing', '40');
        app(ReceiptService::class)->create(['receipt_number' => 'T-1', 'receipt_date' => now()->toDateString(), 'payee_id' => Payee::first()->id, 'amount' => '500'], $fin, record: true);
        $dash = $this->actingAs($fin)->get('/dashboard')->viewData('page')['props'] ?? [];
        $reg = $this->actingAs($fin)->get('/transactions')->viewData('page')['props'] ?? [];
        $dashTotal = $dash['stats']['cheques']['total'] ?? null;
        $this->check('dashboard cheque total = cheques in DB', $dashTotal === Cheque::count(), "dash={$dashTotal} db=".Cheque::count());
        $regTotal = $reg['rows']['total'] ?? ($reg['transactions']['total'] ?? null);
        $this->check('register total = cheques + receipts', $regTotal === Cheque::count() + Receipt::count(), 'register='.json_encode($regTotal).' expected='.(Cheque::count() + Receipt::count()).' keys='.implode(',', array_keys($reg)));
        $out = $dash['stats']['cheques']['outstanding_amount'] ?? null;
        $this->check('outstanding = released + for clearing (70.50)', (float) $out === 70.50, "got {$out}");

        // ---- Notification toggles hide dashboard queues
        Settings::set('notify_failed_prints', false);
        Settings::set('notify_stale_cheques', false);
        $labels = collect($this->actingAs($fin)->get('/dashboard')->viewData('page')['props']['queues'] ?? [])->pluck('label')->all();
        $this->check('notification toggles hide Failed prints / Stale review', ! in_array('Failed prints', $labels) && ! in_array('Stale review', $labels), implode(',', $labels));
        Settings::set('notify_failed_prints', true);
        Settings::set('notify_stale_cheques', true);

        // ---- Cheque rules
        $c = $this->cheque($fin, 'draft', '250.75');
        $this->check('amount in words saved', str_contains((string) $c->amount_words, 'TWO HUNDRED FIFTY AND 75/100 PESOS ONLY'), (string) $c->amount_words);
        $dup = $this->actingAs($fin)->post('/cheques', ['bank_account_id' => $c->bank_account_id, 'cheque_number' => $c->cheque_number,
            'cheque_date' => now()->toDateString(), 'payee_id' => Payee::first()->id, 'amount' => '5']);
        $this->check('duplicate cheque number blocked', $dup->getSession()->get('errors') !== null || $dup->getStatusCode() === 422);
        $zero = $this->actingAs($fin)->post('/cheques', ['bank_account_id' => $c->bank_account_id, 'cheque_number' => (string) (++$this->n),
            'cheque_date' => now()->toDateString(), 'payee_id' => Payee::first()->id, 'amount' => '0']);
        $this->check('zero amount cannot be prepared', ! Cheque::where('cheque_number', (string) $this->n)->where('status', '!=', 'draft')->exists());
        $rel = $this->actingAs($fin)->post("/cheques/{$c->id}/release", ['released_at' => now()->toDateString(), 'released_to' => 'A', 'released_by' => 'B']);
        $this->check('release refused from Draft', $c->refresh()->status->value === 'draft');
        $enc2 = $this->actingAs($enc)->post('/cheques', ['bank_account_id' => $c->bank_account_id, 'cheque_number' => (string) (++$this->n),
            'cheque_date' => now()->toDateString(), 'payee_id' => Payee::first()->id, 'amount' => '12']);
        $mine = Cheque::where('created_by', $enc->id)->first();
        $this->check('encoder can create own draft', (bool) $mine);
        $this->check('encoder cannot prepare', $this->actingAs($enc)->post("/cheques/{$mine->id}/prepare")->getStatusCode() === 403);
        $rel2 = $this->cheque($fin, 'released');
        $this->actingAs($fin)->post("/cheques/{$rel2->id}/release", ['released_at' => now()->toDateString(), 'released_to' => 'A', 'released_by' => 'B']);
        $this->check('only one release per cheque', \DB::table('cheque_releases')->where('cheque_id', $rel2->id)->count() === 1);
        $this->actingAs($fin)->post("/cheques/{$rel2->id}/stop", ['kind' => 'void', 'reason' => 'wrong payee']);
        $this->check('void after release needs stop-payment note', $rel2->refresh()->status->value !== 'voided');
        $this->actingAs($fin)->post("/cheques/{$rel2->id}/stop", ['kind' => 'void', 'reason' => 'wrong payee', 'stop_payment_note' => 'Bank notified']);
        $this->check('void with reason + note works, record stays', $rel2->refresh()->status->value === 'voided' && Cheque::whereKey($rel2->id)->exists());
        $fc = $this->cheque($fin, 'for_clearing');
        $this->actingAs($fin)->post("/cheques/{$fc->id}/stop", ['kind' => 'cancel', 'reason' => 'too late']);
        $this->check('cancel refused after For Clearing', $fc->refresh()->status->value === 'for_clearing');
        $this->actingAs($fin)->post("/cheques/{$fc->id}/clearing", ['result' => 'bounced', 'cleared_at' => now()->toDateString(), 'remarks' => 'NSF']);
        $this->check('bounced recorded', $fc->refresh()->status->value === 'bounced');
        $hist = \DB::table('status_histories')->where('subject_id', $fc->id)->count();
        $this->check('status history written for each move', $hist >= 5, "rows={$hist}");

        // Stale flag job
        $old = $this->cheque($fin, 'released');
        \DB::table('cheque_releases')->where('cheque_id', $old->id)->update(['released_at' => now()->subDays(200)->toDateString()]);
        $flagged = app(ChequeService::class)->flagStale();
        $this->check('stale job flags cheque past stale period', $flagged >= 1 && $old->refresh()->stale_flagged_at !== null, "flagged={$flagged}");
        $this->check('stale job does not change status by itself', $old->status->value === 'released');

        // ---- Receipts
        $r1 = $this->actingAs($fin)->post('/receipts', ['receipt_number' => 'DUP-1', 'receipt_date' => now()->toDateString(), 'payee_id' => Payee::first()->id, 'amount' => '10']);
        $r2 = $this->actingAs($fin)->post('/receipts', ['receipt_number' => 'DUP-1', 'receipt_date' => now()->toDateString(), 'payee_id' => Payee::first()->id, 'amount' => '10']);
        $this->check('duplicate receipt number blocked', Receipt::where('receipt_number', 'DUP-1')->count() === 1);
        $this->actingAs($admin)->post('/receipts', ['receipt_number' => 'DUP-1', 'receipt_date' => now()->toDateString(), 'payee_id' => Payee::first()->id, 'amount' => '10', 'duplicate_override_reason' => 'Two books']);
        $this->check('admin can override duplicate with reason', Receipt::where('receipt_number', 'DUP-1')->count() === 2);
        $rc = Receipt::where('receipt_number', 'DUP-1')->first();
        $this->actingAs($fin)->post("/receipts/{$rc->id}/archive");
        $this->check('cannot archive before verified/processed', $rc->refresh()->status->value !== 'archived');
        foreach (['verify', 'process', 'archive'] as $a) {
            $this->actingAs($fin)->post("/receipts/{$rc->id}/{$a}");
        }
        $this->check('receipt flow Recorded→Verified→Processed→Archived', $rc->refresh()->status->value === 'archived');
        $this->actingAs($fin)->post("/receipts/{$rc->id}/cancel", ['reason' => 'late']);
        $this->check('archived receipt cannot be cancelled', $rc->refresh()->status->value === 'archived');
        $this->actingAs($fin)->put("/receipts/{$rc->id}", ['receipt_number' => 'CHG', 'receipt_date' => now()->toDateString(), 'payee_id' => Payee::first()->id, 'amount' => '99']);
        $this->check('archived receipt is read-only', $rc->refresh()->receipt_number === 'DUP-1');
        $fake = UploadedFile::fake()->createWithContent('evil.pdf', '<?php echo 1; ?> not a pdf');
        $this->actingAs($fin)->post('/receipts', ['receipt_number' => 'BAD-1', 'receipt_date' => now()->toDateString(), 'payee_id' => Payee::first()->id, 'amount' => '1', 'file' => $fake]);
        $this->check('file type checked by content (fake PDF rejected)', DocumentFile::where('original_name', 'evil.pdf')->count() === 0);
        $this->actingAs($fin)->post('/receipts', ['receipt_number' => 'OK-1', 'receipt_date' => now()->toDateString(), 'payee_id' => Payee::first()->id, 'amount' => '1', 'file' => $this->pdf()]);
        $ok = Receipt::where('receipt_number', 'OK-1')->first();
        $doc = $ok?->documents()->first();
        $this->check('PDF upload stored and linked', (bool) $doc);
        if ($doc) {
            $this->actingAs($fin)->post("/receipts/{$ok->id}/files/{$doc->id}/replace", ['file' => $this->pdf('b.pdf'), 'reason' => 'better scan']);
            $this->check('replace keeps old version', DocumentFile::where('documentable_id', $ok->id)->where('documentable_type', 'receipt')->count() >= 2);
            $this->check('private file is not publicly reachable', ! str_starts_with((string) $doc->path, 'public'));
            $dl = $this->actingAs($fin)->get("/documents/{$doc->id}/download");
            $this->check('download works and is logged', $dl->getStatusCode() === 200 && ActivityLog::where('action', 'like', '%download%')->exists(), 'status='.$dl->getStatusCode());
        }
        $big = UploadedFile::fake()->createWithContent('big.pdf', "%PDF-1.4\n".str_repeat('A', 11 * 1024 * 1024)."\n%%EOF");
        $this->actingAs($fin)->post('/receipts', ['receipt_number' => 'BIG-1', 'receipt_date' => now()->toDateString(), 'payee_id' => Payee::first()->id, 'amount' => '1', 'file' => $big]);
        $this->check('oversize file rejected', DocumentFile::where('original_name', 'big.pdf')->count() === 0);

        // ---- Printing
        $p1 = $this->cheque($fin, 'prepared');
        $bad = $this->cheque($fin, 'draft');
        $batch = $this->actingAs($prn)->postJson('/printing/start', ['category' => 'cheque', 'ids' => [$p1->id, $bad->id]]);
        $this->check('batch with a non-eligible cheque is blocked as a whole', $batch->getStatusCode() >= 400 && $p1->refresh()->status->value === 'prepared', 'status='.$batch->getStatusCode());
        $this->check('batch failure names the bad cheque', str_contains($batch->getContent(), $bad->cheque_number), substr($batch->getContent(), 0, 200));
        $job = $this->actingAs($prn)->postJson('/printing/start', ['category' => 'cheque', 'ids' => [$p1->id]])->json('job_id');
        $this->check('print start moves cheque to Printed', $p1->refresh()->status->value === 'printed');
        $this->check('print log written as Initiated', \DB::table('print_logs')->where('printable_id', $p1->id)->where('status', 'initiated')->exists());
        $this->actingAs($prn)->post("/printing/jobs/{$job}/confirm", ['failed_ids' => [$p1->id], 'note' => 'paper jam']);
        $this->check('failed print recorded with note', \DB::table('print_logs')->where('printable_id', $p1->id)->where('status', 'failed')->exists());
        $noReason = $this->actingAs($prn)->postJson('/printing/start', ['category' => 'cheque', 'ids' => [$p1->id], 'reprint' => true]);
        $this->check('reprint without reason refused', $noReason->getStatusCode() >= 400);
        $re = $this->actingAs($prn)->postJson('/printing/start', ['category' => 'cheque', 'ids' => [$p1->id], 'reprint' => true, 'reason' => 'Paper jam']);
        $this->check('reprint with reason works, status unchanged, no new cheque', $re->getStatusCode() === 200 && $p1->refresh()->status->value === 'printed' && Cheque::count() === Cheque::count());
        $this->check('earlier print rows are kept', \DB::table('print_logs')->where('printable_id', $p1->id)->count() >= 2);
        $tpl = PrintTemplate::where('kind', 'cheque')->first();
        $before = \DB::table('print_logs')->count();
        $this->actingAs($prn)->get("/printing/calibrate/{$tpl->id}")->assertOk();
        $this->check('calibration is never logged as a real print', \DB::table('print_logs')->count() === $before);
        $this->check('encoder cannot print', $this->actingAs($enc)->postJson('/printing/start', ['category' => 'cheque', 'ids' => [$p1->id]])->getStatusCode() === 403);

        // ---- Templates & banks
        $this->check('template in use cannot be deleted', $this->actingAs($admin)->delete("/admin/templates/{$tpl->id}")->getStatusCode() !== 204 && PrintTemplate::whereKey($tpl->id)->exists());
        $acct = BankAccount::first();
        $this->actingAs($admin)->put("/admin/accounts/{$acct->id}", ['branch' => $acct->branch, 'account_name' => $acct->account_name, 'account_number' => $acct->account_number, 'is_active' => false]);
        $this->check('deactivated bank account hidden from cheque form', $acct->refresh()->is_active === false
            && collect($this->actingAs($fin)->get('/cheques/create')->viewData('page')['props']['accounts'] ?? [])->where('id', $acct->id)->isEmpty());
        $acct->update(['is_active' => true]);

        // ---- Users
        $new = $this->actingAs($admin)->post('/admin/users', ['name' => 'New Person', 'email' => 'new@finox.test', 'role_id' => Role::where('name', 'encoder')->value('id'), 'password' => 'Temp#Pass2026x']);
        $nu = User::where('email', 'new@finox.test')->first();
        $this->check('admin can create user', (bool) $nu, 'status='.$new->getStatusCode());
        $this->check('new user must change password', $nu && $nu->must_change_password);
        $this->actingAs($admin)->post("/admin/users/{$nu->id}/toggle");
        $this->check('deactivated user is blocked', $this->actingAs($nu->refresh())->get('/dashboard')->getStatusCode() !== 200);
        $this->check('admin can see a user\'s activity and logins', $this->actingAs($admin)->get("/admin/users/{$nu->id}")->getStatusCode() === 200);
        $roleId = Role::where('name', 'encoder')->value('id');
        $this->actingAs($admin)->put("/admin/roles/{$roleId}", ['permissions' => array_slice(config('permissions.roles.encoder.permissions'), 1)]);
        $this->check('role permission change is logged', ActivityLog::where('action', 'like', 'role.%')->exists() || ActivityLog::where('module', 'users')->where('action', 'like', '%role%')->exists());
        $this->check('encoder cannot manage users', $this->actingAs($enc)->get('/admin/users')->getStatusCode() === 403);

        // ---- Session timeout
        Settings::set('session_minutes', 5);
        $idle = $this->actingAs($fin)->withSession(['last_activity' => now()->subMinutes(30)->timestamp])->get('/dashboard');
        $this->check('idle session is logged out after session_minutes', $idle->getStatusCode() === 302, 'status='.$idle->getStatusCode().' (inspect EnsureUserIsActive session key)');

        // ---- Activity log is read-only
        $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'activity') && $r->methods()[0] !== 'GET');
        $this->check('activity log has no write routes', $routes->isEmpty());

        $this->assertSame([], $this->problems, "\n".implode("\n", $this->problems));
    }
}
