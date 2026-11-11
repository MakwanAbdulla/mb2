<?php

namespace App\Services\Accounting;

use App\Models\Accounting\BankAccount;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\JournalEntry;
use Illuminate\Support\Collection;

/**
 * Matches bank-fee debit transactions to journal entries pre-created by
 * the SparbankFeeParser PDF import.
 *
 * Journal entries from that import are marked source_type = 'SparbankBankFee'.
 * Their credit side records which bank account the fee is charged from.
 * The bank transaction is an outgoing (negative) debit from that account.
 *
 * Matching criteria:
 *   - Bank transaction: status=unmatched, amount < 0 (outgoing)
 *   - Journal entry: source_type='SparbankBankFee', not yet linked to a bank tx
 *   - abs(tx.amount) == sum of credits on the journal entry
 *   - tx.booking_date within ±10 days of entry.date (to handle invoice date vs debit date)
 */
class BankFeeMatcherService
{
    public const SOURCE_TYPE = 'SparbankBankFee';

    public function processImport(Collection $transactions): int
    {
        $unbooked = $this->loadUnbookedEntries();

        if ($unbooked->isEmpty()) {
            return 0;
        }

        $matched = 0;

        foreach ($transactions as $tx) {
            if ($tx->amount >= 0) {
                continue; // Bank fees are debits (negative)
            }

            if ($tx->status !== BankTransaction::STATUS_UNMATCHED || $tx->journal_entry_id) {
                continue;
            }

            $amount = abs($tx->amount);
            $txDate = ($tx->booking_date ?? $tx->transaction_date);

            $entry = $unbooked->first(function ($row) use ($amount, $txDate, $tx) {
                if ((int) $row['credit_total'] !== $amount) {
                    return false;
                }

                // The credit account's bank account must match this transaction's bank account
                if ($row['credit_bank_account_id'] && $row['credit_bank_account_id'] !== $tx->bank_account_id) {
                    return false;
                }

                // Date within ±10 days
                if ($txDate && $row['entry_date']) {
                    $diff = abs($txDate->diffInDays($row['entry_date']));
                    if ($diff > 10) {
                        return false;
                    }
                }

                // Ensure not already linked to a different bank transaction
                return ! BankTransaction::where('journal_entry_id', $row['entry_id'])
                    ->where('id', '!=', $tx->id)
                    ->exists();
            });

            if (! $entry) {
                continue;
            }

            $tx->update([
                'status'           => BankTransaction::STATUS_MATCHED,
                'journal_entry_id' => $entry['entry_id'],
            ]);

            // Remove from pool to prevent double-matching
            $unbooked = $unbooked->reject(fn ($r) => $r['entry_id'] === $entry['entry_id']);

            $matched++;
        }

        return $matched;
    }

    /**
     * Try to match an existing bank transaction when a journal entry has just been
     * created from the PDF upload (reverse-direction matching).
     */
    public function matchExistingTransactions(JournalEntry $entry): bool
    {
        $creditTotal = (int) $entry->currentLines()->where('credit', '>', 0)->sum('credit');
        if ($creditTotal === 0) {
            return false;
        }

        // Find the bank account linked to the credit GL account
        $creditAccountId = $entry->currentLines()
            ->where('credit', '>', 0)
            ->value('account_id');

        $bankAccount = $creditAccountId
            ? BankAccount::where('account_id', $creditAccountId)->first()
            : null;

        $query = BankTransaction::where('status', BankTransaction::STATUS_UNMATCHED)
            ->whereNull('journal_entry_id')
            ->where('amount', -$creditTotal);

        if ($bankAccount) {
            $query->where('bank_account_id', $bankAccount->id);
        }

        $entryDate = $entry->date;
        if ($entryDate) {
            $query->whereBetween('booking_date', [
                $entryDate->copy()->subDays(10),
                $entryDate->copy()->addDays(10),
            ]);
        }

        $tx = $query->first();

        if (! $tx) {
            return false;
        }

        $tx->update([
            'status'           => BankTransaction::STATUS_MATCHED,
            'journal_entry_id' => $entry->id,
        ]);

        return true;
    }

    // -------------------------------------------------------------------------

    /** Load all unlinked SparbankBankFee journal entries with their credit totals. */
    private function loadUnbookedEntries(): Collection
    {
        $entries = JournalEntry::where('source_type', self::SOURCE_TYPE)->get();

        // Filter out entries already linked to a bank transaction
        $linkedEntryIds = BankTransaction::whereIn('journal_entry_id', $entries->pluck('id'))
            ->pluck('journal_entry_id')
            ->all();

        return $entries
            ->reject(fn ($e) => in_array($e->id, $linkedEntryIds))
            ->map(function (JournalEntry $entry) {
                $creditTotal     = (int) $entry->currentLines()->where('credit', '>', 0)->sum('credit');
                $creditAccountId = $entry->currentLines()->where('credit', '>', 0)->value('account_id');

                $bankAccount = $creditAccountId
                    ? BankAccount::where('account_id', $creditAccountId)->first()
                    : null;

                return [
                    'entry_id'               => $entry->id,
                    'entry_date'             => $entry->date,
                    'credit_total'           => $creditTotal,
                    'credit_bank_account_id' => $bankAccount?->id,
                ];
            })
            ->filter(fn ($r) => $r['credit_total'] > 0)
            ->values();
    }
}
