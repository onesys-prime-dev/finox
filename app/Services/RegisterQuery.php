<?php

namespace App\Services;

use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Unified cheque + receipt list WITHOUT a separate editable copy.
 * Each source table is queried with its own indexes and the results are merged, which is much faster
 * than ordering/paging a UNION view (the view `transaction_register` remains for ad-hoc SQL/reporting).
 */
class RegisterQuery
{
    private const SORTS = ['transaction_date', 'amount', 'reference_number', 'party', 'status', 'type', 'created_at'];

    public function __construct(private array $f = []) {}

    private function has(string $k): bool
    {
        return isset($this->f[$k]) && $this->f[$k] !== '' && $this->f[$k] !== null;
    }

    private function chequeQuery(): ?Builder
    {
        if ($this->has('type') && $this->f['type'] !== 'cheque') {
            return null;
        }
        $q = DB::table('cheques as c')->join('payees as p', 'p.id', '=', 'c.payee_id')
            ->select('c.id as record_id', 'c.cheque_number as reference_number', 'p.name as party', 'c.cheque_date as transaction_date',
                'c.amount', 'c.status', 'c.bank_account_id', 'c.created_by', 'c.created_at');
        $this->has('status') && $q->where('c.status', $this->f['status']);
        $this->has('from') && $q->whereDate('c.cheque_date', '>=', $this->f['from']);
        $this->has('to') && $q->whereDate('c.cheque_date', '<=', $this->f['to']);
        $this->has('min') && $q->where('c.amount', '>=', $this->f['min']);
        $this->has('max') && $q->where('c.amount', '<=', $this->f['max']);
        $this->has('bank_account_id') && $q->where('c.bank_account_id', $this->f['bank_account_id']);
        $this->has('user_id') && $q->where('c.created_by', $this->f['user_id']);
        if ($this->has('search')) {
            $s = trim($this->f['search']);
            $q->where(function ($w) use ($s) {
                $w->where('c.cheque_number', 'like', "%{$s}%")->orWhere('c.reference_no', 'like', "%{$s}%")->orWhere('p.name', 'like', "%{$s}%");
                if (preg_match('/^CHQ-(\d+)$/i', $s, $m)) {
                    $w->orWhere('c.id', (int) $m[1]);
                }
            });
        }

        return $q;
    }

    private function receiptQuery(): ?Builder
    {
        if (($this->has('type') && $this->f['type'] !== 'receipt') || $this->has('bank_account_id')) {
            return null; // receipts have no bank account
        }
        $q = DB::table('receipts as r')->leftJoin('payees as p', 'p.id', '=', 'r.payee_id')
            ->select('r.id as record_id', 'r.receipt_number as reference_number', 'p.name as party', 'r.receipt_date as transaction_date',
                'r.amount', 'r.status', DB::raw('NULL as bank_account_id'), 'r.created_by', 'r.created_at');
        $this->has('status') && $q->where('r.status', $this->f['status']);
        $this->has('from') && $q->whereDate('r.receipt_date', '>=', $this->f['from']);
        $this->has('to') && $q->whereDate('r.receipt_date', '<=', $this->f['to']);
        $this->has('min') && $q->where('r.amount', '>=', $this->f['min']);
        $this->has('max') && $q->where('r.amount', '<=', $this->f['max']);
        $this->has('user_id') && $q->where('r.created_by', $this->f['user_id']);
        if ($this->has('search')) {
            $s = trim($this->f['search']);
            $q->where(function ($w) use ($s) {
                $w->where('r.receipt_number', 'like', "%{$s}%")->orWhere('r.reference_no', 'like', "%{$s}%")->orWhere('p.name', 'like', "%{$s}%");
                if (preg_match('/^RCP-(\d+)$/i', $s, $m)) {
                    $w->orWhere('r.id', (int) $m[1]);
                }
            });
        }

        return $q;
    }

    private function sortColumns(): array
    {
        $sort = in_array($this->f['sort'] ?? '', self::SORTS, true) ? $this->f['sort'] : 'created_at';
        $map = [
            'transaction_date' => ['c.cheque_date', 'r.receipt_date'], 'amount' => ['c.amount', 'r.amount'],
            'reference_number' => ['c.cheque_number', 'r.receipt_number'], 'party' => ['p.name', 'p.name'],
            'status' => ['c.status', 'r.status'], 'created_at' => ['c.created_at', 'r.created_at'], 'type' => ['c.id', 'r.id'],
        ];

        return [$sort, $map[$sort]];
    }

    private function decorate(object $r, string $type): object
    {
        $r->type = $type;
        $r->transaction_id = ($type === 'cheque' ? 'CHQ-' : 'RCP-').$r->record_id;

        return $r;
    }

    /** Merge-sorted slice for a page. */
    public function paginate(int $page, int $per, string $path, array $query): LengthAwarePaginator
    {
        [$sort, [$cCol, $rCol]] = $this->sortColumns();
        $dir = ($this->f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $need = $page * $per;
        $rows = collect();
        $total = 0;

        foreach ([['cheque', $this->chequeQuery(), $cCol], ['receipt', $this->receiptQuery(), $rCol]] as [$type, $q, $col]) {
            if (! $q) {
                continue;
            }
            $total += (clone $q)->getCountForPagination();
            $q->orderBy($col, $dir)->limit($need);
            $rows = $rows->concat($q->get()->map(fn ($r) => $this->decorate($r, $type)));
        }

        $key = $sort === 'type' ? 'type' : $sort;
        $sorted = $rows->sort(function ($a, $b) use ($key, $dir) {
            $x = $a->{$key} ?? '';
            $y = $b->{$key} ?? '';
            $c = $key === 'amount' ? ((float) $x <=> (float) $y) : strcasecmp((string) $x, (string) $y);
            if ($c === 0) {
                $c = strcmp($a->created_at ?? '', $b->created_at ?? '');
            }

            return $dir === 'asc' ? $c : -$c;
        })->values();

        return new LengthAwarePaginator($sorted->slice(($page - 1) * $per, $per)->values(), $total, $per, $page, ['path' => $path, 'query' => $query]);
    }

    /** Cheque and receipt totals, always separate. */
    public function totals(): array
    {
        $out = ['cheque' => ['count' => 0, 'amount' => '0.00'], 'receipt' => ['count' => 0, 'amount' => '0.00']];
        foreach (['cheque' => $this->chequeQuery(), 'receipt' => $this->receiptQuery()] as $type => $q) {
            if (! $q) {
                continue;
            }
            $r = (clone $q)->cloneWithout(['columns', 'orders'])->cloneWithoutBindings(['select'])->selectRaw('count(*) c, sum('.($type === 'cheque' ? 'c' : 'r').'.amount) s')->first();
            $out[$type] = ['count' => (int) $r->c, 'amount' => Money::sum([$r->s ?? 0])];
        }

        return $out;
    }

    /** Everything, streamed, for CSV export. */
    public function cursor(): \Generator
    {
        foreach (['cheque' => $this->chequeQuery(), 'receipt' => $this->receiptQuery()] as $type => $q) {
            if (! $q) {
                continue;
            }
            foreach ($q->orderBy($type === 'cheque' ? 'c.id' : 'r.id')->cursor() as $r) {
                yield $this->decorate($r, $type);
            }
        }
    }

    public function recent(int $n = 6): Collection
    {
        return (new self)->paginate(1, $n, '', [])->getCollection();
    }
}
