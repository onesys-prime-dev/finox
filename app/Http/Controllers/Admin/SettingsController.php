<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReceiptCategory;
use App\Services\ActivityLogService;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Admin/Settings', [
            'settings' => Settings::all(),
            'categories' => ReceiptCategory::orderBy('name')->get(['id', 'name', 'is_active']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'system_name' => 'required|string|max:60', 'org_name' => 'required|string|max:255', 'org_address' => 'nullable|string|max:500',
            'org_contact' => 'nullable|string|max:255', 'date_format' => 'required|in:M d, Y,d/m/Y,m/d/Y,Y-m-d',
            'currency_symbol' => 'required|string|max:5', 'max_upload_mb' => 'required|integer|min:1|max:50',
            'stale_days' => 'required|integer|min:30|max:730', 'session_minutes' => 'required|integer|min:5|max:720',
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
        $d = $request->validate(['name' => 'required|string|max:100|unique:receipt_categories,name']);
        $c = ReceiptCategory::create($d);
        ActivityLogService::log('category.created', 'settings', "Added receipt category {$c->name}", $c);

        return back()->with('success', 'Category added.');
    }

    public function updateCategory(Request $request, ReceiptCategory $category): RedirectResponse
    {
        $d = $request->validate(['name' => 'required|string|max:100|unique:receipt_categories,name,'.$category->id, 'is_active' => 'boolean']);
        $category->update($d);
        ActivityLogService::log('category.updated', 'settings', "Updated receipt category {$category->name}", $category);

        return back()->with('success', 'Category saved.');
    }
}
