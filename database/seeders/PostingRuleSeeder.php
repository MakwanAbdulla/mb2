<?php

namespace Database\Seeders;

use App\Models\Accounting\Account;
use App\Models\Accounting\BankAccount;
use App\Models\Accounting\CostCenter;
use App\Models\Accounting\PostingRule;
use App\Models\Accounting\Vendor;
use Illuminate\Database\Seeder;

class PostingRuleSeeder extends Seeder
{
    public function run(): void
    {
        $kiosk = CostCenter::where('code', 'KIOSK')->first();

        $a1934 = Account::where('code', '1934')->first(); // SumUp transit
        $a3000 = Account::where('code', '3000')->first(); // Kiosk och merch
        $a4000 = Account::where('code', '4000')->first(); // Inköp av varor från Sverige
        $a6060 = Account::where('code', '6060')->first(); // Kreditförsäljningskostnader

        if (! $a1934 || ! $a3000 || ! $a6060 || ! $kiosk) {
            $this->command->warn('PostingRuleSeeder: saknar konton eller kostnadsställe — hoppar över.');
            return;
        }

        $kioskBankAccount = BankAccount::where('account_number', '43196583')->first();
        $snabbgross       = Vendor::where('code', '40')->first();

        // ── Snabbgross (bank_transaction rule) ───────────────────────────────────
        if ($a4000 && $kioskBankAccount && $snabbgross) {
            PostingRule::updateOrCreate(
                ['name' => 'Snabbgross'],
                [
                    'trigger'            => PostingRule::TRIGGER_BANK_TRANSACTION,
                    'bank_account_id'    => $kioskBankAccount->id,
                    'transaction_type'   => 'debit',
                    'keyword'            => 'Snabbgross',
                    'counter_account_id' => $a4000->id,
                    'vendor_id'          => $snabbgross->id,
                    'cost_center_id'     => $kiosk->id,
                    'series'             => 'U',
                    'requires_receipt'   => true,
                    'is_active'          => true,
                    'sort_order'         => 0,
                ]
            );
        }

        // ── SumUp inbox_email rules ───────────────────────────────────────────
        //
        // Keyword is matched against BOTH the email subject AND the attachment filename.
        // Priority order (sort_order): more specific rules first.
        //
        // Rule design:
        //   Daily email  → only daily-payout-report.pdf auto-books (income, 1934/3000)
        //   Monthly email → only monthly-fee-invoice.pdf auto-books (cost, 6060/3000)
        //   Supporting docs (payments-report, monthly payout-report) → no rule, stored only.

        // SumUp faktura (sort 10): keyword "invoice" matches the monthly fee invoice.
        //   File: "...-monthly-fee-invoice.pdf"
        //   Booking: Debet 6060 (card sales costs) / Kredit 3000 (Kiosk income), KST KIOSK
        PostingRule::updateOrCreate(
            ['name' => 'SumUp faktura'],
            [
                'trigger'            => PostingRule::TRIGGER_INBOX_EMAIL,
                'from_email_pattern' => '@notification.sumup.com',
                'keyword'            => 'invoice',
                'debit_account_id'   => $a6060->id,
                'credit_account_id'  => $a3000->id,
                'cost_center_id'     => $kiosk->id,
                'series'             => 'U',
                'is_active'          => true,
                'sort_order'         => 10,
            ]
        );

        // SumUp betalningsrapport (sort 20): keyword "daily-payout" matches only the daily
        //   payout summary (not monthly payout-report, not payments-report detail lists).
        //   File: "...-daily-payout-report.pdf"
        //   Booking: Debet 1934 (SumUp transit) / Kredit 3000 (Kiosk income), KST KIOSK
        PostingRule::updateOrCreate(
            ['name' => 'SumUp betalningsrapport'],
            [
                'trigger'            => PostingRule::TRIGGER_INBOX_EMAIL,
                'from_email_pattern' => '@notification.sumup.com',
                'keyword'            => 'daily-payout',
                'debit_account_id'   => $a1934->id,
                'credit_account_id'  => $a3000->id,
                'cost_center_id'     => $kiosk->id,
                'series'             => 'U',
                'is_active'          => true,
                'sort_order'         => 20,
            ]
        );
    }
}
