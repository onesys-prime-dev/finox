<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Payee;
use App\Models\Receipt;
use App\Models\ReceiptCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptCategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private function useCategory(ReceiptCategory $c, $user): void
    {
        Receipt::create(['receipt_number' => 'CAT-1', 'receipt_date' => now()->toDateString(), 'payee_id' => Payee::first()->id,
            'amount' => '100', 'status' => 'recorded', 'created_by' => $user->id, 'category_id' => $c->id]);
    }

    public function test_rename_keeps_the_receipts_and_logs_the_old_name(): void
    {
        $admin = $this->userWithRole('admin');
        $c = ReceiptCategory::create(['name' => 'Stationry']);
        $this->useCategory($c, $admin);

        $this->actingAs($admin)->put("/admin/categories/{$c->id}", ['name' => '  Stationery ', 'is_active' => true])->assertSessionHasNoErrors();

        $this->assertSame('Stationery', $c->fresh()->name);
        $this->assertSame(1, $c->fresh()->receipts()->count());
        $this->assertTrue(ActivityLog::where('action', 'category.updated')->where('description', 'Renamed receipt category Stationry to Stationery')->exists());
    }

    public function test_names_must_stay_unique_when_adding_or_renaming(): void
    {
        $admin = $this->userWithRole('admin');
        ReceiptCategory::create(['name' => 'Postage']);
        $other = ReceiptCategory::create(['name' => 'Courier']);

        $this->actingAs($admin)->post('/admin/categories', ['name' => 'Postage'])->assertSessionHasErrors('name');
        $this->actingAs($admin)->put("/admin/categories/{$other->id}", ['name' => 'Postage', 'is_active' => true])->assertSessionHasErrors('name');
        $this->assertSame('Courier', $other->fresh()->name);
    }

    public function test_only_a_category_nothing_uses_can_be_deleted(): void
    {
        $admin = $this->userWithRole('admin');
        $used = ReceiptCategory::create(['name' => 'Used']);
        $free = ReceiptCategory::create(['name' => 'QA test']);
        $this->useCategory($used, $admin);

        $this->actingAs($admin)->delete("/admin/categories/{$used->id}")->assertSessionHas('error');
        $this->assertNotNull($used->fresh());

        $this->actingAs($admin)->delete("/admin/categories/{$free->id}")->assertSessionHas('success');
        $this->assertNull(ReceiptCategory::find($free->id));
        $this->assertTrue(ActivityLog::where('action', 'category.deleted')->exists());
    }

    public function test_settings_page_reports_how_many_receipts_use_each_category(): void
    {
        $admin = $this->userWithRole('admin');
        $c = ReceiptCategory::create(['name' => 'Counted']);
        $this->useCategory($c, $admin);

        $cats = collect($this->actingAs($admin)->get('/admin/settings')->viewData('page')['props']['categories']);
        $this->assertSame(1, $cats->firstWhere('name', 'Counted')['receipts_count']);
    }

    public function test_only_people_who_manage_settings_can_change_categories(): void
    {
        $c = ReceiptCategory::create(['name' => 'Locked']);
        foreach (['encoder', 'finance', 'management'] as $role) {
            $u = $this->userWithRole($role);
            $this->actingAs($u)->put("/admin/categories/{$c->id}", ['name' => 'X', 'is_active' => true])->assertForbidden();
            $this->actingAs($u)->delete("/admin/categories/{$c->id}")->assertForbidden();
        }
        $this->assertSame('Locked', $c->fresh()->name);
    }
}
