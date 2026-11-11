<?php

namespace App\Services\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\CostCenter;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\ZettlePayout;
use App\Models\Accounting\ZettleTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Two reconciliation flows for Zettle (PayPal POS):
 *
 * 1. reconcileSwish — matches individual Swish bank transactions against
 *    Zettle purchases where payment_type LIKE '%SWISH%', same amount, date ±1 day.
 *    One unambiguous match → journal entry on the Swish bank account.
 *
 * 2. reconcilePayouts — matches batched Zettle card-payout bank transactions
 *    (reference/description contains ZETTLE, IZETTLE or PAYPAL) against
 *    ZettlePayout records from the Finance API, same amount, date ±1 day.
 *
 * Journal entries: series U, cost centre BSK (falls back to null).
 * Sales account 3000, fee account 6060 (used when fee data is available).
 */
class ZettleReconciler
{
    // -------------------------------------------------------------------------
    // Swish 1:1 matching
    // -------------------------------------------------------------------------

    /**
     * Try to match unmatched Swish bank transactions on $swishBankAccountId
     * against Zettle sales with payment_type LIKE '%SWISH%'.
     *
     * Zettle records are looked up across all bank accounts that have Zettle
     * transactions — no need to hardcode the Zettle settlement account.
     *
     * Only books when exactly one Zettle SWISH sale matches by amount + date ±1 day
     * (ambiguous cases are left for manual review).
     */
    /**
     * Match Swish bank transactions to Zettle SWISH sales using N:N group matching.
     *
     * Bank transactions are grouped by (booking_date, amount). For each group,
     * we count unmatched Zettle SWISH sales with the same amount within ±1 day.
     * If the counts are equal the whole group is booked — handles the common case
     * of many same-price tickets sold at an event.
     *
     * Already-matched Zettle IDs are excluded from subsequent groups within the same
     * run, preventing double-counting when ±1 day windows overlap across dates.
     */
    public function reconcileSwish(int $swishBankAccountId): int
    {
        $zettleAccountIds = ZettleTransaction::distinct()->pluck('bank_account_id');

        if ($zettleAccountIds->isEmpty()) {
            return 0;
        }

        $bankTxs = BankTransaction::where('bank_account_id', $swishBankAccountId)
            ->whereNull('journal_entry_id')
            ->whereNull('corporate_card_id')
            ->where('amount', '>', 0)
            ->where('status', BankTransaction::STATUS_UNMATCHED)
            ->orderBy('booking_date')
            ->get();

        if ($bankTxs->isEmpty()) {
            return 0;
        }

        $matched        = 0;
        $usedZettleIds  = collect();

        // Group by date + amount so all bank entries for the same price on the same
        // day are evaluated together against the Zettle sales pool.
        $groups = $bankTxs->groupBy(function ($tx) {
            $date = ($tx->booking_date ?? $tx->transaction_date)?->toDateString() ?? 'unknown';
            return $date . '|' . $tx->amount;
        });

        foreach ($groups as $key => $bankGroup) {
            [$dateStr, $amount] = explode('|', $key, 2);

            if ($dateStr === 'unknown') {
                continue;
            }

            $date = \Carbon\Carbon::parse($dateStr);

            $zettleGroup = ZettleTransaction::whereIn('bank_account_id', $zettleAccountIds)
                ->whereNull('bank_transaction_id')
                ->whereNotIn('id', $usedZettleIds->all())
                ->whereRaw("UPPER(payment_type) LIKE '%SWISH%'")
                ->where('gross_ore', (int) $amount)
                ->whereBetween('purchase_date', [
                    $date->copy()->subDay()->toDateString(),
                    $date->copy()->addDay()->toDateString(),
                ])
                ->orderBy('purchase_date')
                ->orderBy('purchase_time')
                ->get();

            // Only match when counts are equal — unequal means ambiguity.
            if ($bankGroup->count() !== $zettleGroup->count()) {
                continue;
            }

            // values() re-indexes so $i is always 0..n regardless of groupBy keys.
            foreach ($bankGroup->values() as $i => $bankTx) {
                $zettle = $zettleGroup[$i];
                $this->createSwishJournalEntry($bankTx, $zettle);
                $usedZettleIds->push($zettle->id);
                $matched++;
            }
        }

        return $matched;
    }

    // -------------------------------------------------------------------------
    // Card payout batch matching
    // -------------------------------------------------------------------------

    /**
     * Match Zettle card-payout bank transactions on $bankAccountId against
     * ZettlePayout records. Identifies payout transactions by looking for
     * ZETTLE / IZETTLE / PAYPAL in the bank transaction's reference or description.
     */
    public function reconcilePayouts(int $bankAccountId): int
    {
        $candidates = BankTransaction::where('bank_account_id', $bankAccountId)
            ->whereNull('journal_entry_id')
            ->where('amount', '>', 0)
            ->where(fn ($q) => $q
                ->where('reference',   'like', '%ZETTLE%')
                ->orWhere('reference',   'like', '%IZETTLE%')
                ->orWhere('reference',   'like', '%PAYPAL%')
                ->orWhere('description', 'like', '%ZETTLE%')
                ->orWhere('description', 'like', '%IZETTLE%')
                ->orWhere('description', 'like', '%PAYPAL%')
            )
            ->orderBy('booking_date')
            ->get();

        $matched = 0;

        foreach ($candidates as $bankTx) {
            $date = $bankTx->booking_date ?? $bankTx->transaction_date;
            if (! $date) {
                continue;
            }

            $payout = ZettlePayout::where('bank_account_id', $bankAccountId)
                ->whereNull('bank_transaction_id')
                ->where('amount_ore', (int) $bankTx->amount)
                ->whereBetween('payout_date', [
                    $date->copy()->subDay()->toDateString(),
                    $date->copy()->addDay()->toDateString(),
                ])
                ->first();

            if ($payout) {
                $this->createPayoutJournalEntry($bankTx, $payout);
                $matched++;
            }
        }

        return $matched;
    }

    // -------------------------------------------------------------------------
    // Journal entry builders
    // -------------------------------------------------------------------------

    private function createSwishJournalEntry(BankTransaction $bankTx, ZettleTransaction $zettle): void
    {
        $date = $bankTx->booking_date ?? $bankTx->transaction_date;

        $bankTx->loadMissing('bankAccount.account');

        $bankAccount  = $bankTx->bankAccount->account;
        $salesAccount = Account::where('code', '3000')->firstOrFail();
        $costCenter   = CostCenter::where('code', 'BSK')->first();

        $fiscalYear = FiscalYear::where('starts_at', '<=', $date)
            ->where('ends_at', '>=', $date)
            ->where('is_locked', false)
            ->first();

        if (! $fiscalYear) {
            return;
        }

        $amountOre = (int) $bankTx->amount;

        DB::transaction(function () use (
            $bankTx, $zettle, $date, $amountOre,
            $bankAccount, $salesAccount, $costCenter, $fiscalYear
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
                'description'      => 'Zettle Swish ' . $date->toDateString()
                    . ($zettle->description ? ' – ' . $zettle->description : ''),
                'source_type'      => BankTransaction::class,
                'source_id'        => $bankTx->id,
                'is_locked'        => false,
                'requires_receipt' => false,
            ]);

            $entry->lines()->createMany([
                ['account_id' => $bankAccount->id,  'debit' => $amountOre, 'credit' => 0,          'cost_center_id' => $costCenter?->id],
                ['account_id' => $salesAccount->id, 'debit' => 0,          'credit' => $amountOre, 'cost_center_id' => $costCenter?->id],
            ]);

            $bankTx->update([
                'journal_entry_id' => $entry->id,
                'status'           => BankTransaction::STATUS_MATCHED,
            ]);

            $zettle->update(['bank_transaction_id' => $bankTx->id]);
        });
    }

    private function createPayoutJournalEntry(BankTransaction $bankTx, ZettlePayout $payout): void
    {
        $date = $bankTx->booking_date ?? $bankTx->transaction_date;

        $bankTx->loadMissing('bankAccount.account');

        $bankAccount  = $bankTx->bankAccount->account;
        $salesAccount = Account::where('code', '3000')->firstOrFail();
        $costCenter   = CostCenter::where('code', 'BSK')->first();

        $fiscalYear = FiscalYear::where('starts_at', '<=', $date)
            ->where('ends_at', '>=', $date)
            ->where('is_locked', false)
            ->first();

        if (! $fiscalYear) {
            return;
        }

        $amountOre = (int) $bankTx->amount;

        DB::transaction(function () use (
            $bankTx, $payout, $date, $amountOre,
            $bankAccount, $salesAccount, $costCenter, $fiscalYear
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
                'description'      => 'Zettle utbetalning ' . $date->toDateString(),
                'source_type'      => BankTransaction::class,
                'source_id'        => $bankTx->id,
                'is_locked'        => false,
                'requires_receipt' => false,
            ]);

            $entry->lines()->createMany([
                ['account_id' => $bankAccount->id,  'debit' => $amountOre, 'credit' => 0,          'cost_center_id' => $costCenter?->id],
                ['account_id' => $salesAccount->id, 'debit' => 0,          'credit' => $amountOre, 'cost_center_id' => $costCenter?->id],
            ]);

            $bankTx->update([
                'journal_entry_id' => $entry->id,
                'status'           => BankTransaction::STATUS_MATCHED,
            ]);

            $payout->update(['bank_transaction_id' => $bankTx->id]);
        });
    }
}
