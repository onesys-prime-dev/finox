<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Cheque;
use App\Models\Payee;
use App\Models\User;
use App\Services\ChequeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Actions that no other test presses: unlock an account, mark a cheque stale, save a profile preference. */
class AdminAndStaleActionsTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 7100000000;

    private function releasedCheque(User $by): Cheque
    {
        $cs = app(ChequeService::class);
        $c = $cs->create(['bank_account_id' => BankAccount::first()->id, 'cheque_date' => now()->toDateString(), 'payee_id' => Payee::first()->id,
            'amount' => '100.00', 'cheque_number' => (string) (++$this->n)], $by);
        $cs->prepare($c, $by);
        $c->refresh()->forceFill(['status' => 'printed', 'printed_at' => now()])->save();
        $cs->release($c->refresh(), ['released_at' => now()->subDays(200)->toDateString(), 'released_to' => 'X', 'released_by' => 'Y'], $by);

        return $c->refresh();
    }

    public function test_admin_can_unlock_a_locked_account_and_it_is_logged(): void
    {
        $admin = $this->userWithRole('admin');
        $locked = $this->userWithRole('encoder');
        $locked->forceFill(['failed_login_count' => 5, 'locked_until' => now()->addMinutes(15)])->save();

        $this->actingAs($admin)->post("/admin/users/{$locked->id}/unlock")->assertSessionHas('success');

        $locked->refresh();
        $this->assertSame(0, (int) $locked->failed_login_count);
        $this->assertNull($locked->locked_until);
        $this->assertTrue(ActivityLog::where('action', 'user.unlocked')->exists());
    }

    public function test_only_user_managers_can_unlock_accounts(): void
    {
        $locked = $this->userWithRole('encoder');
        $locked->forceFill(['failed_login_count' => 5, 'locked_until' => now()->addMinutes(15)])->save();

        foreach (['encoder', 'finance', 'printing', 'management'] as $role) {
            $this->actingAs($this->userWithRole($role))->post("/admin/users/{$locked->id}/unlock")->assertForbidden();
        }
        $this->assertNotNull($locked->fresh()->locked_until);
    }

    public function test_flagged_released_cheque_can_be_marked_stale_by_finance_only(): void
    {
        $fin = $this->userWithRole('finance');
        $c = $this->releasedCheque($fin);
        app(ChequeService::class)->flagStale();
        $this->assertNotNull($c->fresh()->stale_flagged_at);

        $this->actingAs($this->userWithRole('encoder'))->post("/cheques/{$c->id}/stale")->assertForbidden();
        $this->assertSame('released', $c->fresh()->status->value);

        $this->actingAs($fin)->post("/cheques/{$c->id}/stale")->assertSessionHas('success');
        $c->refresh();
        $this->assertSame('stale', $c->status->value);
        $this->assertNull($c->stale_flagged_at);
        $this->assertTrue(ActivityLog::where('action', 'cheque.stale')->exists());
    }

    public function test_a_cheque_that_was_never_released_cannot_be_marked_stale(): void
    {
        $fin = $this->userWithRole('finance');
        $cs = app(ChequeService::class);
        $draft = $cs->create(['bank_account_id' => BankAccount::first()->id, 'cheque_date' => now()->toDateString(), 'payee_id' => Payee::first()->id,
            'amount' => '50.00', 'cheque_number' => (string) (++$this->n)], $fin);

        // the policy only allows clearing actions on Released / For Clearing cheques
        $this->actingAs($fin)->post("/cheques/{$draft->id}/stale")->assertForbidden();
        $this->assertSame('draft', $draft->fresh()->status->value);
    }

    public function test_profile_preferences_belong_to_the_signed_in_user(): void
    {
        $a = $this->userWithRole('encoder');
        $b = $this->userWithRole('encoder');

        $this->actingAs($a)->post('/profile/preferences', ['key' => 'cols.cheques', 'value' => ['reference_no']])->assertRedirect();
        $this->assertSame(['reference_no'], $a->fresh()->preferences['cols.cheques']);
        $this->assertEmpty($b->fresh()->preferences ?? []);

        $this->actingAs($a)->post('/profile/preferences', ['value' => 'x'])->assertSessionHasErrors('key');
    }
}
