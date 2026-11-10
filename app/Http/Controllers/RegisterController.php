<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\RegisterQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RegisterController extends Controller
{
    private const KEYS = ['search', 'type', 'status', 'from', 'to', 'min', 'max', 'bank_account_id', 'user_id', 'sort', 'dir', 'per_page'];

    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasPermission('register.view'), 403);
        $f = $request->only(self::KEYS);
        $per = in_array((int) $request->per_page, [25, 50, 100], true) ? (int) $request->per_page : 25;
        $q = new RegisterQuery($f);

        return Inertia::render('Register/Index', [
            'rows' => $q->paginate(max(1, (int) $request->page), $per, route('register.index'), $f),
            // Cheque and receipt totals are always reported separately.
            'totals' => $q->totals(),
            'filters' => $f,
            'accounts' => BankAccount::with('bank:id,name')->get()->map(fn ($a) => ['id' => $a->id, 'label' => $a->display_name]),
            'users' => User::orderBy('name')->get(['id', 'name']),
            'savedFilters' => $request->user()->savedFilters('register'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()->hasPermission('register.view'), 403);
        $q = new RegisterQuery($request->only(self::KEYS));
        ActivityLogService::log('report.exported', 'reports', 'Exported transaction register (CSV)');

        return response()->streamDownload(function () use ($q) {
            $o = fopen('php://output', 'w');
            fwrite($o, "\xEF\xBB\xBF");
            fputcsv($o, ['Transaction ID', 'Type', 'Reference', 'Payee/Vendor', 'Date', 'Amount', 'Status', 'Created']);
            foreach ($q->cursor() as $r) {
                fputcsv($o, [$r->transaction_id, $r->type, $r->reference_number, $r->party, $r->transaction_date, $r->amount, $r->status, $r->created_at]);
            }
            fclose($o);
        }, 'transaction-register-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
