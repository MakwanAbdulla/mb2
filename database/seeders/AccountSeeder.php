<?php

namespace Database\Seeders;

use App\Models\Accounting\Account;
use App\Models\Accounting\BankAccount;
use Illuminate\Database\Seeder;

/**
 * Seeds the chart of accounts (kontoplan) based on Armagedon's actual
 * Fortnox kontoplan, exported 2026-04-12.
 *
 * Types: asset | liability | equity | income | expense
 */
class AccountSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->accounts() as $data) {
            Account::updateOrCreate(['code' => $data['code']], $data);
        }

        $this->seedBankAccounts();
    }

    private function seedBankAccounts(): void
    {
        $swish  = Account::where('code', '1931')->first();
        $kiosk  = Account::where('code', '1932')->first();
        $medlem = Account::where('code', '1933')->first();

        $bankAccounts = [
            [
                'name'            => 'Kiosk 8032-5,4 319 658-3',
                'account_id'      => $kiosk?->id,
                'bank_name'       => 'Swedbank',
                'clearing_number' => '8032',
                'account_number'  => '43196583',
                'iban'            => 'SE2280000803250043196583',
                'swish_number'    => '1236700413',
                'swish_type'      => null,
                'currency'        => 'SEK',
                'is_active'       => true,
            ],
            [
                'name'            => 'Medlem 8032-5,4 319 657-5',
                'account_id'      => $medlem?->id,
                'bank_name'       => 'Swedbank',
                'clearing_number' => '8032',
                'account_number'  => '43196575',
                'iban'            => 'SE4480000803250043196575',
                'bankgiro'        => '5702-0778',
                'currency'        => 'SEK',
                'is_active'       => true,
            ],
            [
                'name'            => 'Swish 8032-5,4 319 659-1',
                'account_id'      => $swish?->id,
                'bank_name'       => 'Swedbank',
                'clearing_number' => '8032',
                'account_number'  => '43196591',
                'iban'            => 'SE9780000803250043196591',
                'swish_number'    => '1233584729',
                'swish_type'      => 'företag',
                'currency'        => 'SEK',
                'is_active'       => true,
            ],
        ];

        foreach ($bankAccounts as $data) {
            BankAccount::updateOrCreate(['name' => $data['name']], $data);
        }
    }

    private function accounts(): array
    {
        return [
            // ── 1 Tillgångar ─────────────────────────────────────────────────
            ['code' => '1120', 'name' => 'Förbättringsutgifter på annans fastighet',           'type' => 'asset',   'vat_code' => null,   'is_group' => false],
            ['code' => '1129', 'name' => 'Ackumulerade avskrivningar på förbättringsutgifter', 'type' => 'asset',   'vat_code' => null,   'is_group' => false],
            ['code' => '1220', 'name' => 'Inventarier och verktyg',                            'type' => 'asset',   'vat_code' => null,   'is_group' => false],
            ['code' => '1250', 'name' => 'Datorer',                                            'type' => 'asset',   'vat_code' => null,   'is_group' => false],
            ['code' => '1258', 'name' => 'Ackumulerade nedskrivningar på datorer',             'type' => 'asset',   'vat_code' => null,   'is_group' => false],
            ['code' => '1259', 'name' => 'Ackumulerade avskrivningar på datorer',              'type' => 'asset',   'vat_code' => null,   'is_group' => false],
            ['code' => '1291', 'name' => 'Konst och liknande tillgångar',                      'type' => 'asset',   'vat_code' => null,   'is_group' => false],
            ['code' => '1460', 'name' => 'Lager av handelsvaror',                              'type' => 'asset',   'vat_code' => null,   'is_group' => false],
            ['code' => '1510', 'name' => 'Kundfordringar',                                     'type' => 'asset',   'vat_code' => null,   'is_group' => false],
            ['code' => '1580', 'name' => 'Fordringar för kontokort och kuponger',              'type' => 'asset',   'vat_code' => null,   'is_group' => false],
            ['code' => '1680', 'name' => 'Andra kortfristiga fordringar',                      'type' => 'asset',   'vat_code' => null,   'is_group' => false],
            ['code' => '1684', 'name' => 'Kortfristiga fordringar hos leverantörer',           'type' => 'asset',   'vat_code' => null,   'is_group' => false],
            ['code' => '1790', 'name' => 'Övriga förutbetalda kostnader och upplupna intäkter','type' => 'asset',   'vat_code' => null,   'is_group' => false],
            ['code' => '1791', 'name' => 'Förutbetalda medlemsavgifter',                       'type' => 'asset',   'vat_code' => null,   'is_group' => false],
            ['code' => '1910', 'name' => 'Kassa',                                              'type' => 'asset',   'vat_code' => null,   'is_group' => false],
            // Bankkonton — kopplade till bank_accounts via account_id
            ['code' => '1931', 'name' => 'Bankkonto Swish',                                    'type' => 'asset',   'vat_code' => null,   'is_group' => false],
            ['code' => '1932', 'name' => 'Bankkonto Kiosk',                                    'type' => 'asset',   'vat_code' => null,   'is_group' => false],
            ['code' => '1933', 'name' => 'Bankkonto Medlem',                                   'type' => 'asset',   'vat_code' => null,   'is_group' => false],
            // Betaltjänstkonton — transiterande innan avräkning till bankkonto
            ['code' => '1934', 'name' => 'SumUp',                                              'type' => 'asset',   'vat_code' => null,   'is_group' => false],
            ['code' => '1935', 'name' => 'Stripe',                                             'type' => 'asset',   'vat_code' => null,   'is_group' => false],

            // ── 2 Eget kapital ────────────────────────────────────────────────
            ['code' => '2085', 'name' => 'Uppskrivningsfond',                                  'type' => 'equity',  'vat_code' => null,   'is_group' => false],
            ['code' => '2091', 'name' => 'Balanserad vinst eller förlust',                     'type' => 'equity',  'vat_code' => null,   'is_group' => false],
            ['code' => '2098', 'name' => 'Vinst eller förlust från föregående år',             'type' => 'equity',  'vat_code' => null,   'is_group' => false],
            ['code' => '2099', 'name' => 'Årets resultat',                                     'type' => 'equity',  'vat_code' => null,   'is_group' => false],

            // ── 2 Skulder ─────────────────────────────────────────────────────
            ['code' => '2420', 'name' => 'Förskott från medlemmar',                            'type' => 'liability','vat_code' => null,   'is_group' => false],
            ['code' => '2422', 'name' => 'Tillgodo, personer',                                 'type' => 'liability','vat_code' => null,   'is_group' => false],
            ['code' => '2423', 'name' => 'Medlemmars utlägg',                                  'type' => 'liability','vat_code' => null,   'is_group' => false],
            ['code' => '2440', 'name' => 'Leverantörsskulder',                                 'type' => 'liability','vat_code' => null,   'is_group' => false],
            ['code' => '2893', 'name' => 'Skulder till närstående personer, kortfristig del',  'type' => 'liability','vat_code' => null,   'is_group' => false],
            ['code' => '2973', 'name' => 'Förutbetalda aktivitet',                             'type' => 'liability','vat_code' => null,   'is_group' => false],
            ['code' => '2990', 'name' => 'Övriga upplupna kostnader och förutbetalda intäkter','type' => 'liability','vat_code' => null,   'is_group' => false],
            ['code' => '2999', 'name' => 'OBS-konto',                                          'type' => 'liability','vat_code' => null,   'is_group' => false],

            // ── 3 Intäkter ────────────────────────────────────────────────────
            ['code' => '3000', 'name' => 'Kiosk och merch',                                    'type' => 'income',  'vat_code' => null,   'is_group' => false],
            ['code' => '3001', 'name' => 'Försäljning inom Sverige, 25 % moms',                'type' => 'income',  'vat_code' => 'MP1',  'is_group' => false],
            ['code' => '3002', 'name' => 'Försäljning inom Sverige, 12 % moms',                'type' => 'income',  'vat_code' => 'MP2',  'is_group' => false],
            ['code' => '3003', 'name' => 'Försäljning inom Sverige, 6 % moms',                 'type' => 'income',  'vat_code' => 'MP3',  'is_group' => false],
            ['code' => '3004', 'name' => 'Försäljning inom Sverige, momsfri',                  'type' => 'income',  'vat_code' => 'MF',   'is_group' => false],
            ['code' => '3021', 'name' => 'Uthyrning, intern',                                  'type' => 'income',  'vat_code' => null,   'is_group' => false],
            ['code' => '3022', 'name' => 'Uthyrning, extern',                                  'type' => 'income',  'vat_code' => null,   'is_group' => false],
            ['code' => '3040', 'name' => 'Entréavgifter',                                      'type' => 'income',  'vat_code' => null,   'is_group' => false],
            ['code' => '3050', 'name' => 'Evenemang',                                          'type' => 'income',  'vat_code' => null,   'is_group' => false],
            ['code' => '3051', 'name' => 'BSK',                                                'type' => 'income',  'vat_code' => null,   'is_group' => false],
            ['code' => '3052', 'name' => '40K-Turneringar',                                    'type' => 'income',  'vat_code' => null,   'is_group' => false],
            ['code' => '3059', 'name' => 'Gästpass',                                           'type' => 'income',  'vat_code' => null,   'is_group' => false],
            ['code' => '3080', 'name' => 'Medlemsavgifter',                                    'type' => 'income',  'vat_code' => null,   'is_group' => false],
            ['code' => '3081', 'name' => 'Verksamhetsavgifter',                                'type' => 'income',  'vat_code' => null,   'is_group' => false],
            ['code' => '3091', 'name' => 'Skåphyra',                                           'type' => 'income',  'vat_code' => null,   'is_group' => false],
            ['code' => '3092', 'name' => 'Accesskort, taggar och nycklar',                     'type' => 'income',  'vat_code' => null,   'is_group' => false],
            ['code' => '3105', 'name' => 'Försäljning varor till land utanför EU',             'type' => 'income',  'vat_code' => 'E',    'is_group' => false],
            ['code' => '3106', 'name' => 'Försäljning varor till annat EU-land, momspliktig', 'type' => 'income',  'vat_code' => 'MP1',  'is_group' => false],
            ['code' => '3108', 'name' => 'Försäljning varor till annat EU-land, momsfri',     'type' => 'income',  'vat_code' => 'VTEU', 'is_group' => false],
            ['code' => '3231', 'name' => 'Försäljning inom byggsektorn, omvänd skattskyldighet','type' => 'income', 'vat_code' => 'OTTU', 'is_group' => false],
            ['code' => '3305', 'name' => 'Försäljning tjänster till land utanför EU',          'type' => 'income',  'vat_code' => 'ÖTEU', 'is_group' => false],
            ['code' => '3308', 'name' => 'Försäljning tjänster till annat EU-land',            'type' => 'income',  'vat_code' => 'FTEU', 'is_group' => false],
            ['code' => '3740', 'name' => 'Öres- och kronutjämning',                            'type' => 'income',  'vat_code' => 'A',    'is_group' => false],
            ['code' => '3901', 'name' => 'Pant',                                               'type' => 'income',  'vat_code' => null,   'is_group' => false],
            ['code' => '3970', 'name' => 'Vinst vid avyttring av immateriella och materiella tillgångar','type'=>'income','vat_code'=>null,'is_group'=>false],
            ['code' => '3971', 'name' => 'Vinst vid avyttring av immateriella anläggningstillgångar','type'=>'income','vat_code'=>null,'is_group'=>false],
            ['code' => '3972', 'name' => 'Vinst vid avyttring av byggnader och mark',          'type' => 'income',  'vat_code' => null,   'is_group' => false],
            ['code' => '3973', 'name' => 'Vinst vid avyttring av maskiner och inventarier',    'type' => 'income',  'vat_code' => null,   'is_group' => false],
            ['code' => '3980', 'name' => 'Erhållna offentliga bidrag',                         'type' => 'income',  'vat_code' => 'MF',   'is_group' => false],
            ['code' => '3990', 'name' => 'Övriga ersättningar, bidrag och intäkter',           'type' => 'income',  'vat_code' => 'MF',   'is_group' => false],
            ['code' => '3999', 'name' => 'Övriga rörelseintäkter',                             'type' => 'income',  'vat_code' => null,   'is_group' => false],

            // ── 4 Varukostnader ───────────────────────────────────────────────
            ['code' => '4000', 'name' => 'Inköp av varor från Sverige',                        'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '4001', 'name' => 'Inköp av varor från Sverige BSK',                    'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '4599', 'name' => 'Justering, omvänd moms',                             'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '4960', 'name' => 'Förändring av lager av handelsvaror',                'type' => 'expense', 'vat_code' => null,   'is_group' => false],

            // ── 5–6 Övriga externa kostnader ──────────────────────────────────
            ['code' => '5010', 'name' => 'Lokalhyra',                                          'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5020', 'name' => 'El, nät och förbrukning',                            'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5060', 'name' => 'Avfallshantering',                                   'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5070', 'name' => 'Reparation och underhåll av lokaler',                'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5090', 'name' => 'Övriga lokalkostnader',                              'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5210', 'name' => 'Hyra av maskiner och andra tekniska anläggningar',   'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5220', 'name' => 'Hyra av inventarier och verktyg',                    'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5410', 'name' => 'Förbrukningsinventarier',                            'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5418', 'name' => 'Förbrukningsinventarier Spel och tillbehör',         'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5420', 'name' => 'Programvaror',                                       'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5460', 'name' => 'Förbrukningsmaterial',                               'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5480', 'name' => 'Arbetskläder och skyddsmaterial',                    'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5520', 'name' => 'Reparation och underhåll av inventarier och verktyg','type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5611', 'name' => 'Drivmedel för personbilar',                          'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5613', 'name' => 'Reparation och underhåll av personbilar',            'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5615', 'name' => 'Leasing av personbilar',                             'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5616', 'name' => 'Trängselskatt, avdragsgill',                         'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5619', 'name' => 'Övriga personbilskostnader',                         'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5690', 'name' => 'Övriga kostnader för transportmedel',                'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5710', 'name' => 'Frakter, transporter och försäkringar vid varudistribution','type'=>'expense','vat_code'=>null,'is_group'=>false],
            ['code' => '5800', 'name' => 'Resekostnader',                                      'type' => 'expense', 'vat_code' => null,   'is_group' => true],
            ['code' => '5810', 'name' => 'Biljetter',                                          'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5820', 'name' => 'Hyrbilskostnader',                                   'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5831', 'name' => 'Kost och logi i Sverige',                            'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5832', 'name' => 'Kost och logi i utlandet',                           'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5900', 'name' => 'Reklam och PR',                                      'type' => 'expense', 'vat_code' => null,   'is_group' => true],
            ['code' => '5910', 'name' => 'Annonsering',                                        'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '5990', 'name' => 'Övriga kostnader för reklam och PR',                 'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '6040', 'name' => 'Kontokortsavgifter',                                 'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '6060', 'name' => 'Kreditförsäljningskostnader',                        'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '6070', 'name' => 'Representation',                                     'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '6071', 'name' => 'Representation, avdragsgill',                        'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '6072', 'name' => 'Representation, ej avdragsgill',                     'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '6110', 'name' => 'Kontorsmateriel',                                    'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '6200', 'name' => 'Tele och post',                                      'type' => 'expense', 'vat_code' => null,   'is_group' => true],
            ['code' => '6210', 'name' => 'Telekommunikation/Internet',                         'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '6230', 'name' => 'Datakommunikation',                                  'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '6310', 'name' => 'Företagsförsäkringar',                              'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '6540', 'name' => 'IT-tjänster',                                        'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '6570', 'name' => 'Bankkostnader',                                      'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '6950', 'name' => 'Tillsynsavgifter myndigheter',                       'type' => 'expense', 'vat_code' => null,   'is_group' => false],

            // ── 7 Personalkostnader / Avskrivningar ───────────────────────────
            ['code' => '7631', 'name' => 'Personalrepresentation, avdragsgill',                'type' => 'expense', 'vat_code' => null,   'is_group' => false],
            ['code' => '7840', 'name' => 'Avskrivningar på förbättringsutgifter på annans fastighet','type'=>'expense','vat_code'=>null,'is_group'=>false],

            // ── 8 Resultat ────────────────────────────────────────────────────
            ['code' => '8999', 'name' => 'Årets resultat',                                     'type' => 'equity',  'vat_code' => null,   'is_group' => false],
        ];
    }
}
