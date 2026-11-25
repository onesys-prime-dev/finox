<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Cheque;
use App\Models\Receipt;
use App\Models\StatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ActivityLogService
{
    /**
     * Write an audit entry. Call this INSIDE the same DB transaction as the change it records,
     * so that the change and its log entry succeed or fail together.
     */
    public static function log(
        string $action,
        string $module,
        string $description,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        ?string $reason = null,
        ?User $user = null,
        ?string $label = null,
    ): ActivityLog {
        $user ??= auth()->user();

        return ActivityLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'module' => $module,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'subject_label' => $label ?? self::labelFor($subject),
            'description' => $description,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'reason' => $reason,
            'ip' => request()?->ip(),
        ]);
    }

    /** Record a status change in the history table. */
    public static function status(Model $subject, ?string $from, string $to, ?string $reason = null, ?User $user = null): void
    {
        StatusHistory::create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'user_id' => ($user ?? auth()->user())?->id,
        ]);
    }

    /** Old/new arrays containing only attributes that actually changed. */
    public static function diff(Model $model, array $skip = ['updated_at', 'updated_by']): array
    {
        $old = $new = [];
        foreach ($model->getChanges() as $k => $v) {
            if (in_array($k, $skip, true)) {
                continue;
            }
            $o = $model->getOriginal($k);
            $old[$k] = $o instanceof \BackedEnum ? $o->value : (is_object($o) && method_exists($o, 'toDateString') ? $o->toDateString() : $o);
            $new[$k] = $v instanceof \BackedEnum ? $v->value : $v;
        }

        return [$old, $new];
    }

    private static function labelFor(?Model $m): ?string
    {
        return match (true) {
            $m === null => null,
            $m instanceof Cheque => 'Cheque '.$m->cheque_number,
            $m instanceof Receipt => 'Receipt '.($m->receipt_number ?: '#'.$m->id),
            $m instanceof User => $m->name,
            default => class_basename($m).' #'.$m->getKey(),
        };
    }
}
