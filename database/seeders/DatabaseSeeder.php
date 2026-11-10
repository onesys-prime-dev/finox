<?php

namespace Database\Seeders;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Payee;
use App\Models\Permission;
use App\Models\PrintTemplate;
use App\Models\ReceiptCategory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->permissionsAndRoles();

        $admin = User::firstOrCreate(['email' => 'admin@finox.test'], [
            'name' => 'System Administrator',
            'password' => 'ChangeMe#2026',
            'role_id' => Role::where('name', 'admin')->value('id'),
            'must_change_password' => true,
        ]);

        foreach (['Cash Sales', 'Utilities', 'Supplies', 'Professional Fees', 'Taxes & Government', 'Other'] as $c) {
            ReceiptCategory::firstOrCreate(['name' => $c]);
        }

        $this->templates();
        $this->banks();

        // Payees taken from the legacy Excel check workbook (review in Payees before use).
        foreach ([
            'Cythia Domdom', 'Alvin Valerio', 'Philippine Heart Center', 'Tommy B. Teofilo',
            'Eastern Telecommunications Philippines Inc.', 'Barklane Realty Corp', 'Cityland Herrera Tower, Inc.',
            'Luvican Management Inc.', 'Joyce Vel M. Formon', 'Juris Judeial T. Masiglat', 'Elmer Opina',
            'Wilson John S. Gimena',
        ] as $p) {
            Payee::firstOrCreate(['name' => $p]);
        }
        Payee::firstOrCreate(['name' => 'Bureau of Internal Revenue'], [
            'default_payee_line' => 'BPI Ayala II Branch FAO Bureau of Internal Revenue IFO [Taxpayer Name] TIN [000-000-000-000]',
        ]);
    }

    private function permissionsAndRoles(): void
    {
        $keys = [];
        foreach (config('permissions.permissions') as $group => $perms) {
            foreach ($perms as $key => $label) {
                Permission::updateOrCreate(['key' => $key], ['label' => $label, 'group' => $group]);
                $keys[] = $key;
            }
        }
        foreach (config('permissions.roles') as $name => $def) {
            $role = Role::updateOrCreate(['name' => $name], [
                'label' => $def['label'], 'description' => $def['description'], 'is_system' => true,
            ]);
            // Only seed permissions for brand-new roles so admin edits are never overwritten.
            if ($role->wasRecentlyCreated) {
                $role->syncPermissionKeys($def['permissions'] === '*' ? $keys : $def['permissions']);
            }
        }
    }

    /** BDO Corporate Check Printing, 200 x 280 mm, three parts on one sheet. Positions measured from the scan. */
    private function templates(): void
    {
        $bg = null;
        if (is_file(storage_path('app/private/seed/bdo-check-bg.jpg'))) {
            $bg = 'templates/bdo-check-bg.jpg';
            Storage::disk('local')->put($bg, file_get_contents(storage_path('app/private/seed/bdo-check-bg.jpg')));
        }
        $f = fn ($key, $src, $x, $y, $w, $extra = []) => array_merge([
            'key' => $key, 'source' => $src, 'x' => $x, 'y' => $y, 'width' => $w,
            'font_size' => 11, 'align' => 'left', 'uppercase' => true, 'max_lines' => 1, 'format' => null, 'region' => 'cheque',
        ], $extra);

        PrintTemplate::firstOrCreate(['name' => 'BDO Corporate Check Printing (3-part)'], [
            'kind' => 'cheque', 'paper_width_mm' => 200, 'paper_height_mm' => 280, 'background_path' => $bg,
            'notes' => 'Cheque no., account details and MICR are pre-printed and are never printed by FINOX.',
            'layout' => ['preview_crop' => ['top' => 205, 'height' => 75], 'fields' => [
                $f('date', 'date', 152, 214.6, 45, ['format' => 'date_segmented', 'font_size' => 12,
                    'char_x' => [154.4, 159.6, 167.4, 172.4, 179.2, 184.2, 189.2, 194.2]]),
                $f('payee', 'payee_line', 30, 223.4, 108, ['font_size' => 11, 'max_lines' => 1]),
                $f('amount', 'amount_number', 146, 223.8, 49, ['align' => 'left', 'font_size' => 12, 'format' => 'amount_stars']),
                $f('words', 'amount_words', 22, 231.4, 174, ['font_size' => 9, 'max_lines' => 2]),
                $f('adv_payee', 'payee_line', 20, 100, 150, ['region' => 'advice', 'font_size' => 11, 'label' => 'Payee']),
                $f('adv_amount', 'amount_number', 20, 110, 80, ['region' => 'advice', 'font_size' => 11, 'label' => 'Amount (PHP)']),
                $f('adv_purpose', 'purpose', 20, 120, 160, ['region' => 'advice', 'font_size' => 10, 'label' => 'Purpose', 'max_lines' => 3, 'uppercase' => false]),
                $f('adv_ref', 'reference', 20, 138, 100, ['region' => 'advice', 'font_size' => 10, 'label' => 'Reference']),
                $f('adv_by', 'prepared_by', 20, 148, 100, ['region' => 'advice', 'font_size' => 10, 'label' => 'Prepared by', 'uppercase' => false]),
                $f('adv_date', 'date', 20, 158, 60, ['region' => 'advice', 'font_size' => 10, 'label' => 'Date', 'format' => 'date_long']),
            ]],
        ]);

        PrintTemplate::firstOrCreate(['name' => 'Standard Receipt (A5)'], [
            'kind' => 'receipt', 'paper_width_mm' => 148, 'paper_height_mm' => 210,
            'layout' => ['fields' => [
                ['key' => 'org', 'source' => 'org_name', 'x' => 12, 'y' => 12, 'width' => 124, 'font_size' => 16, 'align' => 'center', 'uppercase' => true, 'max_lines' => 1, 'bold' => true, 'region' => 'receipt'],
                ['key' => 'addr', 'source' => 'org_address', 'x' => 12, 'y' => 21, 'width' => 124, 'font_size' => 9, 'align' => 'center', 'uppercase' => false, 'max_lines' => 2, 'region' => 'receipt'],
                ['key' => 'title', 'source' => 'free_text', 'text' => 'RECEIPT RECORD', 'x' => 12, 'y' => 36, 'width' => 124, 'font_size' => 13, 'align' => 'center', 'uppercase' => true, 'max_lines' => 1, 'bold' => true, 'region' => 'receipt'],
                ['key' => 'no', 'source' => 'receipt_no', 'label' => 'Receipt No.', 'x' => 12, 'y' => 50, 'width' => 124, 'font_size' => 11, 'align' => 'left', 'uppercase' => false, 'max_lines' => 1, 'region' => 'receipt'],
                ['key' => 'date', 'source' => 'date', 'label' => 'Date', 'format' => 'date_long', 'x' => 12, 'y' => 60, 'width' => 124, 'font_size' => 11, 'align' => 'left', 'uppercase' => false, 'max_lines' => 1, 'region' => 'receipt'],
                ['key' => 'vendor', 'source' => 'vendor', 'label' => 'Vendor / Payee', 'x' => 12, 'y' => 70, 'width' => 124, 'font_size' => 11, 'align' => 'left', 'uppercase' => false, 'max_lines' => 2, 'region' => 'receipt'],
                ['key' => 'desc', 'source' => 'description', 'label' => 'Description', 'x' => 12, 'y' => 85, 'width' => 124, 'font_size' => 11, 'align' => 'left', 'uppercase' => false, 'max_lines' => 4, 'region' => 'receipt'],
                ['key' => 'amt', 'source' => 'amount_number', 'label' => 'Amount (PHP)', 'x' => 12, 'y' => 115, 'width' => 124, 'font_size' => 12, 'align' => 'left', 'uppercase' => false, 'max_lines' => 1, 'bold' => true, 'region' => 'receipt'],
                ['key' => 'tax', 'source' => 'tax', 'label' => 'Tax', 'x' => 12, 'y' => 125, 'width' => 124, 'font_size' => 11, 'align' => 'left', 'uppercase' => false, 'max_lines' => 1, 'region' => 'receipt'],
                ['key' => 'by', 'source' => 'prepared_by', 'label' => 'Prepared by', 'x' => 12, 'y' => 180, 'width' => 124, 'font_size' => 10, 'align' => 'left', 'uppercase' => false, 'max_lines' => 1, 'region' => 'receipt'],
            ]],
        ]);
    }

    private function banks(): void
    {
        $bdo = Bank::firstOrCreate(['name' => 'BDO Unibank']);
        Bank::firstOrCreate(['name' => 'BPI']);
        BankAccount::firstOrCreate(['bank_id' => $bdo->id, 'account_number' => '011378005308'], [
            'branch' => 'BGC - 9th Avenue Branch',
            'account_name' => 'ECOVIS FINTAX PHILIPPINES CO',
            'account_reference' => 'BRSTN 01053-580-8',
            'cheque_format' => 'BDO Corporate Check Printing',
            'print_template_id' => PrintTemplate::where('kind', 'cheque')->value('id'),
            'starting_number' => '0000094658',
        ]);
    }
}
