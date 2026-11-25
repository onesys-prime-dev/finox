<?php

namespace Tests\Feature;

use App\Enums\ReceiptStatus;
use App\Models\ActivityLog;
use App\Models\Receipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IntakeDiscardTest extends TestCase
{
    use RefreshDatabase;

    private function upload($user): Receipt
    {
        $pdf = UploadedFile::fake()->createWithContent('wrong.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $this->actingAs($user)->post('/receipts/upload', ['files' => [$pdf]])->assertSessionHasNoErrors();

        return Receipt::latest('id')->firstOrFail();
    }

    public function test_encoder_can_remove_a_wrongly_uploaded_draft_from_the_queue(): void
    {
        Storage::fake('local');
        $enc = $this->userWithRole('encoder');
        $r = $this->upload($enc);
        $this->assertSame(ReceiptStatus::Draft, $r->status);

        $this->actingAs($enc)->post("/receipts/{$r->id}/discard")->assertRedirect('/receipts/intake');

        // Nothing is deleted: the receipt is cancelled, with the reason and the action in the activity log.
        $this->assertSame(ReceiptStatus::Cancelled, $r->fresh()->status);
        $this->assertTrue(ActivityLog::where('action', 'receipt.discarded')->exists());
        $queue = $this->actingAs($enc)->get('/receipts/intake')->viewData('page')['props']['queue'];
        $this->assertCount(0, $queue);
    }

    public function test_only_drafts_can_be_removed_and_only_by_someone_who_may_edit_them(): void
    {
        Storage::fake('local');
        $enc = $this->userWithRole('encoder');
        $other = $this->userWithRole('encoder');
        $mgmt = $this->userWithRole('management');
        $r = $this->upload($enc);

        $this->actingAs($other)->post("/receipts/{$r->id}/discard")->assertForbidden();
        $this->actingAs($mgmt)->post("/receipts/{$r->id}/discard")->assertForbidden();
        $this->assertSame(ReceiptStatus::Draft, $r->fresh()->status);

        $r->forceFill(['status' => ReceiptStatus::Recorded])->save();
        $this->actingAs($enc)->post("/receipts/{$r->id}/discard")->assertForbidden();
        $this->assertSame(ReceiptStatus::Recorded, $r->fresh()->status);
    }
}
