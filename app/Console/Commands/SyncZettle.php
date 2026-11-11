<?php

namespace App\Console\Commands;

use App\Models\Accounting\BankAccount;
use App\Models\Accounting\ZettlePayout;
use App\Models\Accounting\ZettleTransaction;
use App\Models\SystemSetting;
use App\Services\Accounting\ZettleClient;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncZettle extends Command
{
    protected $signature   = 'zettle:sync {--bank-account=}';
    protected $description = 'Fetch new Zettle purchases and store them';

    public function handle(ZettleClient $client): int
    {
        $bankAccountId = $this->option('bank-account')
            ? (int) $this->option('bank-account')
            : BankAccount::where('account_number', '43196583')->value('id');

        if (! $bankAccountId) {
            $this->error('No bank account found. Pass --bank-account=ID or configure a swish_type=handel account.');
            return self::FAILURE;
        }

        $cursor   = SystemSetting::get('zettle.last_purchase_hash') ?: null;
        $imported = 0;
        $skipped  = 0;

        do {
            $result    = $client->fetchPurchases($cursor);
            $purchases = $result['purchases'];
            $nextCursor = $result['lastPurchaseHash'];

            foreach ($purchases as $p) {
                $uuid = $p['purchaseUUID'] ?? $p['purchaseUUID1'] ?? null;
                if (! $uuid) {
                    continue;
                }

                if (ZettleTransaction::where('purchase_uuid', $uuid)->exists()) {
                    $skipped++;
                    continue;
                }

                $this->storePurchase($bankAccountId, $p);
                $imported++;
            }

            $cursor = $nextCursor;
        } while ($nextCursor !== null);

        if ($cursor) {
            SystemSetting::set('zettle.last_purchase_hash', $cursor);
        }

        $payouts = $this->syncPayouts($client, $bankAccountId);

        $this->info("Zettle sync done — {$imported} purchases imported, {$skipped} skipped, {$payouts} payouts stored.");

        return self::SUCCESS;
    }

    private function syncPayouts(ZettleClient $client, int $bankAccountId): int
    {
        $syncedUntil = SystemSetting::get('zettle.payouts_synced_until', '2024-01-01');
        $today       = now()->toDateString();
        $period      = Carbon::parse($syncedUntil)->startOfMonth();
        $stored      = 0;

        while ($period->toDateString() <= $today) {
            $start   = $period->toDateString();
            $end     = $period->copy()->endOfMonth()->min(now())->toDateString();
            $entries = $client->fetchLiquidPayouts($start, $end);

            foreach ($entries as $entry) {
                if ($this->storePayout($bankAccountId, $entry)) {
                    $stored++;
                }
            }

            $period->addMonth();
        }

        SystemSetting::set('zettle.payouts_synced_until', $today);

        return $stored;
    }

    private function storePayout(int $bankAccountId, array $entry): bool
    {
        // LIQUID account: PAYOUT entries have negative amount (leaving LIQUID to bank).
        $amountOre = abs((int) ($entry['amount'] ?? 0));
        if ($amountOre === 0) {
            return false;
        }

        $uuid = $entry['originatingTransactionUuid'] ?? null;
        $date = substr($entry['timestamp'] ?? '', 0, 10);

        if (! $date) {
            return false;
        }

        // Dedup by UUID when available, by date+amount otherwise.
        if ($uuid) {
            if (ZettlePayout::where('payout_uuid', $uuid)->exists()) {
                return false;
            }
        } else {
            if (ZettlePayout::where('bank_account_id', $bankAccountId)
                ->where('payout_date', $date)
                ->where('amount_ore', $amountOre)
                ->exists()) {
                return false;
            }
        }

        ZettlePayout::create([
            'bank_account_id' => $bankAccountId,
            'payout_uuid'     => $uuid,
            'payout_date'     => $date,
            'amount_ore'      => $amountOre,
            'raw'             => $entry,
        ]);

        return true;
    }

    private function storePurchase(int $bankAccountId, array $p): void
    {
        $payment = collect($p['payments'] ?? [])->first() ?? [];

        // Zettle API amounts are in minor units (öre for SEK).
        // Fee per transaction is not in the purchases endpoint — reconcile via finance API later.
        $grossOre = (int) ($p['amount'] ?? 0);
        $feeOre   = 0;

        $purchaseDate = substr($p['timestamp'] ?? '', 0, 10);
        $purchaseTime = substr($p['timestamp'] ?? '', 11, 5);

        ZettleTransaction::create([
            'purchase_uuid'   => $p['purchaseUUID'] ?? $p['purchaseUUID1'],
            'bank_account_id' => $bankAccountId,
            'purchase_date'   => $purchaseDate,
            'purchase_time'   => $purchaseTime,
            'receipt_number'  => $p['receiptCopyAllowed'] ?? null,
            'gross_ore'       => $grossOre,
            'fee_ore'         => $feeOre,
            'net_ore'         => $grossOre - $feeOre,
            'payment_type'    => $payment['type'] ?? null,
            'card_type'       => $payment['cardType'] ?? null,
            'card_last_four'  => isset($payment['maskedPan']) ? substr($payment['maskedPan'], -4) : null,
            'staff'           => $p['userDisplayName'] ?? null,
            'description'     => collect($p['products'] ?? [])->pluck('name')->join(', ') ?: null,
            'raw'             => $p,
        ]);
    }
}
