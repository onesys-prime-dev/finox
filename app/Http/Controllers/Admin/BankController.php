<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\PrintTemplate;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BankController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Banks', [
            'banks' => Bank::with(['accounts' => fn ($q) => $q->withCount('cheques')->with('template:id,name')])->orderBy('name')->get()->map(fn ($b) => [
                'id' => $b->id, 'name' => $b->name, 'remarks' => $b->remarks, 'is_active' => $b->is_active,
                'accounts' => $b->accounts->map(fn ($a) => [
                    'id' => $a->id, 'branch' => $a->branch, 'account_name' => $a->account_name, 'account_number' => $a->account_number,
                    'account_reference' => $a->account_reference, 'cheque_format' => $a->cheque_format, 'print_template_id' => $a->print_template_id,
                    'template' => $a->template?->name, 'starting_number' => $a->starting_number, 'is_active' => $a->is_active, 'remarks' => $a->remarks,
                    'cheques_count' => $a->cheques_count,
                ]),
            ]),
            'templates' => PrintTemplate::where('kind', 'cheque')->where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function storeBank(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:255|unique:banks,name', 'remarks' => 'nullable|string|max:255']);
        $bank = Bank::create($data);
        ActivityLogService::log('bank.created', 'banks', "Added bank {$bank->name}", $bank);

        return back()->with('success', 'Bank added.');
    }

    public function updateBank(Request $request, Bank $bank): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:255|unique:banks,name,'.$bank->id, 'remarks' => 'nullable|string|max:255', 'is_active' => 'boolean']);
        $bank->update($data);
        [$old, $new] = ActivityLogService::diff($bank);
        ActivityLogService::log('bank.updated', 'banks', "Updated bank {$bank->name}", $bank, $old, $new);

        return back()->with('success', 'Bank saved.');
    }

    public function storeAccount(Request $request, Bank $bank): RedirectResponse
    {
        $data = $this->account($request);
        $acct = DB::transaction(function () use ($bank, $data) {
            $a = $bank->accounts()->create($data);
            ActivityLogService::log('bank_account.created', 'banks', "Added account {$a->account_number} to {$bank->name}", $a);

            return $a;
        });

        return back()->with('success', "Account {$acct->account_number} added.");
    }

    public function updateAccount(Request $request, BankAccount $account): RedirectResponse
    {
        $data = $this->account($request, $account);
        DB::transaction(function () use ($account, $data) {
            $account->update($data);
            [$old, $new] = ActivityLogService::diff($account);
            if ($new) {
                ActivityLogService::log('bank_account.updated', 'banks', "Updated account {$account->account_number}", $account, $old, $new);
            }
        });

        return back()->with('success', 'Account saved.');
    }

    private function account(Request $request, ?BankAccount $existing = null): array
    {
        return $request->validate([
            'branch' => 'nullable|string|max:255', 'account_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:50', 'account_reference' => 'nullable|string|max:255',
            'cheque_format' => 'nullable|string|max:255', 'print_template_id' => 'nullable|exists:print_templates,id',
            'starting_number' => 'nullable|string|max:20', 'is_active' => 'boolean', 'remarks' => 'nullable|string|max:1000',
        ]);
    }
}
