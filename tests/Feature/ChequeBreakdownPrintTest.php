<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Payee;
use App\Models\PrintTemplate;
use App\Services\ChequeService;
use App\Services\PrintingService;
use App\Support\BdoAdviceLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChequeBreakdownPrintTest extends TestCase
{
    use RefreshDatabase;

    private function cheque($user, array $lines)
    {
        return app(ChequeService::class)->create(['bank_account_id' => BankAccount::first()->id, 'cheque_number' => (string) random_int(95000, 99999),
            'cheque_date' => now()->toDateString(), 'payee_id' => Payee::first()->id, 'amount' => '35,500.50', 'lines' => $lines], $user);
    }

    public function test_print_values_carry_formatted_rows_and_total(): void
    {
        $fin = $this->userWithRole('finance');
        $c = $this->cheque($fin, [['reference_no' => 'OR-1', 'vendor' => 'A', 'particulars' => 'x', 'amount' => '10000', 'line_date' => '2026-11-02'], ['reference_no' => 'OR-2', 'amount' => '25500.50']]);
        $svc = app(PrintingService::class);
        $v = $svc->values($svc->load('cheque', [$c->id])->first(), $fin);

        $this->assertSame(2, $v['breakdown']['count']);
        $this->assertSame('35,500.50', $v['breakdown']['total']);
        $this->assertSame('10,000.00', $v['breakdown']['rows'][0]['amount']);
        $this->assertSame('OR-1', $v['breakdown']['rows'][0]['reference_no']);
    }

    public function test_cheque_without_rows_prints_an_empty_breakdown(): void
    {
        $fin = $this->userWithRole('finance');
        $c = $this->cheque($fin, []);
        $svc = app(PrintingService::class);
        $v = $svc->values($svc->load('cheque', [$c->id])->first(), $fin);
        $this->assertSame(['rows' => [], 'count' => 0, 'total' => ''], $v['breakdown']);
    }

    public function test_bdo_template_has_the_breakdown_table_and_advice_crop(): void
    {
        $this->userWithRole('admin'); // boots the seeded data
        $t = PrintTemplate::where('kind', 'cheque')->first();
        $table = collect($t->layout['fields'])->firstWhere('type', 'table');
        $this->assertNotNull($table);
        $this->assertSame('breakdown', $table['source']);
        $this->assertSame(BdoAdviceLayout::CROP, $t->layout['advice_crop']);
        // Nothing printed over the pre-printed CHECK No. box (x 148-175, y 80-90 mm).
        foreach ($t->layout['fields'] as $f) {
            if (($f['region'] ?? '') === 'advice' && ($f['type'] ?? '') !== 'table') {
                $overlapsX = $f['x'] < 175 && $f['x'] + $f['width'] > 148;
                $overlapsY = $f['y'] < 90 && $f['y'] + 4 > 80;
                $this->assertFalse($overlapsX && $overlapsY, "{$f['key']} overlaps the pre-printed CHECK No.");
            }
        }
    }

    public function test_template_editor_accepts_a_table_field_and_rejects_bad_columns(): void
    {
        $admin = $this->userWithRole('admin');
        $t = PrintTemplate::where('kind', 'cheque')->first();
        $layout = $t->layout;
        $payload = fn (array $l) => ['name' => $t->name, 'kind' => 'cheque', 'paper_width_mm' => 200, 'paper_height_mm' => 280, 'offset_x_mm' => 0, 'offset_y_mm' => 0,
            'is_active' => 1, 'layout' => $l];

        $this->actingAs($admin)->post("/admin/templates/{$t->id}", $payload($layout))->assertSessionHasNoErrors();

        $bad = $layout;
        foreach ($bad['fields'] as &$f) {
            if (($f['type'] ?? '') === 'table') {
                $f['columns'][0]['key'] = 'not_a_column';
            }
        }
        $this->actingAs($admin)->post("/admin/templates/{$t->id}", $payload($bad))->assertSessionHasErrors();
    }

    public function test_breakdown_report_lists_rows_and_searches_by_reference(): void
    {
        $fin = $this->userWithRole('finance');
        $this->cheque($fin, [['reference_no' => 'OR-777', 'vendor' => 'Findable Co', 'amount' => '100']]);
        $this->cheque($fin, [['reference_no' => 'OR-888', 'vendor' => 'Other', 'amount' => '200']]);

        $this->actingAs($fin)->get('/reports/cheque-breakdown?q=OR-777')->assertOk()->assertSee('OR-777')->assertDontSee('OR-888');
        $this->actingAs($fin)->get('/reports/cheque-breakdown/export?format=csv')->assertOk();
    }

    public function test_schedule_page_renders_and_logs_printing(): void
    {
        $fin = $this->userWithRole('finance');
        $c = $this->cheque($fin, [['reference_no' => 'OR-1', 'amount' => '100']]);
        $this->actingAs($fin)->get("/cheques/{$c->id}/schedule")->assertOk();
        $this->actingAs($fin)->postJson("/cheques/{$c->id}/schedule/printed")->assertOk();
        $this->assertDatabaseHas('activity_logs', ['action' => 'cheque.schedule_printed']);
    }

    public function test_saving_a_template_from_the_editor_keeps_real_numbers_and_booleans(): void
    {
        $admin = $this->userWithRole('admin');
        $t = PrintTemplate::where('kind', 'cheque')->first();
        // The editor posts multipart data: everything arrives as strings.
        $stringify = function ($v) use (&$stringify) {
            return is_array($v) ? array_map($stringify, $v) : (is_bool($v) ? ($v ? '1' : '0') : (is_null($v) ? null : (string) $v));
        };
        $layout = $stringify($t->layout);
        $this->actingAs($admin)->post("/admin/templates/{$t->id}", ['name' => $t->name, 'kind' => 'cheque', 'paper_width_mm' => '200', 'paper_height_mm' => '280',
            'offset_x_mm' => '0', 'offset_y_mm' => '0', 'is_active' => '1', 'layout' => $layout])->assertSessionHasNoErrors();

        $saved = $t->fresh()->layout;
        $table = collect($saved['fields'])->firstWhere('type', 'table');
        $this->assertIsNotString($table['x']);
        $this->assertEquals(12, $table['x']);
        $this->assertSame(15, $table['max_rows']);
        $this->assertFalse($table['uppercase']);
        $this->assertTrue($table['header']);
        $this->assertIsNotString($table['columns'][0]['width']);
        $this->assertEquals(18, $table['columns'][0]['width']);
        $this->assertIsNotString($saved['advice_crop']['top']);
        $this->assertEquals(BdoAdviceLayout::CROP['top'], $saved['advice_crop']['top']);
        $this->assertFalse(collect($saved['fields'])->firstWhere('key', 'adv_date')['uppercase']);
    }
}
