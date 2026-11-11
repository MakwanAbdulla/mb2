<?php

namespace App\Services\Accounting;

use App\Models\Accounting\BankTransaction;
use App\Models\Shop\Order;
use Illuminate\Support\Collection;

/**
 * Matches incoming bank transactions to pending Orders by OCR number.
 *
 * Run after InvoiceMatcherService (which handles Accounting\Invoice only) and
 * before PostingRuleService so that matched transactions are not double-booked.
 *
 * Matching strategy:
 *   1. Strip non-digits from bank transaction reference + description.
 *   2. Find pending Order whose ocr_number appears within that string.
 *   3. Verify exact amount match (öre).
 *   4. Call order->markPaid('bankgiro') — fires OrderPaid → activates membership
 *      + creates journal entry via OrderJournalService (when a fiscal year is open).
 *   5. Link bank transaction → journal entry (if created) and mark as MATCHED.
 */
class OrderMatcherService
{
    public function processImport(Collection $transactions): int
    {
        // Load all pending orders once to avoid N+1 on each transaction.
        $pending = Order::where('status', Order::STATUS_PENDING)
            ->where('total_ore', '>', 0)
            ->whereNotNull('ocr_number')
            ->get(['id', 'ocr_number', 'total_ore', 'status', 'payment_method', 'journal_entry_id']);

        if ($pending->isEmpty()) {
            return 0;
        }

        $matched = 0;

        foreach ($transactions as $tx) {
            if ($tx->amount <= 0) {
                continue;
            }

            if ($tx->status !== BankTransaction::STATUS_UNMATCHED || $tx->journal_entry_id) {
                continue;
            }

            if ($tx->corporate_card_id) {
                continue;
            }

            $order = $this->findOrder($tx, $pending);

            if (! $order) {
                continue;
            }

            // Fires OrderPaid → HandleOrderPaid (sync) → activates membership
            // + OrderJournalService creates a journal entry when a fiscal year is open.
            $order->markPaid(Order::METHOD_BANKGIRO);

            // Reload to get journal_entry_id set by OrderJournalService (if created).
            $order->refresh();

            $updateData = ['status' => BankTransaction::STATUS_MATCHED];
            if ($order->journal_entry_id) {
                $updateData['journal_entry_id'] = $order->journal_entry_id;
            }

            $tx->update($updateData);

            // Remove from the in-memory pool so the same order can't be matched twice.
            $pending = $pending->reject(fn ($o) => $o->id === $order->id);

            $matched++;
        }

        return $matched;
    }

    private function findOrder(BankTransaction $tx, Collection $pending): ?Order
    {
        // Normalise the bank-side reference: strip everything except digits.
        $haystack = preg_replace('/\D/', '', ($tx->reference ?? '') . ($tx->description ?? ''));

        if (strlen($haystack) < 4) {
            return null;
        }

        return $pending->first(function (Order $order) use ($tx, $haystack) {
            $ocr = preg_replace('/\D/', '', $order->ocr_number);

            if (! $ocr || ! str_contains($haystack, $ocr)) {
                return false;
            }

            // Amount must match exactly.
            return (int) $tx->amount === (int) $order->total_ore;
        });
    }
}
