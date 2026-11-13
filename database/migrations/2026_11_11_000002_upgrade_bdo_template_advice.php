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
        $keys = array_column($layout['fields'] ?? [], 'key');
        if (in_array('breakdown', $keys, true)) {
            return; // already upgraded
        }
        // Replace only the advice fields FINOX shipped (and sources that no longer exist).
        // Advice fields an admin added are kept as they are.
        $stock = ['adv_payee', 'adv_amount', 'adv_purpose', 'adv_ref', 'adv_by', 'adv_rcp_refs', 'adv_rcp_amts', 'adv_rcp_total', 'adv_date', 'adv_chk', 'adv_apr'];
        $gone = ['receipt_refs', 'receipt_amounts', 'receipt_total'];
        $custom = [];
        $other = [];
        foreach ($layout['fields'] ?? [] as $f) {
            if (($f['region'] ?? null) !== 'advice') {
                $other[] = $f;
            } elseif (! in_array($f['key'] ?? '', $stock, true) && ! in_array($f['source'] ?? '', $gone, true)) {
                $custom[] = $f;
            }
        }
        $upgraded = BdoAdviceLayout::apply(['fields' => $other] + $layout);
        $taken = array_column($upgraded['fields'], 'key');
        foreach ($custom as $f) {
            if (in_array($f['key'] ?? '', $taken, true)) {
                $f['key'] .= '_custom';
            }
            $upgraded['fields'][] = $f;
        }
        DB::table('print_templates')->where('id', $t->id)->update([
            'layout' => json_encode($upgraded),
            'version' => $t->version + 1,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // The shipped advice fields are not restored (custom ones were kept); edit the template in the editor if needed.
    }
};