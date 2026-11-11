<?php

namespace App\Services\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\CostCenter;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\SumupPayout;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles SumUp payouts against bank statement entries.
 *
 * SumUp aggregates payouts weekly and transfers via Bankgiro.
 * Bank transaction: description="Bg kontoinsättning", reference="SUMUP LIMITE"
 *
 * Matching strategy:
 *   For each unmatched SUMUP bank transaction (ordered by date), collect all
 *   SUCCESSFUL payouts between the previous SUMUP bank transaction + 1 day
 *   and this transaction's booking date. If SUM(payouts.amount_ore) equals
 *   the bank amount exactly → match.
 *
 * Journal entry per matched bank transaction:
 *   Debit  bank account   net amount   (= bank transaction amount)
 *   Debit  6060           total fees
 *   Credit 3000           gross = net + fees
 *
 * Series U, cost centre KIOSK.
 */
class SumupReconciler
{
    public function reconcileAll(int $bankAccountId): int
    {
        $matched = 0;

        // Process in chronological order so each entry's window start is determined
        // by the previous already-matched bank transaction.
        $candidates = BankTransaction::where('bank_account_id', $bankAccountId)
            ->whereNull('journal_entry_id')
            ->where('amount', '>', 0)
            ->where('reference', 'like', '%SUMUP%')
            ->orderBy('booking_date')
            ->get();

        foreach ($candidates as $tx) {
            if ($this->reconcile($tx)) {
                $matched++;
            }
        }

        return $matched;
    }

    public function reconcile(BankTransaction $tx): bool
    {
        $date = $tx->booking_date ?? $tx->transaction_date;
        if (! $date) {
            return false;
        }

        // Window start: day after the nearest previous SUMUP bank transaction (matched or not).
        // We use ALL bank dates as period boundaries — matching status doesn't matter here.
        $prevDate = BankTransaction::where('bank_account_id', $tx->bank_account_id)
            ->where('reference', 'like', '%SUMUP%')
            ->where('booking_date', '<', $date->toDateString())
            ->max('booking_date');

        $windowStart = $prevDate
            ? Carbon::parse($prevDate)->addDay()->toDateString()
            : Carbon::parse($date)->subDays(60)->toDateString(); // fallback for first-ever entry

        $payouts = SumupPayout::where('bank_account_id', $tx->bank_account_id)
            ->whereNull('bank_transaction_id')
            ->where('status', 'SUCCESSFUL')
            ->whereBetween('payout_date', [$windowStart, $date->toDateString()])
            ->get();

        if ($payouts->isEmpty()) {
            return false;
        }

        $totalNet = $payouts->sum('amount_ore');
        $totalFee = $payouts->sum('fee_ore');

        if ($totalNet !== (int) $tx->amount) {
            return false;
        }

        $this->createJournalEntry($tx, $totalNet, $totalFee, $payouts->pluck('id'));

        return true;
    }

    private function createJournalEntry(
        BankTransaction $tx,
        int $netOre,
        int $feeOre,
        \Illuminate\Support\Collection $payoutIds,
    ): void {
        $date = $tx->booking_date ?? $tx->transaction_date;

        $tx->loadMissing('bankAccount.account');

        $bankAccount  = $tx->bankAccount->account;
        $feeAccount   = Account::where('code', '6060')->firstOrFail();
        $salesAccount = Account::where('code', '3000')->firstOrFail();
        $costCenter   = CostCenter::where('code', 'KIOSK')->first();

        $fiscalYear = FiscalYear::where('starts_at', '<=', $date)
            ->where('ends_at', '>=', $date)
            ->where('is_locked', false)
            ->first();

        if (! $fiscalYear) {
            return;
        }

        $grossOre = $netOre + $feeOre;

        DB::transaction(function () use (
            $tx, $date, $netOre, $feeOre, $grossOre,
            $bankAccount, $feeAccount, $salesAccount, $costCenter,
            $fiscalYear, $payoutIds
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
                'description'      => 'SumUp utbetalning ' . $date->toDateString(),
                'source_type'      => BankTransaction::class,
                'source_id'        => $tx->id,
                'is_locked'        => false,
                'requires_receipt' => false,
            ]);

            $lines = [
                ['account_id' => $bankAccount->id,  'debit' => $netOre,  'credit' => 0,         'cost_center_id' => $costCenter?->id],
                ['account_id' => $salesAccount->id, 'debit' => 0,        'credit' => $grossOre, 'cost_center_id' => $costCenter?->id],
            ];

            if ($feeOre > 0) {
                $lines[] = ['account_id' => $feeAccount->id, 'debit' => $feeOre, 'credit' => 0, 'cost_center_id' => $costCenter?->id];
            }

            $entry->lines()->createMany($lines);

            $tx->update([
                'journal_entry_id' => $entry->id,
                'status'           => BankTransaction::STATUS_MATCHED,
            ]);

            SumupPayout::whereIn('id', $payoutIds)->update([
                'bank_transaction_id' => $tx->id,
            ]);
        });
    }
}
