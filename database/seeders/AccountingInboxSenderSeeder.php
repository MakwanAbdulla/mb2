<?php

namespace Database\Seeders;

use App\Models\Accounting\AccountingInboxSender;
use App\Models\Accounting\PaymentProvider;
use Illuminate\Database\Seeder;

class AccountingInboxSenderSeeder extends Seeder
{
    public function run(): void
    {
        $sumup = PaymentProvider::where('name', 'SumUp')->first();

        // Shared forwarding address — everything forwarded here is trusted.
        // The original sender (extracted from email body) identifies the provider.
        AccountingInboxSender::updateOrCreate(
            ['pattern' => 'ekonomi@armagedon.se'],
            [
                'type'                => AccountingInboxSender::TYPE_ALLOWED,
                'label'               => 'Ekonomi (vidarebefordran)',
                'notes'               => 'Föreningens gemensamma ekonomiadress. Vidarebefordrar fakturor från leverantörer.',
                'payment_provider_id' => null,
                'is_active'           => true,
                'created_by'          => null,
            ]
        );

        // SumUp notification address — direct sender (not forwarded)
        AccountingInboxSender::updateOrCreate(
            ['pattern' => '@notification.sumup.com'],
            [
                'type'                => AccountingInboxSender::TYPE_ALLOWED,
                'label'               => 'SumUp',
                'notes'               => 'Betalningsrapporter och fakturor från SumUp.',
                'payment_provider_id' => $sumup?->id,
                'is_active'           => true,
                'created_by'          => null,
            ]
        );
    }
}
