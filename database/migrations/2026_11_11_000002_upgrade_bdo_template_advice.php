<?php

use App\Support\BdoAdviceLayout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Give the BDO 3-part template its breakdown table (as a new template version). */
    public function up(): void
    {
        $t = DB::table('print_templates')->where('name', 'BDO Corporate Check Printing (3-part)')->first();
        if (! $t) {
            return;
        }
        $layout = json_decode($t->layout, true) ?: [];
        // Only touch templates that still have the original advice fields.
        $keys = array_column($layout['fields'] ?? [], 'key');
        if (in_array('breakdown', $keys, true)) {
            return;
        }
        DB::table('print_templates')->where('id', $t->id)->update([
            'layout' => json_encode(BdoAdviceLayout::apply($layout)),
            'version' => $t->version + 1,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // The previous advice fields are not restored; edit the template in the editor if needed.
    }
};