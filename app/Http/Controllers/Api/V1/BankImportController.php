<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingDocument;
use App\Models\Accounting\BankAccount;
use App\Models\Accounting\BankStatementImport;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\CostCenter;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\SwishImport;
use App\Models\AuditLog;
use App\Services\Accounting\BankFeeMatcherService;
use App\Services\Accounting\BankStatementParser;
use App\Services\Accounting\CardReceiptMatcher;
use App\Services\Accounting\InternalTransferMatcher;
use App\Services\Accounting\InvoiceMatcherService;
use App\Services\Accounting\LbReturnParser;
use App\Services\Accounting\OrderMatcherService;
use App\Services\Accounting\PostingRuleService;
use App\Services\Accounting\SparbankFeeParser;
use App\Services\Accounting\SumupReconciler;
use App\Services\Accounting\SwishReportParser;
use App\Services\Accounting\SwishTransactionMatcher;
use App\Services\Accounting\ZettleReconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BankImportController extends Controller
{
    /**
     * POST /api/v1/accounting/bank-accounts/{bankAccount}/import
     * Import a bank statement file (CSV/TXT from Swedbank).
     */
    public function importBankStatement(Request $request, BankAccount $bankAccount): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xml',
        ]);

        $uploadedFile = $request->file('file');
        $content      = file_get_contents($uploadedFile->getRealPath());
        $fileHash     = BankStatementParser::fileHash($content);

        // File-level duplicate guard
        if (BankStatementImport::where('file_hash', $fileHash)->exists()) {
            return response()->json([
                'message'            => 'File has already been imported.',
                'file_already_imported' => true,
            ], 422);
        }

        $parser = new BankStatementParser();
        $rows   = $parser->parse($content);

        $imported        = 0;
        $skipped         = 0;
        $newTransactions = collect();

        try {
            foreach ($rows as $row) {
                $hash      = BankStatementParser::importHash($bankAccount->id, $row);
                $duplicate = BankTransaction::where('import_hash', $hash)->exists();

                if ($duplicate) {
                    $skipped++;
                    continue;
                }

                $tx = BankTransaction::create([
                    'bank_account_id'  => $bankAccount->id,
                    'booking_date'     => $row['booking_date'] ?: null,
                    'transaction_date' => $row['transaction_date'] ?: null,
                    'value_date'       => $row['value_date'] ?: null,
                    'amount'           => $row['amount_ore'],
                    'balance_ore'      => $row['balance_ore'],
                    'import_hash'      => $hash,
                    'description'      => $row['description'],
                    'reference'        => $row['reference'],
                    'status'           => BankTransaction::STATUS_UNMATCHED,
                    'raw_data'         => $row,
                ]);

                $newTransactions->push($tx);
                $imported++;
            }

            // Record the file so it can never be imported again
            BankStatementImport::create([
                'bank_account_id' => $bankAccount->id,
                'file_hash'       => $fileHash,
                'row_count'       => count($rows),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Same file (or an identical row) was imported by a concurrent request
            // that won the race — the file_hash/import_hash unique indexes caught
            // it. Report it the same way as the fast-path duplicate check above,
            // instead of a raw 500.
            return response()->json([
                'message'                => 'File has already been imported.',
                'file_already_imported'  => true,
            ], 422);
        }

        // 1. Tag corporate-card transactions — sets corporate_card_id so posting rules skip them
        $cardResult = app(CardReceiptMatcher::class)->processImport($newTransactions);

        // 2. Match invoices — matched transactions get status=matched, preventing double-booking
        $invoiceResult = app(InvoiceMatcherService::class)->processImport($newTransactions);

        // 3. Match pending Orders by OCR number
        $orderMatched = app(OrderMatcherService::class)->processImport($newTransactions);

        // 4. Apply posting rules — skips card and invoice/order-matched transactions
        $ruleResult = app(PostingRuleService::class)->applyAll($bankAccount->id);

        // 5. Reconcile SumUp payouts
        $sumupMatched = app(SumupReconciler::class)->reconcileAll($bankAccount->id);

        // 6. Match internal transfers between own bank accounts
        $transferMatched = app(InternalTransferMatcher::class)->matchAll();

        // 7. Match Zettle Swish bank transactions to Zettle SWISH sales
        $zettleReconciler   = app(ZettleReconciler::class);
        $zettleSwishMatched = $zettleReconciler->reconcileSwish($bankAccount->id);

        // 8. Match Zettle card-payout bank transactions to ZettlePayout records
        $zettlePayoutMatched = $zettleReconciler->reconcilePayouts($bankAccount->id);

        // 9. Backfill Swish <-> bank-transaction links for Swish rows imported
        // before this statement (Swish reports are typically available before
        // the bank statement that covers them).
        $swishRematched = app(SwishTransactionMatcher::class)->rematch($bankAccount->id);

        AuditLog::record('bank_statement_imported', 'accounting', $bankAccount->name . ': ' . $imported . ' transaktioner', null);

        return response()->json([
            'data' => [
                'imported'              => $imported,
                'skipped'               => $skipped,
                'matched_invoices'      => $invoiceResult['matched'],
                'potential_invoices'    => count($invoiceResult['potential']),
                'matched_orders'        => $orderMatched,
                'auto_posted'           => $ruleResult['posted'],
                'sumup_matched'         => $sumupMatched,
                'zettle_swish_matched'  => $zettleSwishMatched,
                'zettle_payout_matched' => $zettlePayoutMatched,
                'transfer_matched'      => $transferMatched,
                'swish_rematched'       => $swishRematched,
                'card_tagged'           => $cardResult['tagged'],
                'card_matched'          => $cardResult['matched'],
                'card_missing'          => $cardResult['missing'],
                'card_unknown_refs'     => $cardResult['unknownRefs'],
            ],
        ]);
    }

    /**
     * POST /api/v1/accounting/bank-accounts/{bankAccount}/swish-import
     * Import a Swish report file (CSV/TXT).
     */
    public function importSwish(Request $request, BankAccount $bankAccount): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
        ]);

        $uploadedFile = $request->file('file');
        $content      = file_get_contents($uploadedFile->getRealPath());
        $hash         = SwishReportParser::fileHash($content);

        // File-level duplicate guard
        if (SwishImport::where('file_hash', $hash)->exists()) {
            return response()->json([
                'message'            => 'File has already been imported.',
                'file_already_imported' => true,
            ], 422);
        }

        $parser   = new SwishReportParser();
        $rows     = $parser->parse($content);
        $filename = $uploadedFile->getClientOriginalName();

        [$periodFrom, $periodTo] = $parser->parsePeriod($content);

        try {
            $result = app(SwishTransactionMatcher::class)->import(
                bankAccountId: $bankAccount->id,
                rows:          $rows,
                fileHash:      $hash,
                filename:      $filename,
                periodFrom:    $periodFrom,
                periodTo:      $periodTo,
            );
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Same file (or an identical row) was imported by a concurrent request.
            return response()->json([
                'message'                => 'File has already been imported.',
                'file_already_imported'  => true,
            ], 422);
        }

        AuditLog::record('swish_imported', 'accounting', $bankAccount->name . ': ' . $result->row_count . ' transaktioner', null);

        return response()->json([
            'data' => [
                'imported'          => $result->row_count,
                'matched'           => $result->matched_count,
                'already_imported'  => false,
                'skipped_rows'      => $parser->getSkippedRows(),
            ],
        ]);
    }

    /**
     * POST /api/v1/accounting/bank-accounts/{bankAccount}/bank-fee-import
     * Import a Sparbanken Sjuhärad bank fee PDF and create a journal entry.
     */
    public function importBankFees(Request $request, BankAccount $bankAccount): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $request->validate([
            'file'              => 'required|file|mimes:pdf',
            'credit_account_id' => 'nullable|exists:accounts,id',
            'cost_center_ids'   => 'nullable|array',
            'cost_center_ids.*' => 'nullable|integer|exists:cost_centers,id',
        ]);

        $uploadedFile = $request->file('file');
        $path         = $uploadedFile->getRealPath();
        $parser       = new SparbankFeeParser();
        $parsed       = $parser->parse($path);

        if (! $parsed) {
            return response()->json([
                'message' => 'Kunde inte läsa PDF-filen. Kontrollera att det är en bankavgiftsfaktura från Sparbanken Sjuhärad.',
            ], 422);
        }

        // Resolve credit account (default: 1933 Bankkonto Medlem)
        if ($request->filled('credit_account_id')) {
            $creditAccount = Account::findOrFail($request->integer('credit_account_id'));
        } else {
            $creditAccount = Account::where('code', '1933')->first();
            if (! $creditAccount) {
                return response()->json([
                    'message' => 'Standardkonto 1933 saknas i kontoplanen. Ange credit_account_id.',
                ], 422);
            }
        }

        $expenseAccount = Account::where('code', '6570')->first();
        if (! $expenseAccount) {
            return response()->json([
                'message' => 'Konto 6570 Bankkostnader saknas i kontoplanen.',
            ], 422);
        }

        $date       = $parsed['period_end'];
        $fiscalYear = FiscalYear::where('starts_at', '<=', $date)
            ->where('ends_at', '>=', $date)
            ->where('is_locked', false)
            ->first();

        if (! $fiscalYear) {
            return response()->json([
                'message' => 'Inget öppet räkenskapsår täcker perioden ' . $date . '. Skapa eller öppna räkenskapsåret.',
            ], 422);
        }

        $lines      = $parsed['lines'];
        $totalOre   = $parsed['total_ore'];
        $periodFrom = $parsed['period_start'];
        $periodTo   = $parsed['period_end'];

        // Auto-detect cost centers from bank account swish_number if not supplied
        $costCenterIds = $request->input('cost_center_ids', []);
        if (empty($costCenterIds)) {
            $bankAccounts = BankAccount::with('costCenter')
                ->whereNotNull('swish_number')
                ->get();

            foreach ($lines as $i => $line) {
                $ba = $bankAccounts->first(fn ($a) =>
                    preg_replace('/\D/', '', $a->swish_number) === $line['agreement_number']
                );
                $costCenterIds[$i] = $ba?->costCenter?->id;
            }
        }

        $journalEntryId = null;

        DB::transaction(function () use (
            $fiscalYear, $expenseAccount, $creditAccount,
            $lines, $totalOre, $periodFrom, $periodTo, $date,
            $costCenterIds, $uploadedFile, &$journalEntryId
        ) {
            $nextNumber = JournalEntry::where('fiscal_year_id', $fiscalYear->id)
                ->where('series', 'U')
                ->lockForUpdate()
                ->max('number') + 1;

            $entry = JournalEntry::create([
                'fiscal_year_id'   => $fiscalYear->id,
                'series'           => 'U',
                'number'           => $nextNumber,
                'date'             => $date,
                'description'      => 'Bankavgifter ' . $periodFrom . ' – ' . $periodTo,
                'is_locked'        => false,
                'requires_receipt' => true,
            ]);

            foreach ($lines as $i => $line) {
                $desc = $line['description'];
                if ($line['count']) {
                    $desc .= ' (' . $line['count'] . ' st)';
                }

                $entry->lines()->create([
                    'account_id'     => $expenseAccount->id,
                    'cost_center_id' => $costCenterIds[$i] ?? null,
                    'debit'          => $line['amount_ore'],
                    'credit'         => 0,
                    'description'    => $desc,
                ]);
            }

            $entry->lines()->create([
                'account_id'     => $creditAccount->id,
                'cost_center_id' => null,
                'debit'          => 0,
                'credit'         => $totalOre,
                'description'    => null,
            ]);

            $entry->updateQuietly([
                'source_type' => BankFeeMatcherService::SOURCE_TYPE,
            ]);

            // Store the PDF as a receipt
            $filePath = AccountingDocument::buildPath('pdf', $fiscalYear->starts_at->year);
            Storage::put($filePath, file_get_contents($uploadedFile->getRealPath()));

            AccountingDocument::create([
                'type'             => AccountingDocument::TYPE_RECEIPT,
                'journal_entry_id' => $entry->id,
                'fiscal_year_id'   => $fiscalYear->id,
                'disk'             => 'local',
                'path'             => $filePath,
                'original_name'    => $uploadedFile->getClientOriginalName(),
                'mime_type'        => 'application/pdf',
                'size_bytes'       => filesize($uploadedFile->getRealPath()),
                'description'      => 'Bankavgiftsfaktura ' . $periodFrom . ' – ' . $periodTo,
                'uploaded_by'      => Auth::id(),
                'linked_at'        => now(),
            ]);

            $entry->updateQuietly(['requires_receipt' => false]);

            $journalEntryId = $entry->id;
        });

        // Try to link an already-imported bank transaction for this debit
        if ($journalEntryId) {
            $entry = JournalEntry::find($journalEntryId);
            if ($entry) {
                app(BankFeeMatcherService::class)->matchExistingTransactions($entry);
            }
        }

        AuditLog::record('bank_fees_imported', 'accounting', $bankAccount->name . ': ' . count($lines) . ' avgifter', null);

        return response()->json([
            'data' => [
                'journal_entry_id' => $journalEntryId,
                'period_start'     => $periodFrom,
                'period_end'       => $periodTo,
                'total_ore'        => $totalOre,
                'line_count'       => count($lines),
            ],
        ]);
    }

    /**
     * POST /api/v1/accounting/lb-return
     * Parse an LB return file (TXT) and return the parsed result. Read-only — no DB writes.
     */
    public function parseLbReturn(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('accounting.manage'), 403);

        $request->validate([
            'file' => 'required|file|mimes:txt',
        ]);

        $content = file_get_contents($request->file('file')->getRealPath());
        $parser  = new LbReturnParser();
        $result  = $parser->parse($content);

        return response()->json(['data' => $result]);
    }
}
