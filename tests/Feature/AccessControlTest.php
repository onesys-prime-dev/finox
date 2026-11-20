<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\DocumentFile;
use App\Models\Payee;
use App\Services\ChequeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/cheques')->assertRedirect('/login');
    }

    public function test_no_public_registration(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_encoder_cannot_reach_admin_or_prepare(): void
    {
        $enc = $this->userWithRole('encoder');
        $this->actingAs($enc)->get('/admin/users')->assertForbidden();
        $this->actingAs($enc)->get('/activity')->assertForbidden();
        $this->actingAs($enc)->get('/printing')->assertForbidden();
        $this->actingAs($enc)->get('/transactions')->assertForbidden();
    }

    public function test_encoder_only_sees_own_cheques(): void
    {
        $a = $this->userWithRole('encoder');
        $b = $this->userWithRole('encoder');
        $c = ChequeService::class;
        $mine = app($c)->create(['bank_account_id' => BankAccount::first()->id, 'cheque_number' => '1', 'cheque_date' => now()->toDateString(), 'payee_id' => Payee::first()->id, 'amount' => '10'], $a);
        $theirs = app($c)->create(['bank_account_id' => BankAccount::first()->id, 'cheque_number' => '2', 'cheque_date' => now()->toDateString(), 'payee_id' => Payee::first()->id, 'amount' => '10'], $b);
        $this->actingAs($a)->get("/cheques/{$mine->id}")->assertOk();
        $this->actingAs($a)->get("/cheques/{$theirs->id}")->assertForbidden();
        $this->actingAs($a)->post("/cheques/{$mine->id}/prepare")->assertForbidden();
    }

    public function test_denied_action_is_refused_server_side(): void
    {
        $mgmt = $this->userWithRole('management');
        $this->actingAs($mgmt)->post('/cheques', [])->assertForbidden();
        $this->actingAs($mgmt)->get('/cheques')->assertOk();
    }

    public function test_deactivated_user_is_blocked(): void
    {
        $u = $this->userWithRole('finance');
        $u->update(['is_active' => false]);
        $this->actingAs($u)->get('/dashboard')->assertRedirect('/login');
    }

    public function test_login_lockout_after_failures(): void
    {
        $u = $this->userWithRole('finance');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $u->email, 'password' => 'wrong']);
        }
        $this->post('/login', ['email' => $u->email, 'password' => 'Password#12345'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_forced_password_change(): void
    {
        $u = $this->userWithRole('finance');
        $u->update(['must_change_password' => true]);
        $this->actingAs($u)->get('/dashboard')->assertRedirect('/change-password');
    }

    public function test_documents_are_private_and_type_checked_by_content(): void
    {
        Storage::fake('local');
        $enc = $this->userWithRole('encoder');
        $other = $this->userWithRole('encoder');
        $fake = UploadedFile::fake()->createWithContent('evil.pdf', '<?php echo 1;');
        $this->actingAs($enc)->post('/receipts/upload', ['files' => [$fake]])->assertSessionHasErrors('file');

        $pdf = UploadedFile::fake()->createWithContent('r.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $this->actingAs($enc)->post('/receipts/upload', ['files' => [$pdf]])->assertSessionHasNoErrors();
        $doc = DocumentFile::first();
        $this->assertNotNull($doc);
        $this->actingAs($enc)->get("/documents/{$doc->id}/view")->assertOk();
        $this->actingAs($other)->get("/documents/{$doc->id}/view")->assertForbidden();
        $this->assertStringNotContainsString('r.pdf', $doc->path);
    }
}
