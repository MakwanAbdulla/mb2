<?php

namespace Database\Seeders;

use App\Models\Accounting\Account;
use App\Models\Accounting\BankAccount;
use App\Models\Accounting\PaymentProvider;
use Illuminate\Database\Seeder;

/**
 * Seeds the standard payment providers used by Armagedon.
 *
 * Each provider requires a transit/clearing account (account_id) that records
 * incoming funds before they settle to the bank. Settlement bank account is
 * set where known; leave null and configure in the UI for the rest.
 *
 * Must run after AccountSeeder (needs 1931-1935) and AccountSeeder's
 * seedBankAccounts (needs BankAccount records).
 */
class PaymentProviderSeeder extends Seeder
{
    public function run(): void
    {
        $swishAccount  = Account::where('code', '1931')->first();
        $sumupAccount  = Account::where('code', '1934')->first();
        $stripeAccount = Account::where('code', '1935')->first();

        // The Swish bank account is the settlement target for both Swish and SumUp
        $swishBank = BankAccount::where('swish_number', '1236700413')->first(); // Kiosk Swish

        $providers = [
            [
                'name'                      => 'Swish',
                'type'                      => PaymentProvider::TYPE_ONLINE,
                'account_id'                => $swishAccount?->id,
                'settlement_bank_account_id' => $swishBank?->id,
                'is_active'                 => true,
                'notes'                     => 'Swish Handel via Swedbank, nummer 1236700413',
            ],
            [
                'name'                      => 'SumUp',
                'type'                      => PaymentProvider::TYPE_CARD_TERMINAL,
                'account_id'                => $sumupAccount?->id,
                'settlement_bank_account_id' => $swishBank?->id,
                'is_active'                 => true,
                'notes'                     => 'Kortterminal. Avräknas till Swish-kontot.',
            ],
            [
                'name'                      => 'Stripe',
                'type'                      => PaymentProvider::TYPE_ONLINE,
                'account_id'                => $stripeAccount?->id,
                'settlement_bank_account_id' => null,
                'is_active'                 => false, // aktiveras när Stripe går live
                'notes'                     => 'Online-betalningar. Konfigurera settlement-konto när aktivt.',
            ],
        ];

        foreach ($providers as $data) {
            if ($data['account_id'] === null) {
                $this->command->warn("PaymentProviderSeeder: konto saknas för {$data['name']}, hoppar över.");
                continue;
            }

            PaymentProvider::updateOrCreate(['name' => $data['name']], $data);
        }
    }
}
