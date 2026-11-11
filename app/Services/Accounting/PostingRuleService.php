<?php

namespace App\Services\Accounting;

use App\Models\Accounting\AccountingInboxEmail;
use App\Models\Accounting\AccountingInboxItem;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\PostingRule;
use Illuminate\Support\Facades\DB;

class PostingRuleService
{
    /**
     * Apply all active bank-transaction rules to unmatched transactions.
     *
     * When $bankAccountId is supplied only rules and transactions for that
     * account are considered — rules with bank_account_id = NULL are still
     * included (they match any account) but only against the scoped transactions.
     * This prevents importing Account A from accidentally auto-posting old
     * unmatched transactions that belong to Account B.
     *
     * Returns ['posted' => N, 'skipped' => N, 'errors' => [message, ...]].
     */
    public function applyAll(?int $bankAccountId = null): array
    {
        $rulesQuery = PostingRule::active()
            ->where('trigger', PostingRule::TRIGGER_BANK_TRANSACTION)
            ->whereNotNull('counter_account_id')
            ->with(['bankAccount.account', 'counterAccount', 'costCenter'])
            ->orderBy('sort_order')
            ->orderBy('id');

        // When scoped to an account, keep rules that either target this specific
        // account or have no account filter (bank_account_id IS NULL).
        if ($bankAccountId !== null) {
            $rulesQuery->where(function ($q) use ($bankAccountId) {
                $q->whereNull('bank_account_id')
                  ->orWhere('bank_account_id', $bankAccountId);
            });
        }

        $rules = $rulesQuery->get();

        if ($rules->isEmpty()) {
            return ['posted' => 0, 'skipped' => 0, 'errors' => []];
        }

        $txQuery = BankTransaction::unmatched()->with('bankAccount.account');

        if ($bankAccountId !== null) {
            $txQuery->where('bank_account_id', $bankAccountId);
        }

        $transactions = $txQuery->get();

        $posted  = 0;
        $skipped = 0;
        $errors  = [];

        foreach ($transactions as $tx) {
            $matched = false;

            foreach ($rules as $rule) {
                if (! $rule->matches($tx)) {
                    continue;
                }

                try {
                    $this->post($tx, $rule);
                    $posted++;
                    $matched = true;
                } catch (\Throwable $e) {
                    $errors[] = "Transaktion #{$tx->id} ({$tx->description}): " . $e->getMessage();
                }

                break; // first matching rule wins
            }

            if (! $matched && empty($errors)) {
                $skipped++;
            }
        }

        return ['posted' => $posted, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Find the first active AP-invoice rule matching the given invoice.
     * Returns null when no rule matches.
     */
    public function suggestForInvoice(Invoice $invoice): ?PostingRule
    {
        return PostingRule::active()
            ->forTrigger(PostingRule::TRIGGER_AP_INVOICE)
            ->whereNotNull('counter_account_id')
            ->with(['counterAccount', 'costCenter', 'vendor'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->first(fn ($rule) => $rule->matchesInvoice($invoice));
    }

    /**
     * Find the first active inbox-email rule matching the given email (ignores filename).
     * Use suggestForItem() when you have a specific attachment to match against.
     * Returns null when no rule matches.
     */
    public function suggestForEmail(AccountingInboxEmail $email): ?PostingRule
    {
        return PostingRule::active()
            ->forTrigger(PostingRule::TRIGGER_INBOX_EMAIL)
            ->with(['counterAccount', 'debitAccount', 'creditAccount', 'costCenter'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->first(fn ($rule) => $rule->matchesInboxEmail($email));
    }

    /**
     * Find the first active inbox-email rule matching this specific attachment.
     *
     * The sender pattern is matched against the email; the keyword is matched
     * against BOTH the email subject AND the attachment filename (case-insensitive).
     * This allows one email to have multiple attachments matched by different rules
     * (e.g. a monthly SumUp email with a fee invoice AND payout reports).
     *
     * Returns null when no rule matches.
     */
    public function suggestForItem(AccountingInboxEmail $email, AccountingInboxItem $item): ?PostingRule
    {
        return PostingRule::active()
            ->forTrigger(PostingRule::TRIGGER_INBOX_EMAIL)
            ->with(['counterAccount', 'debitAccount', 'creditAccount', 'costCenter'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->first(fn ($rule) => $rule->matchesInboxItem($email, $item));
    }

    /**
     * Create a journal entry from an inbox-email posting rule.
     *
     * Called by promoteEmail() when the matched inbox_email rule has both
     * debit_account_id and credit_account_id configured and the PDF extractor
     * has populated extracted_net_amount_ore and extracted_period_start on the item.
     *
     * @param  \Carbon\Carbon|string $date         Booking date (period start from extracted data)
     * @param  int                   $amountOre    Net amount in öre from PDF extraction
     * @param  int|null              $createdBy    User ID of the person triggering the import
     */
    public function postFromInboxEmail(
        AccountingInboxEmail $email,
        PostingRule          $rule,
        int                  $amountOre,
        \Carbon\Carbon|string $date,
        ?int                 $createdBy = null,
    ): JournalEntry {
        return DB::transaction(function () use ($email, $rule, $amountOre, $date, $createdBy) {
            $txDate = is_string($date) ? \Carbon\Carbon::parse($date) : $date;

            $fiscalYear = FiscalYear::where('starts_at', '<=', $txDate)
                ->where('ends_at', '>=', $txDate)
                ->where('is_locked', false)
                ->first();

            if (! $fiscalYear) {
                throw new \RuntimeException(
                    "Inget öppet räkenskapsår täcker datumet {$txDate->toDateString()}."
                );
            }

            $nextNumber = JournalEntry::where('fiscal_year_id', $fiscalYear->id)
                ->where('series', $rule->series)
                ->lockForUpdate()
                ->max('number') + 1;

            $description = trim($rule->name . ($email->subject ? ": {$email->subject}" : ''));
            if (\strlen($description) > 500) {
                $description = substr($description, 0, 497) . '…';
            }

            $entry = JournalEntry::create([
                'fiscal_year_id'   => $fiscalYear->id,
                'series'           => $rule->series,
                'number'           => $nextNumber,
                'date'             => $txDate,
                'description'      => $description,
                'source_type'      => AccountingInboxEmail::class,
                'source_id'        => $email->id,
                'created_by'       => $createdBy,
                'is_locked'        => false,
                'requires_receipt' => $rule->requires_receipt,
            ]);

            $entry->lines()->createMany([
                ['account_id' => $rule->debit_account_id,  'debit' => $amountOre, 'credit' => 0,          'cost_center_id' => $rule->cost_center_id],
                ['account_id' => $rule->credit_account_id, 'debit' => 0,          'credit' => $amountOre, 'cost_center_id' => $rule->cost_center_id],
            ]);

            return $entry;
        });
    }

    /**
     * Preview how many unmatched transactions a specific rule would match.
     */
    public function previewCount(PostingRule $rule): int
    {
        $rule->loadMissing('bankAccount.account');

        return BankTransaction::unmatched()
            ->with('bankAccount.account')
            ->get()
            ->filter(fn ($tx) => $rule->matches($tx))
            ->count();
    }

    /**
     * Apply a single rule to a single transaction and create the journal entry.
     */
    public function post(BankTransaction $tx, PostingRule $rule): JournalEntry
    {
        return DB::transaction(function () use ($tx, $rule) {
            // Re-fetch under a row lock and re-verify the transaction is still
            // unmatched. applyAll() builds its candidate list before this
            // transaction starts, so without this a second, concurrent applyAll()
            // run (or a manual match happening at the same time) could double-book
            // the same transaction. The lock is held until commit, so a concurrent
            // call blocks here and then sees the already-posted status.
            $tx = BankTransaction::where('id', $tx->id)->lockForUpdate()->firstOrFail();

            if ($tx->status !== BankTransaction::STATUS_UNMATCHED) {
                throw new \RuntimeException("Transaktion #{$tx->id} har redan matchats av en annan process.");
            }

            $txDate = $tx->booking_date ?? $tx->transaction_date;

            if (! $txDate) {
                throw new \RuntimeException('Transaktionen saknar datum.');
            }

            // Find the open fiscal year covering this date
            $fiscalYear = FiscalYear::where('starts_at', '<=', $txDate)
                ->where('ends_at', '>=', $txDate)
                ->where('is_locked', false)
                ->first();

            if (! $fiscalYear) {
                throw new \RuntimeException(
                    "Inget öppet räkenskapsår täcker datumet {$txDate->toDateString()}."
                );
            }

            // Next sequential number for this series (locked to prevent races)
            $nextNumber = JournalEntry::where('fiscal_year_id', $fiscalYear->id)
                ->where('series', $rule->series)
                ->lockForUpdate()
                ->max('number') + 1;

            $description = trim($rule->name . ($tx->description ? ': ' . $tx->description : ''));
            if (strlen($description) > 500) {
                $description = substr($description, 0, 497) . '…';
            }

            // Card purchases always require a receipt regardless of rule setting.
            $requiresReceipt = $rule->requires_receipt || $tx->corporate_card_id !== null;

            $entry = JournalEntry::create([
                'fiscal_year_id'   => $fiscalYear->id,
                'series'           => $rule->series,
                'number'           => $nextNumber,
                'date'             => $txDate,
                'description'      => $description,
                'source_type'      => BankTransaction::class,
                'source_id'        => $tx->id,
                'created_by'       => null, // automated
                'is_locked'        => false,
                'requires_receipt' => $requiresReceipt,
            ]);

            $bankLedgerAccountId = $tx->bankAccount->account_id;
            $counterAccountId    = $rule->counter_account_id;
            $absAmount           = abs($tx->amount);
            $costCenterId        = $rule->cost_center_id;

            if ($tx->amount > 0) {
                // Inbetalning: bank account debited, counter account credited
                $entry->lines()->createMany([
                    ['account_id' => $bankLedgerAccountId, 'debit' => $absAmount, 'credit' => 0,           'cost_center_id' => null],
                    ['account_id' => $counterAccountId,    'debit' => 0,          'credit' => $absAmount,  'cost_center_id' => $costCenterId],
                ]);
            } else {
                // Utbetalning: counter account debited, bank account credited
                $entry->lines()->createMany([
                    ['account_id' => $counterAccountId,    'debit' => $absAmount, 'credit' => 0,           'cost_center_id' => $costCenterId],
                    ['account_id' => $bankLedgerAccountId, 'debit' => 0,          'credit' => $absAmount,  'cost_center_id' => null],
                ]);
            }

            $update = [
                'status'           => BankTransaction::STATUS_POSTED,
                'journal_entry_id' => $entry->id,
            ];

            // Tag the transaction with the rule's vendor so spend can be
            // grouped by vendor in reports (e.g. all Snabbgross card purchases).
            if ($rule->vendor_id) {
                $update['vendor_id'] = $rule->vendor_id;
            }

            $tx->update($update);

            return $entry;
        });
    }
}
