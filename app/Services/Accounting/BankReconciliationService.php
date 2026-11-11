<?php

namespace App\Services\Accounting;

use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\JournalEntry;
use Illuminate\Support\Collection;

/**
 * Suggests journal entry matches for unmatched bank transactions.
 *
 * Matching strategy (in priority order):
 *   1. A current journal line sits on the bank account with the exact same amount
 *      on the correct side (debit for incoming, credit for outgoing).
 *   2. Among those candidates, score by date proximity to the transaction's booking date.
 *
 * Confidence levels:
 *   high   — exact amount + date within 2 days (safe to auto-confirm in bulk)
 *   medium — exact amount + date within 14 days (worth reviewing before confirming)
 *   low    — exact amount but date further away (show as a hint only)
 */
class BankReconciliationService
{
    /**
     * Suggest the best matching journal entry for a single bank transaction.
     *
     * @return array{entry: JournalEntry, confidence: string, reason: string, total_candidates: int}|null
     */
    public function suggest(BankTransaction $tx): ?array
    {
        $bankLedgerId = $tx->bankAccount?->account_id;
        if (! $bankLedgerId) {
            return null;
        }

        $amount     = abs($tx->amount);
        $isIncoming = $tx->amount > 0;
        $txDate     = $tx->booking_date ?? $tx->transaction_date;

        if (! $txDate || $amount === 0) {
            return null;
        }

        // Exclude journal entries already claimed by another bank transaction
        $claimedIds = BankTransaction::whereNotNull('journal_entry_id')
            ->where('id', '!=', $tx->id)
            ->pluck('journal_entry_id');

        // Find entries that have a matching line on the bank ledger account
        $candidates = JournalEntry::with(['currentLines.account'])
            ->whereNotIn('id', $claimedIds)
            ->whereHas('currentLines', function ($q) use ($bankLedgerId, $amount, $isIncoming) {
                $q->whereNull('superseded_at')
                  ->where('account_id', $bankLedgerId);

                // Incoming → the bank account is debited in the journal entry
                // Outgoing → the bank account is credited in the journal entry
                if ($isIncoming) {
                    $q->where('debit', $amount);
                } else {
                    $q->where('credit', $amount);
                }
            })
            ->orderBy('date')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        // Score each candidate by date proximity
        $scored = $candidates->map(function (JournalEntry $entry) use ($txDate) {
            $days = (int) abs($entry->date->diffInDays($txDate));

            $score = match (true) {
                $days === 0 => 100,
                $days <= 2  => 85,
                $days <= 7  => 60,
                $days <= 14 => 35,
                default     => 10,
            };

            return ['entry' => $entry, 'score' => $score, 'days' => $days];
        })->sortByDesc('score');

        $best = $scored->first();

        $confidence = match (true) {
            $best['score'] >= 85 => 'high',
            $best['score'] >= 35 => 'medium',
            default              => 'low',
        };

        $days   = $best['days'];
        $reason = $days === 0
            ? 'Exakt belopp, samma datum'
            : "Exakt belopp, {$days} dag" . ($days !== 1 ? 'ar' : '') . ' skillnad';

        return [
            'entry'            => $best['entry'],
            'confidence'       => $confidence,
            'reason'           => $reason,
            'total_candidates' => $candidates->count(),
        ];
    }

    /**
     * Run suggest() for a collection of bank transactions.
     * Returns a keyed array: bankTransaction.id => suggestion|null.
     *
     * @param  Collection<BankTransaction> $transactions
     * @return array<int, array|null>
     */
    public function suggestBatch(Collection $transactions): array
    {
        $results = [];
        foreach ($transactions as $tx) {
            $results[$tx->id] = $this->suggest($tx);
        }
        return $results;
    }
}
