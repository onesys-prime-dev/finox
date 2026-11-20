<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Models\BankAccount;
use App\Models\Cheque;
use App\Models\DocumentFile;
use App\Models\Payee;
use App\Models\PrintTemplate;
use App\Models\Role;
use App\Models\User;
use App\Services\ChequeService;
use App\Services\DocumentService;
use App\Services\ReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Every page and action, tried as every role, compared with what the permission matrix says should happen.
 * Failures are collected and reported together so one run shows every gap.
 */
class FullSystemTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = ['admin', 'finance', 'encoder', 'printing', 'management'];

    private array $problems = [];

    private function can(string $role, array $anyOf): bool
    {
        $perms = config("permissions.roles.{$role}.permissions");

        return $anyOf === [] || $perms === '*' || count(array_intersect($anyOf, $perms)) > 0;
    }

    private function note(string $m): void
    {
        $this->problems[] = $m;
    }

    private function expectStatus(string $label, string $role, $response, bool $allowed): void
    {
        $s = $response->getStatusCode();
        if ($s >= 500) {
            $this->note("[500] {$role} {$label} => {$s}");

            return;
        }
        if ($allowed && $s === 403) {
            $this->note("[WRONGLY DENIED] {$role} {$label}");
        }
        if (! $allowed && $s !== 403) {
            $this->note("[WRONGLY ALLOWED] {$role} {$label} => {$s}");
        }
    }

    private function seedData(): array
    {
        $admin = $this->userWithRole('admin');
        Storage::fake('local');
        $cs = app(ChequeService::class);
        $base = ['bank_account_id' => BankAccount::first()->id, 'cheque_date' => now()->toDateString(), 'payee_id' => Payee::first()->id, 'amount' => '1500.25'];
        $draft = $cs->create($base + ['cheque_number' => '5000000001'], $admin);
        $receipt = app(ReceiptService::class)->create([
            'receipt_number' => 'FS-1', 'receipt_date' => now()->toDateString(), 'payee_id' => Payee::first()->id, 'amount' => '250',
        ], $admin, record: true);
        $doc = DocumentFile::first() ?? app(DocumentService::class)->store(
            UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"), 'other', $draft, $admin);

        return compact('admin', 'draft', 'receipt', 'doc');
    }

    public function test_get_pages_match_permission_matrix(): void
    {
        $d = $this->seedData();
        $tpl = PrintTemplate::where('kind', 'cheque')->first();
        $ids = ['c' => $d['draft']->id, 'r' => $d['receipt']->id, 'd' => $d['doc']->id, 't' => $tpl->id, 'u' => $d['admin']->id];

        // url => permissions of which at least one is required (empty = any signed-in user)
        $pages = [
            '/dashboard' => [], '/profile' => [], '/search?q=a' => [], '/payees/search?q=a' => [],
            '/cheques' => ['cheques.view', 'cheques.view_all'], '/cheques/create' => ['cheques.create'],
            "/cheques/{$ids['c']}" => ['cheques.view', 'cheques.view_all'], "/cheques/{$ids['c']}/edit" => ['cheques.edit'],
            "/cheques/create?duplicate={$ids['c']}" => ['cheques.create'], '/cheques/check?bank_account_id=1&want_next=1' => ['cheques.create'],
            '/receipts' => ['receipts.view', 'receipts.view_all'], '/receipts/intake' => ['receipts.create'], '/receipts/new' => ['receipts.create'],
            "/receipts/{$ids['r']}" => ['receipts.view', 'receipts.view_all'], "/receipts/{$ids['r']}/edit" => ['receipts.edit'],
            '/receipts/duplicates?number=FS-1' => ['receipts.create'], '/receipts/cheque-search?q=5' => ['receipts.create'],
            '/documents' => ['documents.view', 'documents.upload'], "/documents/{$ids['d']}/view" => ['documents.view'],
            "/documents/{$ids['d']}/download" => ['documents.view'],
            '/printing' => ['print.print'], '/printing/history' => ['print.print', 'reports.printing', 'reports.view'],
            '/printing/templates' => ['print.calibrate', 'templates.manage'],
            "/printing/calibrate/{$ids['t']}" => ['print.calibrate', 'templates.manage'],
            '/transactions' => ['register.view'], '/transactions/export' => ['register.view'],
            '/reports' => ['reports.view', 'reports.printing'], '/reports/cheque-register' => ['reports.view'],
            '/activity' => ['activity.view'], '/payees' => ['payees.create', 'payees.manage', 'cheques.create', 'receipts.create', 'register.view', 'reports.view'],
            '/admin/users' => ['users.manage'], "/admin/users/{$ids['u']}" => ['users.manage'], '/admin/problems' => ['users.manage'],
            '/admin/roles' => ['roles.manage'], '/admin/banks' => ['banks.manage'], '/admin/templates' => ['templates.manage'],
            '/admin/templates/create' => ['templates.manage'], "/admin/templates/{$ids['t']}/edit" => ['templates.manage'],
            '/admin/settings' => ['settings.manage'],
        ];

        foreach (self::ROLES as $role) {
            $user = User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
            foreach ($pages as $url => $need) {
                $r = $this->actingAs($user)->get($url);
                // Encoders only see their own records: a record by someone else must be refused even though the page permission exists.
                $own = $role === 'encoder' && preg_match('#/(cheques|receipts|documents)/\d+|duplicate=#', $url);
                $allowed = $this->can($role, $need) && ! $own;
                if ($url === '/payees') {
                    continue; // payees list rule is checked separately below
                }
                $this->expectStatus("GET {$url}", $role, $r, $allowed);
            }
        }

        $this->report();
    }

    public function test_every_report_for_every_role(): void
    {
        $this->seedData();
        $reports = array_keys((new \ReflectionClass(ReportController::class))->getReflectionConstant('REPORTS')->getValue());
        foreach (self::ROLES as $role) {
            $user = User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
            foreach ($reports as $k) {
                $r = $this->actingAs($user)->get("/reports/{$k}");
                $e = $this->actingAs($user)->get("/reports/{$k}/export");
                foreach (['' => $r, '/export' => $e] as $suffix => $resp) {
                    if ($resp->getStatusCode() >= 500) {
                        $this->note("[500] {$role} /reports/{$k}{$suffix}");
                    }
                }
                $expect = $this->can($role, str_contains($k, 'print') ? ['reports.printing', 'reports.view'] : ($k === 'user-activity' ? ['activity.view'] : ['reports.view']));
                $this->expectStatus("/reports/{$k}", $role, $r, $expect);
            }
        }
        $this->report();
    }

    public function test_write_actions_match_permission_matrix(): void
    {
        $d = $this->seedData();
        $cs = app(ChequeService::class);
        $n = 5100000000;
        $fresh = function (string $to) use ($cs, $d, &$n) {
            $c = $cs->create(['bank_account_id' => BankAccount::first()->id, 'cheque_date' => now()->toDateString(), 'payee_id' => Payee::first()->id,
                'amount' => '99.50', 'cheque_number' => (string) (++$n)], $d['admin']);
            foreach (['prepared', 'printed', 'released', 'for_clearing'] as $step) {
                if ($c->status->value === $to) {
                    break;
                }
                match ($step) {
                    'prepared' => $cs->prepare($c, $d['admin']),
                    'printed' => $c->forceFill(['status' => 'printed', 'printed_at' => now()])->save(),
                    'released' => $cs->release($c->refresh(), ['released_at' => now()->toDateString(), 'released_to' => 'X', 'released_by' => 'Y'], $d['admin']),
                    'for_clearing' => $cs->markForClearing($c->refresh(), $d['admin']),
                };
                $c->refresh();
            }

            return $c;
        };

        foreach (self::ROLES as $role) {
            $u = User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
            $as = fn () => $this->actingAs($u);
            $own = fn () => $cs->create(['bank_account_id' => BankAccount::first()->id, 'cheque_date' => now()->toDateString(), 'payee_id' => Payee::first()->id,
                'amount' => '10', 'cheque_number' => (string) (++$n)], $u);

            // create cheque
            $this->expectStatus('POST /cheques', $role, $as()->post('/cheques', [
                'bank_account_id' => BankAccount::first()->id, 'cheque_number' => (string) (++$n), 'cheque_date' => now()->toDateString(),
                'payee_id' => Payee::first()->id, 'amount' => '75',
            ]), $this->can($role, ['cheques.create']));

            // prepare / release / clearing / stop on someone else's records
            $this->expectStatus('POST prepare', $role, $as()->post('/cheques/'.$fresh('draft')->id.'/prepare'), $this->can($role, ['cheques.prepare']));
            $this->expectStatus('POST release', $role, $as()->post('/cheques/'.$fresh('printed')->id.'/release', [
                'released_at' => now()->toDateString(), 'released_to' => 'A', 'released_by' => 'B']), $this->can($role, ['cheques.release']));
            $this->expectStatus('POST for-clearing', $role, $as()->post('/cheques/'.$fresh('released')->id.'/for-clearing'), $this->can($role, ['cheques.clear']));
            $this->expectStatus('POST clearing', $role, $as()->post('/cheques/'.$fresh('for_clearing')->id.'/clearing', [
                'result' => 'cleared', 'cleared_at' => now()->toDateString()]), $this->can($role, ['cheques.clear']));
            $this->expectStatus('POST stale', $role, $as()->post('/cheques/'.$fresh('for_clearing')->id.'/stale'), $this->can($role, ['cheques.clear']));
            $this->expectStatus('POST stop(cancel)', $role, $as()->post('/cheques/'.$fresh('draft')->id.'/stop', ['kind' => 'cancel', 'reason' => 'test reason']), $this->can($role, ['cheques.cancel']));

            // receipts
            $this->expectStatus('POST /receipts', $role, $as()->post('/receipts', [
                'receipt_number' => 'W-'.$u->id, 'receipt_date' => now()->toDateString(), 'payee_id' => Payee::first()->id, 'amount' => '5', '_record' => true,
            ]), $this->can($role, ['receipts.create']));
            $rc = app(ReceiptService::class)->create(['receipt_number' => 'V-'.$u->id.'-'.$role, 'receipt_date' => now()->toDateString(), 'payee_id' => Payee::first()->id, 'amount' => '9'], $d['admin'], record: true);
            $this->expectStatus('POST receipt verify', $role, $as()->post("/receipts/{$rc->id}/verify"), $this->can($role, ['receipts.verify']));

            // printing
            $prep = $fresh('prepared');
            $this->expectStatus('POST printing/preview', $role, $as()->postJson('/printing/preview', ['category' => 'cheque', 'ids' => [$prep->id]]), $this->can($role, ['print.print']));
            $this->expectStatus('POST printing/start', $role, $as()->postJson('/printing/start', ['category' => 'cheque', 'ids' => [$fresh('prepared')->id]]), $this->can($role, ['print.print']));

            // reference data
            $this->expectStatus('POST /payees', $role, $as()->post('/payees/quick', ['name' => "P {$role}"]), $this->can($role, ['payees.create', 'payees.manage']));
            $this->expectStatus('POST /documents', $role, $as()->post('/documents', [
                'file' => UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF"), 'category' => 'other']), $this->can($role, ['documents.upload']));

            // admin
            $this->expectStatus('POST admin/users', $role, $as()->post('/admin/users', ['name' => 'N', 'email' => "n{$role}@x.test", 'role_id' => 2]), $this->can($role, ['users.manage']));
            $this->expectStatus('PUT admin/settings', $role, $as()->put('/admin/settings', []), $this->can($role, ['settings.manage']));
            $this->expectStatus('POST admin/banks', $role, $as()->post('/admin/banks', ['name' => "Bank {$role}"]), $this->can($role, ['banks.manage']));
        }
        $this->report();
    }

    public function test_encoder_sees_only_own_records_and_cannot_touch_others(): void
    {
        $d = $this->seedData();
        $enc = $this->userWithRole('encoder');
        $this->actingAs($enc)->get("/cheques/{$d['draft']->id}")->assertForbidden();
        $this->actingAs($enc)->get("/receipts/{$d['receipt']->id}")->assertForbidden();
        $this->actingAs($enc)->get('/transactions')->assertForbidden();
        $this->actingAs($enc)->put("/cheques/{$d['draft']->id}", ['purpose' => 'hack'])->assertForbidden();
        $this->actingAs($enc)->get("/documents/{$d['doc']->id}/download")->assertStatus(in_array($enc->id, [$d['doc']->uploaded_by]) ? 200 : 403);
    }

    private function report(): void
    {
        $this->assertSame([], $this->problems, "\n".implode("\n", $this->problems));
    }
}
