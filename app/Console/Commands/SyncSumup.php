<?php

namespace App\Console\Commands;

use App\Models\Accounting\BankAccount;
use App\Models\Accounting\SumupPayout;
use App\Models\Accounting\SumupTransaction;
use App\Models\SystemSetting;
use App\Services\Accounting\SumupClient;
use App\Services\Accounting\SumupReconciler;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncSumup extends Command
{
    protected $signature   = 'sumup:sync {--bank-account=}';
    protected $description = 'Fetch new SumUp transactions and payouts';

    public function handle(SumupClient $client, SumupReconciler $reconciler): int
    {
        $bankAccountId = $this->option('bank-account')
            ? (int) $this->option('bank-account')
            : BankAccount::where('account_number', '43196583')->value('id');

        if (! $bankAccountId) {
            $this->error('No bank account found. Pass --bank-account=ID.');
            return self::FAILURE;
        }

        [$txImported, $txSkipped] = $this->syncTransactions($client, $bankAccountId);
        [$poImported, $poSkipped] = $this->syncPayouts($client, $bankAccountId);
        $matched                  = $reconciler->reconcileAll($bankAccountId);

        $this->info("SumUp sync done — transactions: {$txImported} imported, {$txSkipped} skipped — payouts: {$poImported} imported, {$poSkipped} skipped — {$matched} bank transactions reconciled.");

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Transactions
    // -------------------------------------------------------------------------

    private function syncTransactions(SumupClient $client, int $bankAccountId): array
    {
        $cursor   = SystemSetting::get('sumup.oldest_ref') ?: null;
        $imported = 0;
        $skipped  = 0;

        do {
            $result  = $client->fetchTransactions($cursor);
            $items   = $result['items'];
            $cursor  = $result['nextRef'];

            foreach ($items as $item) {
                $id = $item['transaction_id'] ?? $item['id'] ?? null;
                if (! $id) {
                    continue;
                }

                if (SumupTransaction::where('transaction_id', $id)->exists()) {
                    $skipped++;
                    continue;
                }

                $this->storeTransaction($bankAccountId, $item);
                $imported++;
            }
        } while ($cursor !== null);

        // cursor ends up null after the last page — persist the last known ref
        // by re-reading the latest transaction's ref from DB on next run instead.
        // For now: do nothing (re-scan is safe due to dedup).

        return [$imported, $skipped];
    }

    private function storeTransaction(int $bankAccountId, array $item): void
    {
        SumupTransaction::create([
            'transaction_id'   => $item['transaction_id'] ?? $item['id'],
            'transaction_code' => $item['transaction_code'] ?? '',
            'bank_account_id'  => $bankAccountId,
            'purchased_at'     => $item['timestamp'] ?? null,
            'payout_date'      => $item['payout_date'] ?? null,
            'amount_ore'       => (int) round(($item['amount'] ?? 0) * 100),
            'currency'         => $item['currency'] ?? 'SEK',
            'status'           => $item['status'] ?? 'UNKNOWN',
            'payment_type'     => $item['payment_type'] ?? null,
            'entry_mode'       => $item['entry_mode'] ?? null,
            'card_type'        => $item['card_type'] ?? null,
            'product_summary'  => $item['product_summary'] ?? null,
            'staff_email'      => $item['user'] ?? null,
            'raw'              => $item,
        ]);
    }

    // -------------------------------------------------------------------------
    // Payouts
    // -------------------------------------------------------------------------

    private function syncPayouts(SumupClient $client, int $bankAccountId): array
    {
        // Fetch month by month from first known payout date to today.
        // Avoids hitting the 1000-item limit per request.
        $from     = Carbon::parse(SystemSetting::get('sumup.payouts_synced_until') ?? '2023-07-01');
        $until    = now();
        $imported = 0;
        $skipped  = 0;

        $month = $from->copy()->startOfMonth();
        while ($month->lte($until)) {
            $start = $month->toDateString();
            $end   = $month->copy()->endOfMonth()->toDateString();

            $payouts = $client->fetchPayouts($start, $end);

            foreach ($payouts as $payout) {
                $id = $payout['id'] ?? null;
                if (! $id) {
                    continue;
                }

                if (SumupPayout::where('sumup_id', $id)->exists()) {
                    $skipped++;
                    continue;
                }

                SumupPayout::create([
                    'sumup_id'       => $id,
                    'bank_account_id'=> $bankAccountId,
                    'payout_date'    => $payout['date'],
                    'transaction_code' => $payout['transaction_code'] ?? '',
                    'reference'      => $payout['reference'] ?? '',
                    'status'         => $payout['status'] ?? 'UNKNOWN',
                    'type'           => $payout['type'] ?? 'PAYOUT',
                    'currency'       => $payout['currency'] ?? 'SEK',
                    'amount_ore'     => (int) round(($payout['amount'] ?? 0) * 100),
                    'fee_ore'        => (int) round(($payout['fee'] ?? 0) * 100),
                    'raw'            => $payout,
                ]);

                $imported++;
            }

            $month->addMonth();
        }

        SystemSetting::set('sumup.payouts_synced_until', $until->toDateString());

        return [$imported, $skipped];
    }
}
