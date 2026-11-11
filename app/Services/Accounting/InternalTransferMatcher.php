<?php

namespace App\Services\Accounting;

use App\Models\Accounting\BankAccount;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Detects and books internal transfers between the association's own bank accounts.
 *
 * Matching: two unmatched transactions on different internal bank accounts where
 *   - amounts are equal in magnitude but opposite in sign
 *   - booking dates are within ±1 day of each other
 *   - neither side is already explained by SumUp/Swish (reference != 'SUMUP LIMITE')
 *
 * Journal entry:
 *   Debit  receiving account ledger code   amount
 *   Credit sending account ledger code     amount
 *
 * Series K (kassatransaktioner / kontorörelse), no cost centre.
 */
class InternalTransferMatcher
{
    /** @var Collection<int, BankAccount> */
    private Collection $internalAccounts;

    public function matchAll(): int
    {
        $this->internalAccounts = BankAccount::with('account')->where('is_active', true)->get();

        // Candidates: unmatched debits and credits that look like transfers
        $candidates = BankTransaction::whereNull('journal_entry_id')
            ->whereIn('bank_account_id', $this->internalAccounts->pluck('id'))
            ->where('reference', 'not like', '%SUMUP%')
            ->where('reference', 'not like', '%swish%')
            ->orderBy('booking_date')
            ->get();

        $debits  = $candidates->where('amount', '<', 0);   // outgoing (negative in bank file)
        $credits = $candidates->where('amount', '>', 0);   // incoming (positive)

        $matched = 0;
        $usedIds = [];

        foreach ($debits as $debit) {
            if (in_array($debit->id, $usedIds)) {
                continue;
            }

            $counterpart = $this->findCounterpart($debit, $credits, $usedIds);
            if (! $counterpart) {
                continue;
            }

            $this->createJournalEntry($debit, $counterpart);
            $usedIds[] = $debit->id;
            $usedIds[] = $counterpart->id;
            $matched++;
        }

        return $matched;
    }

    private function findCounterpart(
        BankTransaction $debit,
        Collection $credits,
        array $usedIds,
    ): ?BankTransaction {
        $date = $debit->booking_date ?? $debit->transaction_date;
        if (! $date) {
            return null;
        }

        return $credits
            ->reject(fn ($c) => in_array($c->id, $usedIds))
            ->reject(fn ($c) => $c->bank_account_id === $debit->bank_account_id)
            ->filter(function ($credit) use ($debit, $date) {
                // Amounts must match in magnitude
                if (abs($credit->amount) !== abs($debit->amount)) {
                    return false;
                }

                // Dates within ±1 day
                $creditDate = $credit->booking_date ?? $credit->transaction_date;
                if (! $creditDate) {
                    return false;
                }

                return abs($date->diffInDays($creditDate)) <= 1;
            })
            ->first();
    }

    private function createJournalEntry(BankTransaction $debit, BankTransaction $credit): void
    {
        $date = $debit->booking_date ?? $debit->transaction_date;

        $fiscalYear = FiscalYear::where('starts_at', '<=', $date)
            ->where('ends_at', '>=', $date)
            ->where('is_locked', false)
            ->first();

        if (! $fiscalYear) {
            return;
        }

        $debitAccount  = $this->internalAccounts->firstWhere('id', $debit->bank_account_id)?->account;
        $creditAccount = $this->internalAccounts->firstWhere('id', $credit->bank_account_id)?->account;

        if (! $debitAccount || ! $creditAccount) {
            return;
        }

        $amount = abs($debit->amount);

        DB::transaction(function () use (
            $debit, $credit, $date, $amount,
            $debitAccount, $creditAccount, $fiscalYear
        ) {
            $nextNumber = JournalEntry::where('fiscal_year_id', $fiscalYear->id)
                ->where('series', 'K')
                ->lockForUpdate()
                ->max('number') + 1;

            $entry = JournalEntry::create([
                'fiscal_year_id'   => $fiscalYear->id,
                'series'           => 'K',
                'number'           => $nextNumber,
                'date'             => $date,
                'description'      => "Intern överföring {$creditAccount->name} → {$debitAccount->name}",
                'source_type'      => BankTransaction::class,
                'source_id'        => $credit->id,
                'is_locked'        => false,
                'requires_receipt' => false,
            ]);

            $entry->lines()->createMany([
                ['account_id' => $creditAccount->id, 'debit' => $amount, 'credit' => 0,      'cost_center_id' => null],
                ['account_id' => $debitAccount->id,  'debit' => 0,       'credit' => $amount, 'cost_center_id' => null],
            ]);

            $debit->update([
                'journal_entry_id' => $entry->id,
                'status'           => BankTransaction::STATUS_MATCHED,
            ]);

            $credit->update([
                'journal_entry_id' => $entry->id,
                'status'           => BankTransaction::STATUS_MATCHED,
            ]);
        });
    }
}
