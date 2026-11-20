<?php

namespace Tests\Feature;

use App\Models\DocumentFile;
use App\Services\DocumentService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Records past the retention period are hidden by default, never deleted, and still reachable. */
class RetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_records_are_archived_not_deleted(): void
    {
        Storage::fake('local');
        $admin = $this->userWithRole('admin');
        $new = app(DocumentService::class)->store(
            UploadedFile::fake()->createWithContent('new.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF"), 'other', null, $admin);
        $old = app(DocumentService::class)->store(
            UploadedFile::fake()->createWithContent('old.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF"), 'other', null, $admin);
        DocumentFile::whereKey($old->id)->update(['created_at' => now()->subYears(11)]);
        \DB::table('activity_logs')->insert(['user_id' => $admin->id, 'module' => 'test', 'action' => 'old.entry', 'description' => 'ancient entry',
            'created_at' => now()->subYears(11)]);

        $names = fn ($url) => collect($this->actingAs($admin)->get($url)->viewData('page')['props']['documents']['data'])->pluck('name')->all();
        $this->assertSame(['new.pdf'], $names('/documents'));
        $this->assertEqualsCanonicalizing(['new.pdf', 'old.pdf'], $names('/documents?archived=1'));

        $desc = fn ($url) => collect($this->actingAs($admin)->get($url)->viewData('page')['props']['logs']['data'])->pluck('description')->all();
        $this->assertNotContains('ancient entry', $desc('/activity'));
        $this->assertContains('ancient entry', $desc('/activity?archived=1'));

        $this->assertTrue(DocumentFile::whereKey($old->id)->exists(), 'archived document is never deleted');
        $this->assertSame(200, $this->actingAs($admin)->get("/documents/{$old->id}/view")->getStatusCode(), 'archived document still opens');

        $this->actingAs($admin)->put('/admin/settings', array_merge(Settings::all(), ['retention_years' => 15]))->assertSessionHasNoErrors();
        $this->assertSame(15, Settings::get('retention_years'));
        $this->assertEqualsCanonicalizing(['new.pdf', 'old.pdf'], $names('/documents'), 'longer retention brings it back');
    }
}
