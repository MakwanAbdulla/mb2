<?php

namespace App\Services\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\Budget;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\JournalEntry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountingReportService
{
    // ── Balance sheet ─────────────────────────────────────────────────────────

    public function balanceSheet(
        int     $fiscalYearId,
        string  $asOfDate,
        ?int    $costCenterId    = null,
        ?string $compareAsOfDate = null,
    ): array {
        $hasCompare = $compareAsOfDate !== null;

        $curr = $this->balanceSheetRows($fiscalYearId, $asOfDate, $costCenterId);
        $cmp  = $hasCompare
            ? $this->balanceSheetRows($fiscalYearId, $compareAsOfDate, $costCenterId)
            : collect();

        // Merge: all account ids from either period
        $merged = $curr->keys()->merge($cmp->keys())->unique()
            ->map(function ($id) use ($curr, $cmp, $hasCompare) {
                $base = $curr->get($id) ?? array_merge($cmp->get($id), ['net' => 0]);
                return array_merge($base, ['net_compare' => $hasCompare ? ($cmp->get($id)['net'] ?? 0) : null]);
            })
            ->filter(fn ($r) => $r['net'] !== 0 || ($r['net_compare'] ?? 0) !== 0)
            ->values();

        $assets      = $merged->where('type', 'asset')->sortBy('code');
        $equity      = $merged->where('type', 'equity')->sortBy('code');
        $liabilities = $merged->where('type', 'liability')->sortBy('code');

        $result        = $this->periodResult($fiscalYearId, $asOfDate, $costCenterId);
        $resultCompare = $hasCompare ? $this->periodResult($fiscalYearId, $compareAsOfDate, $costCenterId) : null;

        $assetGroups = $this->groupByBas($assets, [
            'anläggning' => ['label' => 'Anläggningstillgångar', 'fn' => fn ($c) => (int) substr($c, 0, 2) <= 14],
            'omsättning' => ['label' => 'Omsättningstillgångar', 'fn' => fn ($c) => true],
        ], $hasCompare);

        $equityGroup = [
            'label'          => 'Eget kapital',
            'rows'           => $equity->values()->all(),
            'result'         => $result,
            'result_compare' => $resultCompare,
            'total'          => $equity->sum('net') + $result,
            'total_compare'  => $hasCompare ? ($equity->sum('net_compare') + $resultCompare) : null,
        ];

        $liabilityGroups = $this->groupByBas($liabilities, [
            'avsättningar' => ['label' => 'Avsättningar',         'fn' => fn ($c) => (int) substr($c, 0, 2) === 22],
            'långfristiga' => ['label' => 'Långfristiga skulder', 'fn' => fn ($c) => (int) substr($c, 0, 2) === 23],
            'kortfristiga' => ['label' => 'Kortfristiga skulder', 'fn' => fn ($c) => true],
        ], $hasCompare);

        $totalAssets         = $assets->sum('net');
        $totalAssetsCompare  = $hasCompare ? $assets->sum('net_compare') : null;
        $totalLiab           = array_sum(array_column($liabilityGroups, 'total')) + $equityGroup['total'];
        $totalLiabCompare    = $hasCompare
            ? array_sum(array_column($liabilityGroups, 'total_compare')) + $equityGroup['total_compare']
            : null;

        return [
            'fiscal_year_id'     => $fiscalYearId,
            'as_of_date'         => $asOfDate,
            'compare_as_of_date' => $compareAsOfDate,
            'cost_center_id'     => $costCenterId,
            'last_vouchers'      => $this->lastVouchers($fiscalYearId, asOfDate: $asOfDate),
            'balanced'           => $totalAssets === $totalLiab,
            'asset_groups'       => $assetGroups,
            'equity_group'       => $equityGroup,
            'liability_groups'   => $liabilityGroups,
            'totals'             => [
                'assets'              => $totalAssets,
                'assets_compare'      => $totalAssetsCompare,
                'liabilities'         => $totalLiab,
                'liabilities_compare' => $totalLiabCompare,
            ],
        ];
    }

    private function balanceSheetRows(int $fiscalYearId, string $asOfDate, ?int $costCenterId): Collection
    {
        $ob = $costCenterId === null
            ? DB::table('opening_balances')
                ->where('fiscal_year_id', $fiscalYearId)
                ->pluck('amount', 'account_id')
            : collect();

        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.fiscal_year_id', $fiscalYearId)
            ->whereNull('journal_lines.superseded_at')
            ->where('accounts.is_group', false)
            ->whereIn('accounts.type', ['asset', 'liability', 'equity'])
            ->where('journal_entries.date', '<=', $asOfDate)
            ->when($costCenterId, fn ($q) => $q->where('journal_lines.cost_center_id', $costCenterId))
            ->selectRaw('accounts.id, accounts.code, accounts.name, accounts.type,
                         SUM(journal_lines.debit) as d, SUM(journal_lines.credit) as c')
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type')
            ->get()
            ->keyBy('id');

        // Pull in accounts that have only an opening balance (no journal lines this period)
        if ($ob->isNotEmpty()) {
            $missingIds = $ob->keys()->diff($rows->keys());
            if ($missingIds->isNotEmpty()) {
                DB::table('accounts')
                    ->whereIn('id', $missingIds)
                    ->where('is_group', false)
                    ->whereIn('type', ['asset', 'liability', 'equity'])
                    ->select('id', 'code', 'name', 'type')
                    ->get()
                    ->each(fn ($a) => $rows->put($a->id, (object) [
                        'id' => $a->id, 'code' => $a->code, 'name' => $a->name,
                        'type' => $a->type, 'd' => 0, 'c' => 0,
                    ]));
            }
        }

        return $rows->map(fn ($r) => [
            'id'   => $r->id,
            'code' => $r->code,
            'name' => $r->name,
            'type' => $r->type,
            'net'  => match ($r->type) {
                'asset'     =>  ($ob->get($r->id, 0)) + (int) $r->d - (int) $r->c,
                'liability' => -($ob->get($r->id, 0)) + (int) $r->c - (int) $r->d,
                'equity'    => -($ob->get($r->id, 0)) + (int) $r->c - (int) $r->d,
            },
        ]);
    }

    private function periodResult(int $fiscalYearId, string $asOfDate, ?int $costCenterId): int
    {
        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.fiscal_year_id', $fiscalYearId)
            ->whereNull('journal_lines.superseded_at')
            ->where('accounts.is_group', false)
            ->whereIn('accounts.type', ['income', 'expense'])
            ->where('journal_entries.date', '<=', $asOfDate)
            ->when($costCenterId, fn ($q) => $q->where('journal_lines.cost_center_id', $costCenterId))
            ->selectRaw('accounts.type, SUM(journal_lines.debit) as d, SUM(journal_lines.credit) as c')
            ->groupBy('accounts.type')
            ->get();

        $incomeNet  = $rows->where('type', 'income')->sum(fn ($r) => (int) $r->c - (int) $r->d);
        $expenseNet = $rows->where('type', 'expense')->sum(fn ($r) => (int) $r->d - (int) $r->c);

        return $incomeNet - $expenseNet;
    }

    private function groupByBas(Collection $accounts, array $groups, bool $hasCompare): array
    {
        $result  = [];
        $matched = collect();

        foreach ($groups as $key => $def) {
            $rows = $accounts
                ->filter(fn ($r) => ! $matched->contains($r['id']) && $def['fn']($r['code']))
                ->values();

            $matched = $matched->merge($rows->pluck('id'));

            if ($rows->isEmpty()) {
                continue;
            }

            $result[] = [
                'group'         => $key,
                'label'         => $def['label'],
                'rows'          => $rows->all(),
                'total'         => $rows->sum('net'),
                'total_compare' => $hasCompare ? $rows->sum(fn ($r) => (int) ($r['net_compare'] ?? 0)) : null,
            ];
        }

        return $result;
    }

    // ── Income statement ──────────────────────────────────────────────────────

    public function incomeStatement(
        int     $fiscalYearId,
        string  $dateFrom,
        string  $dateTo,
        ?int    $costCenterId    = null,
        ?string $compareDateFrom = null,
        ?string $compareDateTo   = null,
    ): array {
        $hasCompare = $compareDateFrom !== null && $compareDateTo !== null;

        $curr = $this->incomeRows($fiscalYearId, $dateFrom, $dateTo, $costCenterId);
        $cmp  = $hasCompare
            ? $this->incomeRows($fiscalYearId, $compareDateFrom, $compareDateTo, $costCenterId)
            : collect();

        $merge = function (Collection $rows, string $type, bool $financial) use ($cmp, $hasCompare): Collection {
            $filtered = $rows->filter(fn ($r) => $r['type'] === $type
                && ($financial ? substr($r['code'], 0, 1) === '8' : substr($r['code'], 0, 1) !== '8'));

            return $filtered->map(fn ($r) => [
                'code'        => $r['code'],
                'name'        => $r['name'],
                'net'         => $r['net'],
                'net_compare' => $hasCompare ? ($cmp->get($r['code'])['net'] ?? 0) : null,
            ])->values();
        };

        $opIncome  = $merge($curr, 'income',  false);
        $opExpense = $merge($curr, 'expense', false);
        $finIncome = $merge($curr, 'income',  true);
        $finExpense= $merge($curr, 'expense', true);

        $tot = fn (Collection $c, string $key): int => $c->sum($key);
        $cTot= fn (Collection $c): ?int => $hasCompare ? $c->sum('net_compare') : null;

        $totalOpIncome  = $tot($opIncome,  'net');
        $totalOpExpense = $tot($opExpense, 'net');
        $opResult       = $totalOpIncome - $totalOpExpense;
        $finNet         = $tot($finIncome, 'net') - $tot($finExpense, 'net');

        $totalOpIncCmp  = $cTot($opIncome);
        $totalOpExpCmp  = $cTot($opExpense);
        $opResultCmp    = $hasCompare ? ($totalOpIncCmp - $totalOpExpCmp) : null;
        $finNetCmp      = $hasCompare ? ($cTot($finIncome) - $cTot($finExpense)) : null;

        return [
            'fiscal_year_id'     => $fiscalYearId,
            'date_from'          => $dateFrom,
            'date_to'            => $dateTo,
            'compare_date_from'  => $compareDateFrom,
            'compare_date_to'    => $compareDateTo,
            'cost_center_id'     => $costCenterId,
            'last_vouchers'      => $this->lastVouchers($fiscalYearId, dateFrom: $dateFrom, dateTo: $dateTo),
            'op_income'          => $opIncome->all(),
            'op_expense'         => $opExpense->all(),
            'financial_income'   => $finIncome->all(),
            'financial_expense'  => $finExpense->all(),
            'totals'             => [
                'op_income'             => $totalOpIncome,
                'op_income_compare'     => $totalOpIncCmp,
                'op_expense'            => $totalOpExpense,
                'op_expense_compare'    => $totalOpExpCmp,
                'op_result'             => $opResult,
                'op_result_compare'     => $opResultCmp,
                'financial_net'         => $finNet,
                'financial_net_compare' => $finNetCmp,
                'result'                => $opResult + $finNet,
                'result_compare'        => $hasCompare ? ($opResultCmp + $finNetCmp) : null,
            ],
        ];
    }

    private function incomeRows(int $fiscalYearId, string $dateFrom, string $dateTo, ?int $costCenterId): Collection
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.fiscal_year_id', $fiscalYearId)
            ->whereNull('journal_lines.superseded_at')
            ->whereIn('accounts.type', ['income', 'expense'])
            ->where('accounts.is_group', false)
            ->where('journal_entries.date', '>=', $dateFrom)
            ->where('journal_entries.date', '<=', $dateTo)
            ->when($costCenterId, fn ($q) => $q->where('journal_lines.cost_center_id', $costCenterId))
            ->selectRaw('accounts.code, accounts.name, accounts.type,
                         SUM(journal_lines.debit) as total_debit,
                         SUM(journal_lines.credit) as total_credit')
            ->groupBy('accounts.code', 'accounts.name', 'accounts.type')
            ->orderBy('accounts.code')
            ->get()
            ->map(fn ($r) => [
                'code' => $r->code,
                'name' => $r->name,
                'type' => $r->type,
                'net'  => $r->type === 'income'
                    ? (int) $r->total_credit - (int) $r->total_debit
                    : (int) $r->total_debit  - (int) $r->total_credit,
            ])
            ->keyBy('code');
    }

    // ── General ledger ────────────────────────────────────────────────────────

    public function generalLedger(
        int    $fiscalYearId,
        int    $accountId,
        string $dateFrom,
        string $dateTo,
        ?int   $costCenterId = null,
    ): array {
        $account = Account::findOrFail($accountId);

        $obAmount = $costCenterId === null
            ? (int) DB::table('opening_balances')
                ->where('fiscal_year_id', $fiscalYearId)
                ->where('account_id', $accountId)
                ->value('amount')
            : 0;

        $openingRow = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $accountId)
            ->where('journal_entries.fiscal_year_id', $fiscalYearId)
            ->whereNull('journal_lines.superseded_at')
            ->where('journal_entries.date', '<', $dateFrom)
            ->when($costCenterId, fn ($q) => $q->where('journal_lines.cost_center_id', $costCenterId))
            ->selectRaw('SUM(journal_lines.debit) as d, SUM(journal_lines.credit) as c')
            ->first();

        $openingBalance = $obAmount + (int) ($openingRow->d ?? 0) - (int) ($openingRow->c ?? 0);

        $lines = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $accountId)
            ->where('journal_entries.fiscal_year_id', $fiscalYearId)
            ->whereNull('journal_lines.superseded_at')
            ->where('journal_entries.date', '>=', $dateFrom)
            ->where('journal_entries.date', '<=', $dateTo)
            ->when($costCenterId, fn ($q) => $q->where('journal_lines.cost_center_id', $costCenterId))
            ->select(
                'journal_lines.debit',
                'journal_lines.credit',
                'journal_lines.description as line_description',
                'journal_entries.date',
                'journal_entries.series',
                'journal_entries.number',
                'journal_entries.description as entry_description',
                'journal_entries.id as entry_id',
            )
            ->orderBy('journal_entries.date')
            ->orderBy('journal_entries.series')
            ->orderBy('journal_entries.number')
            ->get();

        $balance = $openingBalance;
        $rows    = [];

        foreach ($lines as $line) {
            $balance += (int) $line->debit - (int) $line->credit;
            $rows[] = [
                'date'        => $line->date,
                'ver'         => $line->series . $line->number,
                'entry_id'    => $line->entry_id,
                'description' => $line->line_description ?: $line->entry_description,
                'debit'       => (int) $line->debit,
                'credit'      => (int) $line->credit,
                'balance'     => $balance,
            ];
        }

        return [
            'fiscal_year_id'  => $fiscalYearId,
            'account_id'      => $accountId,
            'account_code'    => $account->code,
            'account_name'    => $account->name,
            'date_from'       => $dateFrom,
            'date_to'         => $dateTo,
            'cost_center_id'  => $costCenterId,
            'last_vouchers'   => $this->lastVouchers($fiscalYearId, dateFrom: $dateFrom, dateTo: $dateTo),
            'opening_balance' => $openingBalance,
            'rows'            => $rows,
            'period_totals'   => [
                'debit'   => array_sum(array_column($rows, 'debit')),
                'credit'  => array_sum(array_column($rows, 'credit')),
                'closing' => $balance,
            ],
        ];
    }

    // ── Budget variance ───────────────────────────────────────────────────────

    public function budgetVariance(
        int    $budgetId,
        string $dateFrom,
        string $dateTo,
        ?int   $costCenterId = null,
    ): array {
        $budget = Budget::with(['lines.account', 'fiscalYear'])->findOrFail($budgetId);

        $actuals = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.fiscal_year_id', $budget->fiscal_year_id)
            ->whereNull('journal_lines.superseded_at')
            ->whereIn('accounts.type', ['income', 'expense'])
            ->where('accounts.is_group', false)
            ->where('journal_entries.date', '>=', $dateFrom)
            ->where('journal_entries.date', '<=', $dateTo)
            ->when($costCenterId, fn ($q) => $q->where('journal_lines.cost_center_id', $costCenterId))
            ->selectRaw('accounts.id, SUM(journal_lines.debit) as total_debit, SUM(journal_lines.credit) as total_credit')
            ->groupBy('accounts.id')
            ->get()
            ->keyBy('id');

        $fyYear = $budget->fiscalYear?->starts_at->year ?? now()->year;
        $from   = Carbon::parse($dateFrom);
        $to     = Carbon::parse($dateTo);

        $monthsInRange = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthStart = Carbon::create($fyYear, $m, 1)->startOfMonth();
            $monthEnd   = $monthStart->copy()->endOfMonth();
            if ($monthEnd->gte($from) && $monthStart->lte($to)) {
                $monthsInRange[] = $m;
            }
        }

        $buildRows = function (string $type) use ($budget, $actuals, $monthsInRange) {
            return $budget->lines
                ->filter(fn ($l) => $l->account?->type === $type && $l->annualTotal() !== 0)
                ->map(function ($line) use ($type, $actuals, $monthsInRange) {
                    $budgetOre = (int) collect($monthsInRange)->sum(fn ($m) => $line->monthAmount($m));
                    $actual    = $actuals->get($line->account_id);

                    if ($type === 'income') {
                        $actualOre   = (int) ($actual?->total_credit ?? 0) - (int) ($actual?->total_debit ?? 0);
                        $varianceOre = $actualOre - $budgetOre;
                    } else {
                        $actualOre   = (int) ($actual?->total_debit ?? 0) - (int) ($actual?->total_credit ?? 0);
                        $varianceOre = $budgetOre - $actualOre;
                    }

                    $pct = $budgetOre !== 0 ? round($actualOre / $budgetOre * 100) : null;

                    return [
                        'code'         => $line->account?->code,
                        'name'         => $line->account?->name,
                        'budget_ore'   => $budgetOre,
                        'actual_ore'   => $actualOre,
                        'variance_ore' => $varianceOre,
                        'pct'          => $pct,
                    ];
                })
                ->sortBy('code')
                ->values();
        };

        $income  = $buildRows('income');
        $expense = $buildRows('expense');

        $netBudget   = $income->sum('budget_ore')   - $expense->sum('budget_ore');
        $netActual   = $income->sum('actual_ore')   - $expense->sum('actual_ore');
        $netVariance = $income->sum('variance_ore') - $expense->sum('variance_ore');

        return [
            'budget_id'      => $budget->id,
            'budget_name'    => $budget->name,
            'fiscal_year'    => $budget->fiscalYear?->name,
            'date_from'      => $dateFrom,
            'date_to'        => $dateTo,
            'cost_center_id' => $costCenterId,
            'last_vouchers'  => $this->lastVouchers($budget->fiscal_year_id, dateFrom: $dateFrom, dateTo: $dateTo),
            'income'         => $income,
            'expense'        => $expense,
            'totals'         => [
                'income'  => ['budget' => $income->sum('budget_ore'), 'actual' => $income->sum('actual_ore'), 'variance' => $income->sum('variance_ore')],
                'expense' => ['budget' => $expense->sum('budget_ore'), 'actual' => $expense->sum('actual_ore'), 'variance' => $expense->sum('variance_ore')],
                'net'     => ['budget' => $netBudget, 'actual' => $netActual, 'variance' => $netVariance],
            ],
        ];
    }

    // ── Journal list ──────────────────────────────────────────────────────────

    public function journalList(
        int     $fiscalYearId,
        string  $dateFrom,
        string  $dateTo,
        ?string $series       = null,
        ?int    $costCenterId = null,
    ): array {
        $entries = JournalEntry::where('fiscal_year_id', $fiscalYearId)
            ->when($series, fn ($q) => $q->where('series', $series))
            ->where('date', '>=', $dateFrom)
            ->where('date', '<=', $dateTo)
            ->when($costCenterId, fn ($q) => $q->whereIn('id',
                DB::table('journal_lines')
                    ->where('cost_center_id', $costCenterId)
                    ->whereNull('superseded_at')
                    ->select('journal_entry_id')
            ))
            ->addSelect('journal_entries.*')
            ->addSelect(DB::raw(
                '(SELECT SUM(debit)  FROM journal_lines WHERE journal_entry_id = journal_entries.id AND superseded_at IS NULL) AS total_debit'
            ))
            ->addSelect(DB::raw(
                '(SELECT SUM(credit) FROM journal_lines WHERE journal_entry_id = journal_entries.id AND superseded_at IS NULL) AS total_credit'
            ))
            ->addSelect(DB::raw(
                '(SELECT COUNT(*) FROM accounting_documents WHERE journal_entry_id = journal_entries.id) AS documents_count'
            ))
            ->with('createdBy')
            ->orderBy('date')
            ->orderBy('series')
            ->orderBy('number')
            ->get();

        $rows = $entries->map(fn ($e) => [
            'id'              => $e->id,
            'date'            => $e->date->toDateString(),
            'ver'             => $e->series . $e->number,
            'series'          => $e->series,
            'number'          => $e->number,
            'description'     => $e->description,
            'total_debit'     => (int) $e->total_debit,
            'total_credit'    => (int) $e->total_credit,
            'documents_count' => (int) $e->documents_count,
            'created_by'      => $e->createdBy?->name,
        ]);

        return [
            'fiscal_year_id' => $fiscalYearId,
            'date_from'      => $dateFrom,
            'date_to'        => $dateTo,
            'series'         => $series,
            'cost_center_id' => $costCenterId,
            'last_vouchers'  => $this->lastVouchers($fiscalYearId, dateFrom: $dateFrom, dateTo: $dateTo),
            'entries'        => $rows,
            'grand_totals'   => [
                'debit'  => $rows->sum('total_debit'),
                'credit' => $rows->sum('total_credit'),
            ],
        ];
    }

    // ── Vendor report ─────────────────────────────────────────────────────────

    public function vendorReport(int $fiscalYearId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $fiscalYear = FiscalYear::findOrFail($fiscalYearId);
        $dateFrom ??= $fiscalYear->starts_at->format('Y-m-d');
        $dateTo   ??= $fiscalYear->ends_at->format('Y-m-d');
        $today      = today()->format('Y-m-d');

        $rows = DB::table('invoices')
            ->join('vendors', 'vendors.id', '=', 'invoices.vendor_id')
            ->where('invoices.type', Invoice::TYPE_PAYABLE)
            ->where('invoices.status', '!=', Invoice::STATUS_CANCELLED)
            ->where('invoices.invoice_date', '>=', $dateFrom)
            ->where('invoices.invoice_date', '<=', $dateTo)
            ->selectRaw('vendors.id, vendors.code, vendors.name,
                         COUNT(*) as invoice_count,
                         SUM(invoices.total) as total_ore,
                         SUM(CASE WHEN invoices.status = ? THEN invoices.total ELSE 0 END) as paid_ore,
                         SUM(CASE WHEN invoices.status IN (?, ?) THEN invoices.total ELSE 0 END) as unpaid_ore,
                         SUM(CASE WHEN invoices.due_date < ? AND invoices.status NOT IN (?, ?) THEN 1 ELSE 0 END) as overdue_count',
                [
                    Invoice::STATUS_PAID,
                    Invoice::STATUS_APPROVED, Invoice::STATUS_POSTED,
                    $today,
                    Invoice::STATUS_PAID, Invoice::STATUS_CANCELLED,
                ])
            ->groupBy('vendors.id', 'vendors.code', 'vendors.name')
            ->orderBy('vendors.name')
            ->get()
            ->map(fn ($r) => [
                'id'             => $r->id,
                'code'           => $r->code,
                'name'           => $r->name,
                'invoice_count'  => (int) $r->invoice_count,
                'total_ore'      => (int) $r->total_ore,
                'paid_ore'       => (int) $r->paid_ore,
                'unpaid_ore'     => (int) $r->unpaid_ore,
                'overdue_count'  => (int) $r->overdue_count,
            ]);

        return [
            'fiscal_year_id' => $fiscalYearId,
            'date_from'      => $dateFrom,
            'date_to'        => $dateTo,
            'vendors'        => $rows->all(),
            'totals'         => [
                'invoice_count' => $rows->sum('invoice_count'),
                'total_ore'     => $rows->sum('total_ore'),
                'paid_ore'      => $rows->sum('paid_ore'),
                'unpaid_ore'    => $rows->sum('unpaid_ore'),
                'overdue_count' => $rows->sum('overdue_count'),
            ],
        ];
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    private function lastVouchers(
        int     $fiscalYearId,
        ?string $asOfDate = null,
        ?string $dateFrom = null,
        ?string $dateTo   = null,
    ): array {
        $q = DB::table('journal_entries')
            ->where('fiscal_year_id', $fiscalYearId)
            ->selectRaw('series, MAX(number) as last_number')
            ->groupBy('series')
            ->orderBy('series');

        if ($asOfDate) {
            $q->where('date', '<=', $asOfDate);
        } elseif ($dateFrom && $dateTo) {
            $q->whereBetween('date', [$dateFrom, $dateTo]);
        }

        return $q->get()
            ->map(fn ($r) => ['series' => $r->series, 'last' => $r->series . $r->last_number])
            ->all();
    }
}
