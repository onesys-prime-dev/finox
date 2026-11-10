<?php

namespace App\Http\Controllers;

use App\Models\Payee;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayeeController extends Controller
{
    /** Search-as-you-type for forms. */
    public function search(Request $request): JsonResponse
    {
        $rows = Payee::where('is_active', true)->when($request->q, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('name')->limit(12)->get(['id', 'name', 'default_payee_line', 'tin']);

        return response()->json($rows);
    }

    /** Add a payee without leaving the form. */
    public function quickStore(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('payees.create') || $request->user()->hasPermission('payees.manage'), 403);
        $data = $request->validate(['name' => 'required|string|max:255', 'tin' => 'nullable|string|max:30', 'default_payee_line' => 'nullable|string|max:500']);
        $existing = Payee::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])->first();
        if ($existing) {
            return response()->json($existing);
        }
        $payee = Payee::create($data);
        ActivityLogService::log('payee.created', 'payees', "Added payee {$payee->name}", $payee);

        return response()->json($payee, 201);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasPermission('payees.manage'), 403);

        return Inertia::render('Payees/Index', [
            'payees' => Payee::when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))->orderBy('name')->paginate(30)->withQueryString(),
            'filters' => $request->only('search'),
        ]);
    }

    public function update(Request $request, Payee $payee): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('payees.manage'), 403);
        $data = $request->validate(['name' => 'required|string|max:255', 'tin' => 'nullable|string|max:30', 'default_payee_line' => 'nullable|string|max:500', 'is_active' => 'boolean']);
        $payee->update($data);
        [$old, $new] = ActivityLogService::diff($payee);
        if ($new) {
            ActivityLogService::log('payee.updated', 'payees', "Updated payee {$payee->name}", $payee, $old, $new);
        }

        return back()->with('success', 'Payee saved.');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('payees.manage'), 403);
        $data = $request->validate(['name' => 'required|string|max:255', 'tin' => 'nullable|string|max:30', 'default_payee_line' => 'nullable|string|max:500']);
        $p = Payee::create($data);
        ActivityLogService::log('payee.created', 'payees', "Added payee {$p->name}", $p);

        return back()->with('success', 'Payee added.');
    }
}
