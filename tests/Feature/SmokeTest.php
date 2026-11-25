<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Models\BankAccount;
use App\Models\Payee;
use App\Models\PrintJob;
use App\Models\PrintTemplate;
use App\Services\ChequeService;
use App\Services\ReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every page renders for an administrator, with real data present. */
class SmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_pages_render(): void
    {
        $admin = $this->userWithRole('admin');
        $cheque = app(ChequeService::class)->create([
            'bank_account_id' => BankAccount::first()->id, 'cheque_number' => '0000094700', 'cheque_date' => now()->toDateString(),
            'payee_id' => Payee::first()->id, 'amount' => '5000', 'purpose' => 'Smoke',
        ], $admin);
        $receipt = app(ReceiptService::class)->create([
            'receipt_number' => 'R-1', 'receipt_date' => now()->toDateString(), 'payee_id' => Payee::first()->id, 'amount' => '100',
        ], $admin, record: true);
        $tpl = PrintTemplate::first();

        $urls = [
            '/dashboard', '/dashboard?from=2026-01-01&to=2026-12-31', '/profile', '/cheques', '/cheques?status=outstanding&search=0000', '/cheques/create',
            "/cheques/{$cheque->id}", "/cheques/{$cheque->id}/edit", "/cheques/create?duplicate={$cheque->id}",
            '/receipts', '/receipts/intake', '/receipts/new', "/receipts/{$receipt->id}", "/receipts/{$receipt->id}/edit",
            '/documents', '/printing', '/printing?category=receipt', "/printing?select={$cheque->id}", '/printing/history', "/printing/calibrate/{$tpl->id}",
            '/transactions', '/transactions?type=cheque&min=1', '/reports', '/payees', '/activity',
            '/admin/users', '/admin/roles', '/admin/banks', '/admin/templates', '/admin/templates/create', "/admin/templates/{$tpl->id}/edit",
            '/admin/settings', '/admin/problems', '/search?q=000', '/payees/search?q=a', '/cheques/check?bank_account_id=1&want_next=1&amount=1500',
            '/receipts/duplicates?number=R-1', '/receipts/cheque-search', '/printing/templates', "/templates/{$tpl->id}/background", '/transactions/export',
        ];
        foreach ($urls as $u) {
            $r = $this->actingAs($admin)->get($u);
            $this->assertTrue(in_array($r->getStatusCode(), [200, 404], true) && ($r->getStatusCode() === 200 || str_contains($u, 'background')), "GET {$u} => {$r->getStatusCode()}\n".substr((string) ($r->baseResponse instanceof SymfonyComponentHttpFoundationStreamedResponse ? '' : $r->getContent()), 0, 600));
        }
        foreach (array_keys((fn () => (new \ReflectionClass(ReportController::class))->getReflectionConstant('REPORTS')->getValue())()) as $k) {
            $this->actingAs($admin)->get("/reports/{$k}")->assertOk();
            $this->actingAs($admin)->get("/reports/{$k}/export")->assertOk();
        }
    }

    public function test_receipt_flow_and_preview(): void
    {
        $fin = $this->userWithRole('finance');
        $c = app(ChequeService::class)->create([
            'bank_account_id' => BankAccount::first()->id, 'cheque_number' => '0000094701', 'cheque_date' => now()->toDateString(),
            'payee_id' => Payee::first()->id, 'amount' => '1234.5',
        ], $fin);
        $this->actingAs($fin)->post("/cheques/{$c->id}/prepare")->assertRedirect();
        $r = $this->actingAs($fin)->postJson('/printing/preview', ['category' => 'cheque', 'ids' => [$c->id]]);
        $r->assertOk()->assertJsonPath('pages.0.values.amount_words', 'ONE THOUSAND TWO HUNDRED THIRTY FOUR AND 50/100 PESOS ONLY');
        $job = $this->actingAs($fin)->postJson('/printing/start', ['category' => 'cheque', 'ids' => [$c->id]])->assertOk()->json('job_id');
        $this->actingAs($fin)->post("/printing/jobs/{$job}/confirm", [])->assertRedirect();
        $this->assertSame('confirmed', PrintJob::find($job)->status);
    }
}
