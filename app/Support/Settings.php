<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class Settings
{
    public const DEFAULTS = [
        'system_name' => 'FINOX',
        'org_name' => 'Your Organization',
        'org_address' => '',
        'org_contact' => '',
        'date_format' => 'M d, Y',
        'currency_symbol' => '₱',
        'max_upload_mb' => 10,
        'stale_days' => 180,
        'retention_years' => 10,
        'session_minutes' => 60,
        'lockout_attempts' => 5,
        'lockout_minutes' => 15,
        'password_min_length' => 10,
        'notify_failed_prints' => true,
        'notify_stale_cheques' => true,
        'print_offset_x_mm' => 0,
        'print_offset_y_mm' => 0,
    ];

    public static function all(): array
    {
        $stored = Cache::rememberForever('finox.settings', function () {
            try {
                return SystemSetting::query()->pluck('value', 'key')
                    ->map(fn ($v) => json_decode($v, true))->all();
            } catch (\Throwable) {
                return [];
            }
        });

        return array_merge(self::DEFAULTS, $stored);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    /** Records older than this are "archived": hidden from default lists, never deleted. */
    public static function retentionCutoff(): \Illuminate\Support\Carbon
    {
        return now()->subYears(max(1, (int) self::get('retention_years', 10)));
    }

    public static function set(string $key, mixed $value): void
    {
        SystemSetting::updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
        self::flush();
    }

    public static function flush(): void
    {
        Cache::forget('finox.settings');
    }
}
