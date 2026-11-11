<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Http\Resources\JournalEntryResource;
use App\Models\Accounting\Account;
use App\Models\Accounting\CostCenter;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Accounting\JournalSeries;
use App\Models\Accounting\OpeningBalance;
use App\Models\AuditLog;
use App\Services\Accounting\Sie4Exporter;
use App\Services\Accounting\Sie4Importer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AccountingController extends Controller
{
    // GET /accounting/fiscal-years
    public function fiscalYears(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['accounting.view', 'accounting.manage']), 403);

        $years = FiscalYear::orderByDesc('starts_at')->get()->map(fn ($y) => [
            'id'        => $y->id,
            'name'      => $y->name,
            'starts_at' => $y->starts_at->toDateString(),
            'ends_at'   => $y->ends_at->toDateString(),
            'is_locked' => $y->is_locked,
        ]);

        return response()->json(['data' => $years]);
    }

    // GET /accounting/accounts
    public function accounts(Request $request): ResourceCollection
    {
        abort_unless($request->user()->hasAnyPermission(['accounting.view', 'accounting.manage']), 403);

        $accounts = Account::query()
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->boolean('active_only', false), fn ($q) => $q->where('is_active', true))
            ->orderBy('number')
            ->get();

        return AccountResource::collection($accounts);
    }

    // GET /accounting/journal-entries
    public function journalEntries(Request $request): ResourceCollection
    {
        abort_unless($request->user()->hasAnyPermission(['accounting.view', 'accounting.manage']), 403);

        $entries = JournalEntry::query()
            ->when($request->input('fiscal_year_id'), fn ($q, $v) => $q->where('fiscal_year_id', $v))
            ->when($request->input('from'), fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($request->input('to'), fn ($q, $v) => $q->where('date', '<=', $v))
            ->orderByDesc('date')
            ->orderByDesc('number')
            ->paginate($request->integer('per_page', 50));

        return JournalEntryResource::collection($entries);
    }

    // GET /accounting/journal-entries/{entry}
    public function showJournalEntry(Request $request, JournalEntry $journalEntry): JournalEntryResource
    {
        abort_unless($request->user()->hasAnyPermission(['accounting.view', 'accounting.manage']), 403);

        $journalEntry->load('lines.account');

        return new JournalEntryResource($journalEntry);
    }

    // POST /accounting/accounts
    public function storeAccount(Request $request): AccountResource
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'code'       => 'required|string|max:10|unique:accounts,code',
            'name'       => 'required|string|max:255',
            'type'       => 'required|in:asset,liability,equity,income,expense',
            'vat_code'   => 'nullable|string|max:10',
            'is_group'   => 'boolean',
            'is_active'  => 'boolean',
            'parent_id'  => 'nullable|exists:accounts,id',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $account = Account::create($data);

        AuditLog::record('account_created', 'accounting', $account->code . ' ' . $account->name, $account);

        return new AccountResource($account);
    }

    // PATCH /accounting/accounts/{account}
    public function updateAccount(Request $request, Account $account): AccountResource
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'code'       => 'sometimes|string|max:10|unique:accounts,code,' . $account->id,
            'name'       => 'sometimes|string|max:255',
            'type'       => 'sometimes|in:asset,liability,equity,income,expense',
            'vat_code'   => 'nullable|string|max:10',
            'is_group'   => 'boolean',
            'is_active'  => 'boolean',
            'parent_id'  => 'nullable|exists:accounts,id',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $account->update($data);

        AuditLog::record('account_updated', 'accounting', $account->code . ' ' . $account->name, $account);

        return new AccountResource($account->fresh());
    }

    // DELETE /accounting/accounts/{account}
    public function destroyAccount(Request $request, Account $account): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $hasLines = $account->journalLines()->exists();
        abort_if($hasLines, 422, 'Cannot delete account: it has journal lines.');

        $label = $account->code . ' ' . $account->name;
        $account->delete();

        AuditLog::record('account_deleted', 'accounting', $label);

        return response()->json(null, 204);
    }

    // GET /accounting/fiscal-years/{fiscalYear}/opening-balances
    public function openingBalances(Request $request, FiscalYear $fiscalYear): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['accounting.view', 'accounting.manage']), 403);

        $balances = OpeningBalance::with('account')
            ->where('fiscal_year_id', $fiscalYear->id)
            ->get()
            ->map(fn ($b) => [
                'id'             => $b->id,
                'fiscal_year_id' => $b->fiscal_year_id,
                'account_id'     => $b->account_id,
                'account_code'   => $b->account?->code,
                'account_name'   => $b->account?->name,
                'amount'         => $b->amount,
                'updated_at'     => $b->updated_at->toIso8601String(),
            ]);

        return response()->json(['data' => $balances]);
    }

    // PUT /accounting/fiscal-years/{fiscalYear}/opening-balances
    public function saveOpeningBalances(Request $request, FiscalYear $fiscalYear): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'balances'                => 'required|array',
            'balances.*.account_id'   => 'required|exists:accounts,id',
            'balances.*.amount'       => 'required|integer',
        ]);

        DB::transaction(function () use ($data, $request, $fiscalYear) {
            foreach ($data['balances'] as $row) {
                OpeningBalance::updateOrCreate(
                    ['fiscal_year_id' => $fiscalYear->id, 'account_id' => $row['account_id']],
                    [
                        'amount'     => $row['amount'],
                        'created_by' => $request->user()->id,
                        'updated_by' => $request->user()->id,
                    ]
                );
            }
        });

        AuditLog::record('opening_balances_saved', 'accounting', 'Ingående saldon ' . $fiscalYear->name);

        return response()->json(['message' => 'Opening balances saved.']);
    }

    // POST /accounting/journal-entries
    public function storeJournalEntry(Request $request): JournalEntryResource
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $data = $request->validate([
            'fiscal_year_id' => 'required|exists:fiscal_years,id',
            'date'           => 'required|date',
            'description'    => 'required|string|max:500',
            'series'         => 'nullable|string|max:10',
            'lines'          => 'required|array|min:2',
            'lines.*.account_id'  => 'required|exists:accounts,id',
            'lines.*.debit'       => 'required|integer|min:0',
            'lines.*.credit'      => 'required|integer|min:0',
            'lines.*.description' => 'nullable|string|max:255',
            'lines.*.cost_center_id' => 'nullable|exists:cost_centers,id',
        ]);

        $totalDebit  = array_sum(array_column($data['lines'], 'debit'));
        $totalCredit = array_sum(array_column($data['lines'], 'credit'));
        abort_unless($totalDebit === $totalCredit, 422, 'Journal entry must balance: total debit must equal total credit.');

        $entry = DB::transaction(function () use ($data, $request) {
            $series = $data['series'] ?? 'A';
            $number = (JournalEntry::where('fiscal_year_id', $data['fiscal_year_id'])
                ->where('series', $series)->max('number') ?? 0) + 1;

            $entry = JournalEntry::create([
                'fiscal_year_id' => $data['fiscal_year_id'],
                'date'           => $data['date'],
                'description'    => $data['description'],
                'series'         => $series,
                'number'         => $number,
                'created_by'     => $request->user()->id,
            ]);

            foreach ($data['lines'] as $line) {
                $entry->lines()->create($line);
            }

            return $entry;
        });

        AuditLog::record('journal_entry_created', 'accounting', $entry->description, $entry);

        return new JournalEntryResource($entry->load('lines.account'));
    }

    // GET /accounting/fiscal-years/{fiscalYear}/sie4
    public function exportSie4(Request $request, FiscalYear $fiscalYear): Response
    {
        abort_unless($request->user()->hasPermissionTo('accounting.admin'), 403);

        $exporter = new Sie4Exporter($fiscalYear);
        $content  = $exporter->generate();
        $filename = $exporter->filename();

        return response($content, 200, [
            'Content-Type'        => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length'      => strlen($content),
        ]);
    }

    // POST /accounting/fiscal-years/{fiscalYear}/sie4
    public function importSie4(Request $request, FiscalYear $fiscalYear): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.admin'), 403);

        $request->validate([
            'file'                      => 'required|file|mimes:si,sie,txt,csv|max:20480',
            'create_accounts'           => 'boolean',
            'import_opening_balances'   => 'boolean',
            'import_entries'            => 'boolean',
            'duplicate_handling'        => 'string|in:skip,overwrite',
        ]);

        $raw  = file_get_contents($request->file('file')->getRealPath());
        $utf8 = Sie4Importer::decodeToUtf8($raw);

        $importer = new Sie4Importer();
        $doc      = $importer->parse($utf8);

        $options = [
            'create_accounts'    => $request->boolean('create_accounts', true),
            'import_ib'          => $request->boolean('import_opening_balances', true),
            'import_entries'     => $request->boolean('import_entries', true),
            'duplicate_handling' => $request->input('duplicate_handling', 'skip'),
        ];

        $result = $importer->import($doc, $fiscalYear, $options);

        AuditLog::record(
            'sie4_imported',
            'accounting',
            $fiscalYear->name . ': ' . ($result['entries_imported'] ?? 0) . ' verifikationer',
            null
        );

        return response()->json(['data' => $result]);
    }

    // GET /accounting/dashboard
    public function dashboard(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.view'), 403);

        $stats = [
            'accounts_active'   => Account::where('is_active', true)->count(),
            'accounts_total'    => Account::count(),
            'cost_centers'      => CostCenter::where('is_active', true)->count(),
            'series'            => JournalSeries::count(),
            'missing_receipts'  => JournalEntry::where('requires_receipt', true)
                ->whereDoesntHave('documents')
                ->count(),
            'open_fiscal_years' => FiscalYear::where('is_locked', false)->count(),
        ];

        return response()->json(['data' => ['stats' => $stats]]);
    }
}
