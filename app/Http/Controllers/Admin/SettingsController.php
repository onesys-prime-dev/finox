<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReceiptCategory;
use App\Services\ActivityLogService;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Admin/Settings', [
            'settings' => Settings::all(),
            'categories' => ReceiptCategory::withCount('receipts')->orderBy('name')->get(['id', 'name', 'is_active']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'system_name' => 'required|string|max:60', 'org_name' => 'required|string|max:255', 'org_address' => 'nullable|string|max:500',
            'org_contact' => 'nullable|string|max:255', 'date_format' => ['required', Rule::in(['M d, Y', 'd/m/Y', 'm/d/Y', 'Y-m-d'])],
            'currency_symbol' => 'required|string|max:5', 'max_upload_mb' => 'required|integer|min:1|max:50',
            'stale_days' => 'required|integer|min:30|max:730', 'retention_years' => 'required|integer|min:1|max:50', 'session_minutes' => 'required|integer|min:5|max:720',
            'lockout_attempts' => 'required|integer|min:3|max:20', 'lockout_minutes' => 'required|integer|min:1|max:240',
            'password_min_length' => 'required|integer|min:8|max:64',
            'notify_failed_prints' => 'boolean', 'notify_stale_cheques' => 'boolean',
            'print_offset_x_mm' => 'required|numeric|between:-20,20', 'print_offset_y_mm' => 'required|numeric|between:-20,20',
        ]);
        $old = array_intersect_key(Settings::all(), $d);
        foreach ($d as $k => $v) {
            Settings::set($k, $v);
        }
        $changed = array_filter($d, fn ($v, $k) => $old[$k] != $v, ARRAY_FILTER_USE_BOTH);
        if ($changed) {
            ActivityLogService::log('settings.updated', 'settings', 'Updated system settings', null, array_intersect_key($old, $changed), $changed);
        }

        return back()->with('success', 'Settings saved.');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $d = $request->validate(['name' => 'required|string|max:100|unique:receipt_categories,name']);
        $c = ReceiptCategory::create($d);
        ActivityLogService::log('category.created', 'settings', "Added receipt category {$c->name}", $c);

        return back()->with('success', 'Category added.');
    }

    /** Rename and/or enable-disable. The old name is kept in the activity log. */
    public function updateCategory(Request $request, ReceiptCategory $category): RedirectResponse
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $d = $request->validate(['name' => 'required|string|max:100|unique:receipt_categories,name,'.$category->id, 'is_active' => 'boolean']);
        $oldName = $category->name;
        $category->update($d);
        [$old, $new] = ActivityLogService::diff($category);
        $desc = $oldName !== $category->name ? "Renamed receipt category {$oldName} to {$category->name}" : "Updated receipt category {$category->name}";
        ActivityLogService::log('category.updated', 'settings', $desc, $category, $old, $new);

        return back()->with('success', 'Category saved.');
    }

    /** Only a category that no receipt has ever used can be removed; otherwise it is disabled instead. */
    public function destroyCategory(ReceiptCategory $category): RedirectResponse
    {
        if ($category->receipts()->exists()) {
            return back()->with('error', "'{$category->name}' is used by receipts and cannot be deleted. Disable it instead.");
        }
        ActivityLogService::log('category.deleted', 'settings', "Deleted receipt category {$category->name}", $category);
        $category->delete();

        return back()->with('success', 'Category deleted.');
    }
}
