<?php

namespace App\Services\Accounting;

use App\Mail\MissingReceiptMail;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\CorporateCard;
use App\Models\Accounting\ExpenseClaim;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Matches expense-claim card receipts to corporate-card bank transactions.
 *
 * Run after bank statement import AND after a new card-receipt expense claim
 * is submitted, to keep the two sides in sync without a background job.
 *
 * Matching criteria (all must be satisfied):
 *   - Same card (corporate_card_id)
 *   - Exact amount (öre)
 *   - Purchase date within ±3 days of the bank transaction_date
 *   - Claim status = submitted, no bank_transaction_id yet
 *   - Transaction has no linked claim yet (expenseClaimViaCard)
 */
class CardReceiptMatcher
{
    /** Days either side of the transaction date that we accept a receipt date. */
    private const DATE_TOLERANCE_DAYS = 3;

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Run after a bank statement import.
     *
     * 1. Detect K-references in every new transaction's description.
     * 2. Tag the transaction with the matching CorporateCard if found.
     * 3. Try to match it to a pending card-receipt expense claim.
     * 4. For unmatched card transactions, notify the card holder.
     *
     * @param  Collection<int, BankTransaction>  $transactions  Freshly inserted rows
     * @return array{tagged: int, matched: int, missing: int}
     */
    /**
     * Run after a bank statement import.
     *
     * @param  Collection<int, BankTransaction>  $transactions  Freshly inserted rows
     * @return array{tagged: int, matched: int, missing: int, unknown_refs: string[]}
     */
    public function processImport(Collection $transactions): array
    {
        $tagged      = 0;
        $matched     = 0;
        $missing     = 0;
        $unknownRefs = [];   // K-refs found in descriptions but not in corporate_cards

        // Preload all active cards indexed by reference for O(1) lookup
        $cards = CorporateCard::where('is_active', true)
            ->with('member.user')
            ->get()
            ->keyBy('card_reference');

        foreach ($transactions as $tx) {
            if ($tx->amount >= 0) {
                continue; // only debit card purchases
            }

            $ref = CorporateCard::extractReference($tx->description, $tx->reference ?? '');
            if (! $ref) {
                continue;
            }

            if (! isset($cards[$ref])) {
                // K-reference found but no card registered — flag for kassör
                if (! in_array($ref, $unknownRefs, true)) {
                    $unknownRefs[] = $ref;
                }
                continue;
            }

            $card = $cards[$ref];

            // Tag the transaction (idempotent — safe to run again)
            $tx->update(['corporate_card_id' => $card->id]);
            $tagged++;

            // Try to match to an existing pending receipt
            if ($this->matchTransaction($tx, $card)) {
                $matched++;
            } else {
                $missing++;
                $this->notifyMissingReceipt($card, $tx);
            }
        }

        return compact('tagged', 'matched', 'missing', 'unknownRefs');
    }

    /**
     * Run after a new CorporateCard is registered.
     *
     * Scans all historical BankTransactions whose description contains the
     * card's reference but were imported before the card existed.
     * Tags them with corporate_card_id and tries to match pending receipts.
     *
     * Does NOT send missing-receipt emails — the kassör will see the restlista.
     *
     * @return array{found: int, matched: int, unmatched: int}
     */
    public function backfillCard(CorporateCard $card): array
    {
        $found     = 0;
        $matched   = 0;
        $unmatched = 0;

        $transactions = BankTransaction::whereNull('corporate_card_id')
            ->where('amount', '<', 0)
            ->where(function ($q) use ($card) {
                $q->where('description', 'like', "%{$card->card_reference}%")
                  ->orWhere('reference', 'like', "%{$card->card_reference}%");
            })
            ->get();

        foreach ($transactions as $tx) {
            // Double-check the regex — avoid partial matches like K34 inside K3491
            if (CorporateCard::extractReference($tx->description, $tx->reference ?? '') !== $card->card_reference) {
                continue;
            }

            $tx->update(['corporate_card_id' => $card->id]);
            $found++;

            if ($this->matchTransaction($tx, $card)) {
                $matched++;
            } else {
                $unmatched++;
                // No email — kassör sees restlista instead
            }
        }

        return compact('found', 'matched', 'unmatched');
    }

    /**
     * Run after a member submits a new card-receipt expense claim.
     * Tries to find the matching bank transaction immediately.
     *
     * @return bool  true if matched
     */
    public function processNewClaim(ExpenseClaim $claim): bool
    {
        if ($claim->claim_type !== ExpenseClaim::CLAIM_TYPE_CARD_RECEIPT) {
            return false;
        }
        if (! $claim->corporate_card_id || $claim->bank_transaction_id) {
            return false;
        }

        $tx = $this->findTransaction($claim);
        if (! $tx) {
            return false;
        }

        $claim->update([
            'bank_transaction_id' => $tx->id,
            'status'              => ExpenseClaim::STATUS_APPROVED,
            'reviewed_at'         => now(),
        ]);

        return true;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Try to match a bank transaction to a pending expense claim.
     * Returns true and updates both records when a match is found.
     */
    private function matchTransaction(BankTransaction $tx, CorporateCard $card): bool
    {
        $claim = ExpenseClaim::where('claim_type', ExpenseClaim::CLAIM_TYPE_CARD_RECEIPT)
            ->where('corporate_card_id', $card->id)
            ->whereNull('bank_transaction_id')
            ->where('status', ExpenseClaim::STATUS_SUBMITTED)
            ->where('amount_ore', abs($tx->amount))
            ->whereBetween('purchased_at', [
                Carbon::parse($tx->transaction_date ?? $tx->booking_date)->subDays(self::DATE_TOLERANCE_DAYS),
                Carbon::parse($tx->transaction_date ?? $tx->booking_date)->addDays(self::DATE_TOLERANCE_DAYS),
            ])
            ->first();

        if (! $claim) {
            return false;
        }

        $claim->update([
            'bank_transaction_id' => $tx->id,
            'status'              => ExpenseClaim::STATUS_APPROVED,
            'reviewed_at'         => now(),
        ]);

        return true;
    }

    /**
     * Find a bank transaction that matches a given expense claim.
     */
    private function findTransaction(ExpenseClaim $claim): ?BankTransaction
    {
        return BankTransaction::where('corporate_card_id', $claim->corporate_card_id)
            ->whereDoesntHave('expenseClaimViaCard')
            ->where('amount', -abs($claim->amount_ore))   // card purchases are negative
            ->whereBetween('transaction_date', [
                Carbon::parse($claim->purchased_at)->subDays(self::DATE_TOLERANCE_DAYS),
                Carbon::parse($claim->purchased_at)->addDays(self::DATE_TOLERANCE_DAYS),
            ])
            ->first();
    }

    /**
     * Send a notification email to the card holder about the missing receipt.
     * Silently swallows exceptions so a mail failure never breaks the import.
     */
    private function notifyMissingReceipt(CorporateCard $card, BankTransaction $tx): void
    {
        $email = $card->member?->user?->email ?? $card->member?->email;
        if (! $email) {
            return;
        }

        try {
            Mail::to($email)->send(new MissingReceiptMail($card, $tx));
        } catch (\Throwable) {
            // Log but don't surface — import must always complete
            logger()->warning("MissingReceiptMail failed for card {$card->card_reference}, tx {$tx->id}");
        }
    }

    // ── Restlista queries (used by both member and kassör views) ──────────────

    /**
     * Card transactions that are missing a receipt document (card holder restlista).
     *
     * Every card purchase requires a receipt by definition, so we do NOT filter
     * by requires_receipt here.  The kassör dismisses individual items by setting
     * requires_receipt = false on the journal entry (opt-out).
     *
     * Three states:
     *   - No journal_entry_id yet: show as "väntar på bokföring" (can't upload yet)
     *   - Has journal entry, requires_receipt = false: kassör dismissed — hidden
     *   - Has journal entry, requires_receipt = true, no documents: show
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function missingReceiptsQuery(CorporateCard $card)
    {
        return BankTransaction::where('corporate_card_id', $card->id)
            ->where(function ($q) {
                // No verifikat yet — kassör hasn't booked it yet
                $q->whereNull('journal_entry_id')
                  // OR verifikat requires a receipt but none uploaded yet
                  ->orWhereHas('journalEntry', fn ($q) => $q
                      ->where('requires_receipt', true)
                      ->whereDoesntHave('documents')
                  );
            })
            ->orderByDesc('transaction_date');
    }

    /**
     * All cards with their missing-receipt counts (kassör overview).
     *
     * @return Collection<int, array{card: CorporateCard, count: int}>
     */
    public static function allMissingByCard(): Collection
    {
        return CorporateCard::where('is_active', true)
            ->with('member')
            ->get()
            ->map(fn (CorporateCard $card) => [
                'card'  => $card,
                'count' => static::missingReceiptsQuery($card)->count(),
            ])
            ->filter(fn ($row) => $row['count'] > 0)
            ->values();
    }
}
