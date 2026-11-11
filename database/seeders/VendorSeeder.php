<?php

namespace Database\Seeders;

use App\Models\Accounting\Account;
use App\Models\Accounting\Vendor;
use Illuminate\Database\Seeder;

class VendorSeeder extends Seeder
{
    public function run(): void
    {
        $vendors = [
            ['code' => '1',  'name' => 'Bahnhof AB (publ)',          'org_number' => '556519-9493', 'notes' => 'Stockholm'],
            ['code' => '2',  'name' => 'Nätkraft Borås Infra',        'org_number' => '556527-5582', 'email' => 'info@boraselnat.se', 'notes' => 'Borås'],
            ['code' => '3',  'name' => 'Borås kommun',                'org_number' => '212000-1561', 'email' => 'boras.stad@boras.se', 'notes' => 'Borås'],
            ['code' => '4',  'name' => 'Dina Försäkring AB'],
            ['code' => '5',  'name' => 'Fastigheten Stenyxan 10 KB', 'org_number' => '969712-5863', 'notes' => 'Borås'],
            ['code' => '6',  'name' => 'Gullkragens Konditori',       'org_number' => '556542-2150', 'notes' => 'Borås'],
            ['code' => '7',  'name' => 'Mera Profil & Reklam',        'org_number' => '556872-9833', 'notes' => 'Göteborg'],
            ['code' => '8',  'name' => 'Polismyndigheten',            'org_number' => '202100-0076', 'email' => 'registrator.kansli@polisen.se', 'notes' => 'Stockholm'],
            ['code' => '9',  'name' => 'Sparbanken Sjuhärad',         'org_number' => '516401-9852', 'notes' => 'Borås'],
            ['code' => '10', 'name' => 'Borås Energi och Miljö',      'org_number' => '556527-5590', 'email' => 'info@borasem.se', 'notes' => 'Borås'],
            ['code' => '11', 'name' => 'Fortnox',                     'org_number' => '556469-6291', 'notes' => 'Växjö'],
            ['code' => '12', 'name' => 'Bravida',                     'org_number' => '556197-4188', 'notes' => 'Borås'],
            ['code' => '13', 'name' => 'Returpack-Burk',              'org_number' => '556218-9117', 'email' => 'kundtjanst@returpack.se', 'notes' => 'Norrköping'],
            ['code' => '14', 'name' => 'Loopia',                      'org_number' => '556633-9304', 'email' => 'info@loopia.se', 'notes' => 'Västerås'],
            ['code' => '15', 'name' => 'Borås Brandservice',          'org_number' => '556333-5917', 'notes' => 'Borås'],
            ['code' => '16', 'name' => 'LåsTeam',                     'org_number' => '556580-8473', 'email' => 'ekonomi@lasteam.se', 'notes' => 'Borås'],
            ['code' => '17', 'name' => 'Borås Funcentre',             'org_number' => '556970-1609', 'email' => 'faktura@laserdomeboras.se', 'notes' => 'Borås'],
            ['code' => '18', 'name' => 'Sumup Limited',               'email' => 'support@sumup.se', 'notes' => 'Amsterdam, Netherlands'],
            ['code' => '19', 'name' => 'Lyreco',                      'org_number' => '556091-3591', 'notes' => 'Borås'],
            ['code' => '20', 'name' => 'Lenbergs Begravningsbyrå',    'org_number' => '556183-8573', 'email' => 'info@lenbergs.se', 'notes' => 'Ulricehamn'],
            ['code' => '21', 'name' => 'AREFF Systems',               'org_number' => '556590-2797', 'email' => 'info@areff.se', 'notes' => 'Ronneby'],
            ['code' => '22', 'name' => 'Evion/Borås Kongress',        'org_number' => '559135-2751', 'email' => 'info@boraskongress.se', 'notes' => 'Borås'],
            ['code' => '23', 'name' => 'Norrby Trä',                  'org_number' => '556040-0458', 'email' => 'norrby@norrbytra.se', 'notes' => 'Borås'],
            ['code' => '24', 'name' => 'JustNu',                      'org_number' => '556671-1924', 'email' => 'info@justnu.se', 'notes' => 'Borås'],
            ['code' => '25', 'name' => 'Skatteverket',                'org_number' => '202100-5448', 'notes' => 'Gävle'],
            ['code' => '26', 'name' => 'Kimmen&co',                   'org_number' => '559273-7489', 'email' => 'kimmen@kimmen.se', 'notes' => 'Borås'],
            ['code' => '27', 'name' => 'Lennarts Lotter',             'org_number' => '556497-8467', 'email' => 'info@lennartsidrottspriser.se', 'notes' => 'Borås'],
            ['code' => '28', 'name' => 'Evema'],
            ['code' => '29', 'name' => 'Fristad Rör AB',              'org_number' => '559474-9177'],
            ['code' => '30', 'name' => 'CogWork AB',                  'org_number' => '556985-3145', 'email' => 'info@cogwork.se', 'notes' => 'Uppsala'],
            ['code' => '31', 'name' => 'ProfileXpress',               'org_number' => '556924-7660', 'notes' => 'Borås'],
            ['code' => '32', 'name' => 'Tingstad Papper',             'org_number' => '556117-1199', 'notes' => 'Göteborg'],
            ['code' => '33', 'name' => '7H Elpartner',                'org_number' => '559135-8857', 'notes' => 'Kinna'],
            ['code' => '34', 'name' => 'EASY ONLINE STORES',          'org_number' => '556702-9755', 'notes' => 'Stockholm'],
            ['code' => '35', 'name' => 'Svenska Schackbutiken',       'org_number' => '556592-1359', 'notes' => 'Göteborg'],
            ['code' => '36', 'name' => 'Hobbyisterna/Alphaspel',      'org_number' => '556940-4204', 'email' => 'info@alphaspel.se', 'notes' => 'Hägersten'],
            ['code' => '37', 'name' => 'Danlin Handel',               'org_number' => '556640-7796', 'notes' => 'Huddinge'],
            ['code' => '38', 'name' => 'Scandinavian Photo',          'org_number' => '559381-9096', 'notes' => 'Borås'],
            ['code' => '39', 'name' => 'Nomina AB',                   'org_number' => '556728-5712', 'email' => 'henrik@nomina.se', 'notes' => 'Borås'],
            ['code' => '40', 'name' => 'Snabbgross',                  'notes' => 'Borås'],
        ];

        foreach ($vendors as $data) {
            Vendor::firstOrCreate(
                ['code' => $data['code']],
                array_merge([
                    'type'      => Vendor::TYPE_EXTERNAL,
                    'is_active' => true,
                ], $data)
            );
        }

        // Set default accounts that depend on Account FK resolution
        $a4000 = Account::where('code', '4000')->first();
        if ($a4000) {
            Vendor::where('code', '40')->update(['default_account_id' => $a4000->id]);
        }
    }
}
