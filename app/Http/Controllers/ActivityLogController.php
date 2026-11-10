<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Read-only for everyone, administrators included. */
class ActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasPermission('activity.view'), 403);

        $logs = ActivityLog::with('user:id,name')
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('description', 'like', "%{$s}%")->orWhere('subject_label', 'like', "%{$s}%")))
            ->when($request->module, fn ($q, $v) => $q->where('module', $v))
            ->when($request->user_id, fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->from, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when(! $request->boolean('archived'), fn ($q) => $q->where('created_at', '>=', \App\Support\Settings::retentionCutoff()))
            ->latest('id')->paginate(40)->withQueryString()
            ->through(fn ($l) => [
                'id' => $l->id, 'at' => $l->created_at->toDateTimeString(), 'user' => $l->user?->name ?? 'System', 'module' => $l->module,
                'action' => $l->action, 'description' => $l->description, 'subject' => $l->subject_label, 'subject_type' => $l->subject_type, 'subject_id' => $l->subject_id,
                'old' => $l->old_values, 'new' => $l->new_values, 'reason' => $l->reason, 'ip' => $l->ip,
            ]);

        return Inertia::render('Admin/ActivityLog', [
            'logs' => $logs, 'filters' => $request->only('search', 'module', 'user_id', 'from', 'to', 'archived'),
            'retentionYears' => (int) \App\Support\Settings::get('retention_years', 10),
            'modules' => ActivityLog::select('module')->distinct()->orderBy('module')->pluck('module'),
            'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
