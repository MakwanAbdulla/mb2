<?php

namespace App\Services\Accounting;

use App\Mail\InvoiceMatchedMail;
use App\Models\Accounting\Account;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalSeries;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

/**
 * Matches bank transactions to unpaid invoices.
 *
 * Run after bank statement import, before posting rules, so that transactions
 * linked to an invoice are never double-booked by an automatic rule.
 *
 * Two matching strategies:
 *
 *  Leverantörsfakturor (payable):
 *    - Transaction is outgoing (amount < 0)
 *    - transaction.vendor_id === invoice.vendor_id
 *    - Exact amount match (öre)
 *    - Booking date within ±PAYABLE_TOLERANCE days of invoice due_date
 *
 *  Utställda fakturor (receivable):
 *    - Transaction is incoming (amount > 0)
 *    - Definitive: transaction.reference contains invoice.ocr_number (OCR/bankgiro)
 *    - Fallback: exact amount match within ±RECEIVABLE_TOLERANCE days
 *      (returned as "potential" — not auto-confirmed, shown for manual review)
 */
class InvoiceMatcherService
{
    private const PAYABLE_TOLERANCE    = 14;  // days either side of due_date
    private const RECEIVABLE_TOLERANCE = 30;  // days either side of due_date

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Run against a batch of freshly imported transactions.
     *
     * Returns:
     *   matched   — number of transactions definitively matched and marked paid
     *   potential — array of ['tx' => BankTransaction, 'invoices' => Invoice[]]
     *               for amount-only receivable matches that need manual confirmation
     */
    public function processImport(Collection $transactions): array
    {
        $matched   = 0;
        $potential = [];

        // Preload unpaid invoices to avoid N+1
        $payable    = $this->unpaidPayable()->get();
        $receivable = $this->unpaidReceivable()->get();

        foreach ($transactions as $tx) {
            // Skip corporate-card transactions (handled by CardReceiptMatcher)
            if ($tx->corporate_card_id) {
                continue;
            }

            if ($tx->amount < 0) {
                // Outgoing — try to match a leverantörsfaktura
                $invoice = $this->findPayableMatch($tx, $payable);
                if ($invoice) {
                    try {
                        $this->linkAndPay($tx, $invoice);
                        $matched++;
                        $payable = $payable->reject(fn ($i) => $i->id === $invoice->id);
                    } catch (\Throwable $e) {
                        // Leave the transaction unmatched so it can be resolved (and
                        // re-attempted) manually rather than silently half-linking it.
                        Log::error("InvoiceMatcherService: failed to book payment for tx #{$tx->id} / invoice #{$invoice->id}: {$e->getMessage()}");
                    }
                }
            } elseif ($tx->amount > 0) {
                // Incoming — try OCR first, then amount fallback
                [$definitive, $candidates] = $this->findReceivableMatch($tx, $receivable);

                if ($definitive) {
                    try {
                        $this->linkAndPay($tx, $definitive);
                        $matched++;
                        $receivable = $receivable->reject(fn ($i) => $i->id === $definitive->id);
                    } catch (\Throwable $e) {
                        Log::error("InvoiceMatcherService: failed to book payment for tx #{$tx->id} / invoice #{$definitive->id}: {$e->getMessage()}");
                    }
                } elseif ($candidates->isNotEmpty()) {
                    $potential[] = [
                        'tx'       => $tx,
                        'invoices' => $candidates->values(),
                    ];
                }
            }
        }

        return compact('matched', 'potential');
    }

    /**
     * Manually link a transaction to an invoice and mark both as paid.
     * Called from the reconcile UI when kassör confirms a potential match.
     */
    public function confirmMatch(BankTransaction $tx, Invoice $invoice): void
    {
        $this->linkAndPay($tx, $invoice);
    }

    // ── Private matching helpers ──────────────────────────────────────────────

    private function findPayableMatch(BankTransaction $tx, Collection $payable): ?Invoice
    {
        if (! $tx->vendor_id) {
            return null;
        }

        $absAmount = abs($tx->amount);
        $txDate    = $tx->booking_date ?? $tx->transaction_date;

        return $payable->first(function (Invoice $inv) use ($tx, $absAmount, $txDate) {
            if ($inv->vendor_id !== $tx->vendor_id) {
                return false;
            }

            if ($inv->total !== $absAmount) {
                return false;
            }

            if ($txDate && $inv->due_date) {
                $diff = abs(Carbon::parse($txDate)->diffInDays($inv->due_date));
                if ($diff > self::PAYABLE_TOLERANCE) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Returns [?Invoice $definitive, Collection $potential].
     *
     * Definitive: OCR reference match (certain).
     * Potential:  amount-only matches within date window (ambiguous, needs human).
     */
    private function findReceivableMatch(BankTransaction $tx, Collection $receivable): array
    {
        $txDate = $tx->booking_date ?? $tx->transaction_date;

        // 1. OCR match — strip non-digits from both sides
        $txRef = preg_replace('/\D/', '', $tx->reference ?? '');
        if ($txRef !== '') {
            $definitive = $receivable->first(function (Invoice $inv) use ($txRef) {
                $ocr = preg_replace('/\D/', '', $inv->ocr_number ?? '');
                return $ocr !== '' && $ocr === $txRef;
            });

            if ($definitive) {
                return [$definitive, collect()];
            }
        }

        // 2. Amount + date window — return as candidates for manual review
        $candidates = $receivable->filter(function (Invoice $inv) use ($tx, $txDate) {
            if ($inv->total !== $tx->amount) {
                return false;
            }

            if ($txDate && $inv->due_date) {
                $diff = abs(Carbon::parse($txDate)->diffInDays($inv->due_date));
                if ($diff > self::RECEIVABLE_TOLERANCE) {
                    return false;
                }
            }

            return true;
        });

        return [null, $candidates];
    }

    // ── Linking ───────────────────────────────────────────────────────────────

    /**
     * Links a bank transaction to an invoice, books the clearing journal entry
     * (Debet/Kredit 2440 Leverantörsskulder or 1510 Kundfordringar against the
     * bank account), and marks both as paid.
     *
     * Guards against re-linking a transaction that's already been claimed by
     * another process (a posting rule, another match, a manual booking) —
     * without this, linking an already-posted transaction to an invoice would
     * silently leave the old (wrong) journal entry in place while also marking
     * the invoice paid without ever clearing its liability/receivable account.
     *
     * @throws \RuntimeException  When the transaction isn't linkable, or the
     *                             fiscal year / journal series / chart accounts
     *                             needed to book the payment aren't available.
     */
    private function linkAndPay(BankTransaction $tx, Invoice $invoice): void
    {
        if ($tx->status !== BankTransaction::STATUS_UNMATCHED || $tx->journal_entry_id !== null) {
            throw new \RuntimeException(
                'Transaktionen är redan matchad eller bokförd — kan inte kopplas till en faktura.'
            );
        }

        DB::transaction(function () use ($tx, $invoice) {
            $entry = $this->bookPayment($tx, $invoice);

            $tx->update([
                'invoice_id'       => $invoice->id,
                'status'           => BankTransaction::STATUS_MATCHED,
                'journal_entry_id' => $entry->id,
            ]);

            $invoice->update([
                'status'                   => Invoice::STATUS_PAID,
                'paid_at'                  => now(),
                'payment_journal_entry_id' => $entry->id,
            ]);
        });

        $this->notifyKassor($tx, $invoice);
    }

    /**
     * Books the clearing entry for an invoice payment:
     *   Payable (leverantörsfaktura):    Debet 2440 Leverantörsskulder / Kredit bank
     *   Receivable (kundfaktura):        Debet bank / Kredit 1510 Kundfordringar
     * Uses the dedicated payment series (JournalSeriesSeeder: E "ap_payment" /
     * C "ar_payment") — the same series the invoice-posting flow in
     * payable|receivable/show.blade.php expects payments to land in.
     */
    private function bookPayment(BankTransaction $tx, Invoice $invoice): JournalEntry
    {
        $date = $tx->booking_date ?? $tx->transaction_date;
        if (! $date) {
            throw new \RuntimeException('Transaktionen saknar datum.');
        }

        $fiscalYear = FiscalYear::where('starts_at', '<=', $date)
            ->where('ends_at', '>=', $date)
            ->where('is_locked', false)
            ->first();

        if (! $fiscalYear) {
            throw new \RuntimeException("Inget öppet räkenskapsår täcker datumet {$date->toDateString()}.");
        }

        $isPayable = $invoice->isPayable();

        $series = JournalSeries::where('default_for', $isPayable ? 'ap_payment' : 'ar_payment')->first();
        if (! $series) {
            $purpose = $isPayable ? 'leverantörsbetalningar (default_for=ap_payment)' : 'kundinbetalningar (default_for=ar_payment)';
            throw new \RuntimeException("Verifikationsserie för {$purpose} saknas. Kör JournalSeriesSeeder.");
        }

        $clearingCode    = $isPayable ? '2440' : '1510';
        $clearingAccount = Account::where('code', $clearingCode)->first();
        if (! $clearingAccount) {
            $label = $isPayable ? 'Leverantörsskulder' : 'Kundfordringar';
            throw new \RuntimeException("Konto {$clearingCode} ({$label}) saknas i kontoplanen.");
        }

        $tx->loadMissing('bankAccount.account');
        $bankAccount = $tx->bankAccount->account;
        if (! $bankAccount) {
            throw new \RuntimeException('Bankkontot saknar kopplat redovisningskonto.');
        }

        $amount = abs($tx->amount);

        $nextNumber = JournalEntry::where('fiscal_year_id', $fiscalYear->id)
            ->where('series', $series->code)
            ->lockForUpdate()
            ->max('number') + 1;

        $party = $isPayable ? $invoice->vendor?->name : $invoice->customer?->name;

        $entry = JournalEntry::create([
            'fiscal_year_id'   => $fiscalYear->id,
            'series'           => $series->code,
            'number'           => $nextNumber,
            'date'             => $date,
            'description'      => ($isPayable ? 'Betalning lev.faktura ' : 'Inbetalning kundfaktura ')
                . ($party ?? '') . ($invoice->invoice_number ? " #{$invoice->invoice_number}" : ''),
            'source_type'      => Invoice::class,
            'source_id'        => $invoice->id,
            'is_locked'        => false,
            'requires_receipt' => false,
        ]);

        $lines = $isPayable
            ? [
                ['account_id' => $clearingAccount->id, 'debit' => $amount, 'credit' => 0,      'cost_center_id' => null],
                ['account_id' => $bankAccount->id,      'debit' => 0,      'credit' => $amount, 'cost_center_id' => null],
            ]
            : [
                ['account_id' => $bankAccount->id,      'debit' => $amount, 'credit' => 0,      'cost_center_id' => null],
                ['account_id' => $clearingAccount->id,  'debit' => 0,       'credit' => $amount, 'cost_center_id' => null],
            ];

        $entry->lines()->createMany($lines);

        return $entry;
    }

    // ── Notification ──────────────────────────────────────────────────────────

    private function notifyKassor(BankTransaction $tx, Invoice $invoice): void
    {
        $recipients = User::permission('accounting.manage')->get();

        foreach ($recipients as $user) {
            try {
                Mail::to($user->email)->send(new InvoiceMatchedMail($tx, $invoice));
            } catch (\Throwable) {
                logger()->warning("InvoiceMatchedMail failed for tx #{$tx->id}, invoice #{$invoice->id}");
            }
        }
    }

    // ── Query helpers ─────────────────────────────────────────────────────────

    private function unpaidPayable()
    {
        return Invoice::payable()
            ->whereIn('status', [Invoice::STATUS_POSTED])
            ->whereNotNull('vendor_id');
    }

    private function unpaidReceivable()
    {
        return Invoice::receivable()
            ->whereIn('status', [Invoice::STATUS_POSTED]);
    }
}
