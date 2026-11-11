<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class MembershipPaymentSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'key'         => 'payment.swish_number',
                'category'    => 'payment',
                'label'       => 'Swish-nummer',
                'value'       => '',
                'type'        => 'string',
                'description' => 'Föreningens Swish-nummer. Lämna tomt för att dölja Swish-alternativet.',
            ],
            [
                'key'         => 'payment.swish_type',
                'category'    => 'payment',
                'label'       => 'Swish-typ',
                'value'       => 'private',
                'type'        => 'string',
                'description' => 'private = Swish Privat (07…, manuell), company = Swish Företag (123…, manuell), merchant = Swish Handel (123…, API kräver certifikat).',
            ],
            [
                'key'         => 'payment.bankgiro',
                'category'    => 'payment',
                'label'       => 'Bankgironummer',
                'value'       => '',
                'type'        => 'string',
                'description' => 'Föreningens bankgironummer (format: XXX-XXXX). Lämna tomt för att dölja BG-alternativet.',
            ],
            [
                'key'         => 'payment.bankgiro_reference_type',
                'category'    => 'payment',
                'label'       => 'Bankgiro referenstyp',
                'value'       => 'message',
                'type'        => 'string',
                'description' => 'message = fritt meddelande, ocr = strukturerat OCR-nummer med kontrollsiffra för automatisk filimport.',
            ],
            [
                'key'         => 'payment.stripe_enabled',
                'category'    => 'payment',
                'label'       => 'Stripe aktiverat',
                'value'       => '0',
                'type'        => 'boolean',
                'description' => 'Aktivera kortbetalning via Stripe.',
            ],
            [
                'key'         => 'payment.stripe_publishable_key',
                'category'    => 'payment',
                'label'       => 'Stripe publiceringsnyckel',
                'value'       => '',
                'type'        => 'string',
                'description' => 'Stripe publishable key (pk_live_… eller pk_test_…). Visas i frontend.',
            ],
            [
                'key'         => 'payment.stripe_secret_key',
                'category'    => 'payment',
                'label'       => 'Stripe hemlig nyckel',
                'value'       => '',
                'type'        => 'encrypted',
                'description' => 'Stripe secret key (sk_live_… eller sk_test_…). Hålls hemlig på servern.',
            ],
            [
                'key'         => 'payment.stripe_webhook_secret',
                'category'    => 'payment',
                'label'       => 'Stripe webhook-hemlighet',
                'value'       => '',
                'type'        => 'encrypted',
                'description' => 'Stripe webhook signing secret (whsec_…). Verifierar inkommande Stripe-events.',
            ],
            [
                'key'         => 'payment.reference_prefix',
                'category'    => 'payment',
                'label'       => 'Referensprefix',
                'value'       => 'ARM',
                'type'        => 'string',
                'description' => 'Prefix på betalningsreferensen (t.ex. ARM → ARM-00042).',
            ],
        ];

        foreach ($defaults as $row) {
            SystemSetting::firstOrCreateSetting($row);
        }

        $this->seedLocalSwishTestCertificate();
        $this->seedLocalStripeTestKeys();
    }

    /**
     * Local dev convenience only — never runs on staging/production.
     * Stripe keys are account-specific (unlike Swish's shared public test
     * certificate), so they must never be hardcoded in a committed seeder.
     * Instead, reads them from the developer's own .env (gitignored, never
     * committed) and copies them into system_settings so `migrate:fresh
     * --seed` doesn't lose the Stripe test keys entered via the settings
     * UI. If STRIPE_KEY/STRIPE_SECRET aren't set locally, this is a no-op —
     * the app itself never reads these env vars, only this seeder does.
     */
    private function seedLocalStripeTestKeys(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $publishableKey = (string) env('STRIPE_KEY', '');
        $secretKey      = (string) env('STRIPE_SECRET', '');
        $webhookSecret  = (string) env('STRIPE_WEBHOOK_SECRET', '');

        if ($publishableKey === '' && $secretKey === '') {
            return;
        }

        $map = [
            'payment.stripe_publishable_key' => ['value' => $publishableKey, 'untouched' => '', 'type' => SystemSetting::TYPE_STRING],
            'payment.stripe_secret_key'      => ['value' => $secretKey, 'untouched' => '', 'type' => SystemSetting::TYPE_ENCRYPTED],
            'payment.stripe_webhook_secret'  => ['value' => $webhookSecret, 'untouched' => '', 'type' => SystemSetting::TYPE_ENCRYPTED],
            'payment.stripe_enabled'         => ['value' => '1', 'untouched' => '0', 'type' => SystemSetting::TYPE_BOOLEAN],
        ];

        foreach ($map as $key => $row) {
            $setting = SystemSetting::where('key', $key)->first();
            if ($setting && $setting->value === $row['untouched']) {
                $setting->update([
                    'value' => SystemSetting::prepareValueForStorage($row['value'], $row['type']),
                    'type'  => $row['type'],
                ]);
            }
        }
    }

    /**
     * Local dev convenience only — never runs on staging/production, and
     * firstOrCreate() means it never overwrites a value an admin already
     * configured through the settings UI. Points Swish Handel at Swish's
     * own public MSS sandbox test certificate (payee alias 1234679304) so
     * `migrate:fresh --seed` doesn't lose the upload done via
     * /admin/installningar every time.
     */
    private function seedLocalSwishTestCertificate(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $targetPath = storage_path('app/private/swish/certificate.p12');

        if (! is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0775, true);
        }
        if (! file_exists($targetPath)) {
            copy(resource_path('certs/swish-test-certificate.p12'), $targetPath);
        }

        $rows = [
            ['key' => 'payment.swish_certificate_path',       'category' => 'payment', 'label' => 'Swish-certifikatsökväg',   'value' => $targetPath, 'type' => 'string',    'description' => 'Sökväg till Swish Handel-certifikatet (.p12). Sätts via uppladdning i /admin/installningar.'],
            ['key' => 'payment.swish_certificate_passphrase', 'category' => 'payment', 'label' => 'Swish-certifikatlösenord', 'value' => 'swish',     'type' => 'encrypted', 'description' => 'Lösenord för Swish Handel-certifikatet.'],
            ['key' => 'payment.swish_emulator_enabled',       'category' => 'payment', 'label' => 'Swish-emulator aktiverad', 'value' => '0',         'type' => 'boolean',   'description' => 'Hoppar över Swish API helt — ingen riktig betalning sker. Endast för dev/test.'],
        ];

        foreach ($rows as $row) {
            SystemSetting::firstOrCreateSetting($row);
        }

        // swish_number/swish_type come from the generic $defaults above as
        // 'private'/empty — only switch them to the sandbox test values when
        // nothing else has touched them yet (i.e. this is a truly fresh seed).
        $swishNumber = SystemSetting::where('key', 'payment.swish_number')->first();
        if ($swishNumber && $swishNumber->value === '') {
            $swishNumber->update(['value' => '1234679304']);
            SystemSetting::where('key', 'payment.swish_type')->update(['value' => 'merchant']);
        }
    }
}
