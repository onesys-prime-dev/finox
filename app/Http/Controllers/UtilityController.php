<?php

namespace App\Http\Controllers;

use App\Models\Cheque;
use App\Models\ProblemReport;
use App\Models\Receipt;
use App\Models\SavedFilter;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Global search, saved filters and the "Report a problem" link. */
class UtilityController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->q);
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }
        $u = $request->user();
        $out = [];
        if ($u->hasPermission('cheques.view') || $u->hasPermission('cheques.view_all')) {
            Cheque::visibleTo($u)->with('payee:id,name')->where(fn ($w) => $w->where('cheque_number', 'like', "%{$q}%")->orWhere('reference_no', 'like', "%{$q}%")
                ->orWhereHas('payee', fn ($p) => $p->where('name', 'like', "%{$q}%")))->latest('id')->limit(6)->get()
                ->each(function ($c) use (&$out) {
                    $out[] = ['type' => 'Cheque', 'title' => $c->cheque_number, 'sub' => $c->printed_payee.' · '.Money::format($c->amount), 'status' => $c->status->value, 'url' => route('cheques.show', $c)];
                });
        }
        if ($u->hasPermission('receipts.view') || $u->hasPermission('receipts.view_all')) {
            Receipt::visibleTo($u)->with('payee:id,name')->where(fn ($w) => $w->where('receipt_number', 'like', "%{$q}%")->orWhere('reference_no', 'like', "%{$q}%")
                ->orWhereHas('payee', fn ($p) => $p->where('name', 'like', "%{$q}%")))->latest('id')->limit(6)->get()
                ->each(function ($r) use (&$out) {
                    $out[] = ['type' => 'Receipt', 'title' => $r->receipt_number ?: 'Receipt #'.$r->id, 'sub' => ($r->payee?->name ?? '—').' · '.Money::format($r->amount), 'status' => $r->status->value, 'url' => route('receipts.show', $r)];
                });
        }

        return response()->json($out);
    }

    public function saveFilter(Request $request): RedirectResponse
    {
        $d = $request->validate(['scope' => 'required|in:cheques,receipts,register', 'name' => 'required|string|max:60', 'filters' => 'required|array']);
        SavedFilter::updateOrCreate(['user_id' => $request->user()->id, 'scope' => $d['scope'], 'name' => $d['name']], ['filters' => $d['filters']]);

        return back()->with('success', 'Filter saved.');
    }

    public function deleteFilter(Request $request, SavedFilter $filter): RedirectResponse
    {
        abort_unless($filter->user_id === $request->user()->id, 403);
        $filter->delete();

        return back();
    }

    public function reportProblem(Request $request): RedirectResponse
    {
        $d = $request->validate(['message' => 'required|string|min:5|max:2000', 'url' => 'required|string|max:500']);
        ProblemReport::create($d + ['user_id' => $request->user()->id]);

        return back()->with('success', 'Thanks — the problem was reported to an administrator.');
    }

    public function problems(Request $request): Response
    {
        abort_unless($request->user()->isAdmin(), 403);

        return Inertia::render('Admin/Problems', [
            'reports' => ProblemReport::with('user:id,name')->orderBy('is_resolved')->latest('id')->paginate(30)
                ->through(fn ($p) => ['id' => $p->id, 'user' => $p->user?->name, 'url' => $p->url, 'message' => $p->message, 'is_resolved' => $p->is_resolved, 'at' => $p->created_at->toDateTimeString()]),
        ]);
    }

    public function resolveProblem(Request $request, ProblemReport $problem): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $problem->update(['is_resolved' => ! $problem->is_resolved]);

        return back();
    }
}
