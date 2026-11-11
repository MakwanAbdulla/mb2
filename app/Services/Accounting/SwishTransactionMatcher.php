<?php

namespace App\Services\Accounting;

use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\SwishImport;
use App\Models\Accounting\SwishTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Imports a parsed Swish report and matches each row against existing BankTransactions.
 *
 * Matching criteria:
 *   - Same bank_account_id
 *   - description contains the sender phone number (e.g. "Swish +46761603014")
 *   - amount_ore matches exactly
 *   - transaction_date within ±1 day of value_date
 *
 * Can also be re-run after the fact to pick up bank transactions imported later.
 */
class SwishTransactionMatcher
{
    /** @param list<array> $rows  Output from SwishReportParser::parse() */
    public function import(
        int    $bankAccountId,
        array  $rows,
        string $fileHash,
        string $filename,
        ?string $periodFrom,
        ?string $periodTo,
    ): SwishImport {
        // Wrapped in a transaction so a failure partway through (e.g. an unexpected
        // parsing edge case) rolls back entirely, rather than leaving a partial
        // SwishImport whose file_hash then blocks the file from ever being re-imported.
        return DB::transaction(function () use ($bankAccountId, $rows, $fileHash, $filename, $periodFrom, $periodTo) {
            $import = SwishImport::create([
                'bank_account_id' => $bankAccountId,
                'file_hash'       => $fileHash,
                'filename'        => $filename,
                'period_from'     => $periodFrom,
                'period_to'       => $periodTo,
                'row_count'       => count($rows),
                'matched_count'   => 0,
            ]);

            $matched = 0;

            foreach ($rows as $row) {
                $hash = SwishTransaction::importHash($bankAccountId, $row);

                // Skip exact duplicates from a previous import
                if (SwishTransaction::where('import_hash', $hash)->exists()) {
                    continue;
                }

                $bankTxId = $this->findBankTransaction($bankAccountId, $row)?->id;
                if ($bankTxId) {
                    $matched++;
                }

                SwishTransaction::create([
                    'swish_import_id'    => $import->id,
                    'bank_account_id'    => $bankAccountId,
                    'bank_transaction_id' => $bankTxId,
                    'row_number'         => $row['row_number'],
                    'booking_date'       => $row['booking_date'],
                    'transaction_date'   => $row['transaction_date'],
                    'value_date'         => $row['value_date'],
                    'swish_number'       => $row['swish_number'],
                    'sender_phone'       => $row['sender_phone'],
                    'sender_name'        => $row['sender_name'] ?: null,
                    'message'            => $row['message'] ?: null,
                    'transaction_time'   => $row['transaction_time'] ?: null,
                    'amount_ore'         => $row['amount_ore'],
                    'order_reference'    => $row['order_reference'] ?: null,
                    'import_hash'        => $hash,
                ]);
            }

            $import->update(['matched_count' => $matched]);

            return $import;
        });
    }

    /**
     * Re-run matching for all unmatched SwishTransactions on a given bank account.
     * Call this after importing new bank statement rows.
     *
     * @return int  Number of newly matched rows
     */
    public function rematch(int $bankAccountId): int
    {
        $matched = 0;

        SwishTransaction::where('bank_account_id', $bankAccountId)
            ->whereNull('bank_transaction_id')
            ->each(function (SwishTransaction $st) use (&$matched) {
                $tx = $this->findBankTransaction($st->bank_account_id, [
                    'sender_phone' => $st->sender_phone,
                    'amount_ore'   => $st->amount_ore,
                    'value_date'   => $st->value_date->toDateString(),
                ]);

                if ($tx) {
                    $st->update(['bank_transaction_id' => $tx->id]);
                    $matched++;
                }
            });

        return $matched;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function findBankTransaction(int $bankAccountId, array $row): ?BankTransaction
    {
        $phone = $row['sender_phone'];          // e.g. "+46761603014"
        $date  = Carbon::parse($row['value_date']);

        return BankTransaction::where('bank_account_id', $bankAccountId)
            ->where('amount', $row['amount_ore'])
            ->where('description', 'like', "%{$phone}%")
            ->whereBetween('transaction_date', [
                $date->copy()->subDay()->toDateString(),
                $date->copy()->addDay()->toDateString(),
            ])
            ->first();
    }
}
