<?php

namespace App\Console\Commands;

use App\Models\Accounting\Account;
use Illuminate\Console\Command;

/**
 * Imports standard BAS accounts that are not already in the chart of accounts.
 * All imported accounts are created with is_active = false — activate them
 * manually in the admin UI when you start using them.
 *
 * Safe to re-run: skips accounts whose code already exists.
 */
class SeedBasAccounts extends Command
{
    protected $signature   = 'accounting:seed-bas';
    protected $description = 'Importera standard-BAS-konton som inaktiva (hoppar över befintliga)';

    public function handle(): int
    {
        $accounts = $this->accounts();
        $created  = 0;
        $skipped  = 0;

        foreach ($accounts as $data) {
            if (Account::where('code', $data['code'])->exists()) {
                $skipped++;
                continue;
            }

            Account::create(array_merge($data, ['is_active' => false, 'is_group' => false]));
            $created++;
        }

        $this->info("Importerade {$created} BAS-konton som inaktiva. Hoppade över {$skipped} befintliga.");
        return self::SUCCESS;
    }

    private function accounts(): array
    {
        return [
            // ── Immateriella anläggningstillgångar ───────────────────────────
            ['code' => '1010', 'name' => 'Utvecklingsutgifter',                                          'type' => 'asset', 'sort_order' => 10],
            ['code' => '1011', 'name' => 'Balanserade utgifter för forskning och utveckling',            'type' => 'asset', 'sort_order' => 11],
            ['code' => '1012', 'name' => 'Balanserade utgifter för programvaror',                        'type' => 'asset', 'sort_order' => 12],
            ['code' => '1018', 'name' => 'Ackumulerade nedskrivningar på balanserade utgifter',         'type' => 'asset', 'sort_order' => 18],
            ['code' => '1019', 'name' => 'Ackumulerade avskrivningar på balanserade utgifter',          'type' => 'asset', 'sort_order' => 19],
            ['code' => '1020', 'name' => 'Koncessioner m.m.',                                            'type' => 'asset', 'sort_order' => 20],
            ['code' => '1028', 'name' => 'Ackumulerade nedskrivningar på koncessioner m.m.',            'type' => 'asset', 'sort_order' => 28],
            ['code' => '1029', 'name' => 'Ackumulerade avskrivningar på koncessioner m.m.',             'type' => 'asset', 'sort_order' => 29],
            ['code' => '1030', 'name' => 'Patent',                                                       'type' => 'asset', 'sort_order' => 30],
            ['code' => '1038', 'name' => 'Ackumulerade nedskrivningar på patent',                       'type' => 'asset', 'sort_order' => 38],
            ['code' => '1039', 'name' => 'Ackumulerade avskrivningar på patent',                        'type' => 'asset', 'sort_order' => 39],
            ['code' => '1040', 'name' => 'Licenser',                                                     'type' => 'asset', 'sort_order' => 40],
            ['code' => '1048', 'name' => 'Ackumulerade nedskrivningar på licenser',                     'type' => 'asset', 'sort_order' => 48],
            ['code' => '1049', 'name' => 'Ackumulerade avskrivningar på licenser',                      'type' => 'asset', 'sort_order' => 49],
            ['code' => '1050', 'name' => 'Varumärken',                                                   'type' => 'asset', 'sort_order' => 50],
            ['code' => '1058', 'name' => 'Ackumulerade nedskrivningar på varumärken',                   'type' => 'asset', 'sort_order' => 58],
            ['code' => '1059', 'name' => 'Ackumulerade avskrivningar på varumärken',                    'type' => 'asset', 'sort_order' => 59],
            ['code' => '1060', 'name' => 'Hyresrätter, tomträtter och liknande',                        'type' => 'asset', 'sort_order' => 60],
            ['code' => '1068', 'name' => 'Ackumulerade nedskrivningar på hyresrätter, tomträtter',      'type' => 'asset', 'sort_order' => 68],
            ['code' => '1069', 'name' => 'Ackumulerade avskrivningar på hyresrätter, tomträtter',       'type' => 'asset', 'sort_order' => 69],
            ['code' => '1070', 'name' => 'Goodwill',                                                     'type' => 'asset', 'sort_order' => 70],
            ['code' => '1078', 'name' => 'Ackumulerade nedskrivningar på goodwill',                     'type' => 'asset', 'sort_order' => 78],
            ['code' => '1079', 'name' => 'Ackumulerade avskrivningar på goodwill',                      'type' => 'asset', 'sort_order' => 79],
            ['code' => '1080', 'name' => 'Förskott för immateriella anläggningstillgångar',             'type' => 'asset', 'sort_order' => 80],
            ['code' => '1081', 'name' => 'Pågående projekt för immateriella anläggningstillgångar',     'type' => 'asset', 'sort_order' => 81],
            ['code' => '1088', 'name' => 'Förskott för immateriella anläggningstillgångar (EU)',        'type' => 'asset', 'sort_order' => 88],

            // ── Byggnader och mark ────────────────────────────────────────────
            ['code' => '1110', 'name' => 'Byggnader',                                                    'type' => 'asset', 'sort_order' => 110],
            ['code' => '1111', 'name' => 'Byggnader på egen mark',                                       'type' => 'asset', 'sort_order' => 111],
            ['code' => '1112', 'name' => 'Byggnader på annans mark',                                     'type' => 'asset', 'sort_order' => 112],
            ['code' => '1118', 'name' => 'Ackumulerade nedskrivningar på byggnader',                    'type' => 'asset', 'sort_order' => 118],
            ['code' => '1119', 'name' => 'Ackumulerade avskrivningar på byggnader',                     'type' => 'asset', 'sort_order' => 119],
            ['code' => '1130', 'name' => 'Mark',                                                          'type' => 'asset', 'sort_order' => 130],
            ['code' => '1140', 'name' => 'Tomter och obebyggda markområden',                             'type' => 'asset', 'sort_order' => 140],
            ['code' => '1150', 'name' => 'Markanläggningar',                                             'type' => 'asset', 'sort_order' => 150],
            ['code' => '1158', 'name' => 'Ackumulerade nedskrivningar på markanläggningar',             'type' => 'asset', 'sort_order' => 158],
            ['code' => '1159', 'name' => 'Ackumulerade avskrivningar på markanläggningar',              'type' => 'asset', 'sort_order' => 159],
            ['code' => '1180', 'name' => 'Pågående nyanläggningar och förskott för byggnader',          'type' => 'asset', 'sort_order' => 180],
            ['code' => '1181', 'name' => 'Pågående ny-, till- och ombyggnad',                            'type' => 'asset', 'sort_order' => 181],
            ['code' => '1188', 'name' => 'Förskott för byggnader och mark',                              'type' => 'asset', 'sort_order' => 188],

            // ── Maskiner och inventarier ──────────────────────────────────────
            ['code' => '1210', 'name' => 'Maskiner och andra tekniska anläggningar',                    'type' => 'asset', 'sort_order' => 210],
            ['code' => '1211', 'name' => 'Maskiner',                                                     'type' => 'asset', 'sort_order' => 211],
            ['code' => '1213', 'name' => 'Andra tekniska anläggningar',                                  'type' => 'asset', 'sort_order' => 213],
            ['code' => '1218', 'name' => 'Ackumulerade nedskrivningar på maskiner och andra tekniska',  'type' => 'asset', 'sort_order' => 218],
            ['code' => '1219', 'name' => 'Ackumulerade avskrivningar på maskiner och andra tekniska',   'type' => 'asset', 'sort_order' => 219],
            ['code' => '1221', 'name' => 'Inventarier',                                                  'type' => 'asset', 'sort_order' => 221],
            ['code' => '1222', 'name' => 'Byggnadsinventarier',                                          'type' => 'asset', 'sort_order' => 222],
            ['code' => '1223', 'name' => 'Markinventarier',                                              'type' => 'asset', 'sort_order' => 223],
            ['code' => '1225', 'name' => 'Verktyg',                                                      'type' => 'asset', 'sort_order' => 225],
            ['code' => '1228', 'name' => 'Ackumulerade nedskrivningar på inventarier och verktyg',      'type' => 'asset', 'sort_order' => 228],
            ['code' => '1229', 'name' => 'Ackumulerade avskrivningar på inventarier och verktyg',       'type' => 'asset', 'sort_order' => 229],
            ['code' => '1230', 'name' => 'Installationer',                                               'type' => 'asset', 'sort_order' => 230],
            ['code' => '1231', 'name' => 'Installationer på egen fastighet',                             'type' => 'asset', 'sort_order' => 231],
            ['code' => '1232', 'name' => 'Installationer på annans fastighet',                           'type' => 'asset', 'sort_order' => 232],
            ['code' => '1238', 'name' => 'Ackumulerade nedskrivningar på installationer',               'type' => 'asset', 'sort_order' => 238],
            ['code' => '1239', 'name' => 'Ackumulerade avskrivningar på installationer',                'type' => 'asset', 'sort_order' => 239],
            ['code' => '1240', 'name' => 'Bilar och andra transportmedel',                               'type' => 'asset', 'sort_order' => 240],
            ['code' => '1241', 'name' => 'Personbilar',                                                  'type' => 'asset', 'sort_order' => 241],
            ['code' => '1242', 'name' => 'Lastbilar',                                                    'type' => 'asset', 'sort_order' => 242],
            ['code' => '1243', 'name' => 'Truckar',                                                      'type' => 'asset', 'sort_order' => 243],
            ['code' => '1244', 'name' => 'Arbetsmaskiner',                                               'type' => 'asset', 'sort_order' => 244],
            ['code' => '1245', 'name' => 'Traktorer',                                                    'type' => 'asset', 'sort_order' => 245],
            ['code' => '1246', 'name' => 'Motorcyklar, mopeder och skotrar',                             'type' => 'asset', 'sort_order' => 246],
            ['code' => '1247', 'name' => 'Båtar, flygplan och helikoptrar',                              'type' => 'asset', 'sort_order' => 247],
            ['code' => '1248', 'name' => 'Ackumulerade nedskrivningar på bilar och transportmedel',     'type' => 'asset', 'sort_order' => 248],
            ['code' => '1249', 'name' => 'Ackumulerade avskrivningar på bilar och transportmedel',      'type' => 'asset', 'sort_order' => 249],
            ['code' => '1251', 'name' => 'Datorer, företaget',                                           'type' => 'asset', 'sort_order' => 251],
            ['code' => '1257', 'name' => 'Datorer, personal',                                            'type' => 'asset', 'sort_order' => 257],
            ['code' => '1260', 'name' => 'Leasade tillgångar',                                           'type' => 'asset', 'sort_order' => 260],
            ['code' => '1269', 'name' => 'Ackumulerade avskrivningar på leasade tillgångar',            'type' => 'asset', 'sort_order' => 269],
            ['code' => '1280', 'name' => 'Pågående nyanläggningar och förskott för maskiner',           'type' => 'asset', 'sort_order' => 280],
            ['code' => '1281', 'name' => 'Pågående nyanläggningar, maskiner och inventarier',           'type' => 'asset', 'sort_order' => 281],
            ['code' => '1288', 'name' => 'Förskott för maskiner och inventarier',                       'type' => 'asset', 'sort_order' => 288],
            ['code' => '1290', 'name' => 'Övriga materiella anläggningstillgångar',                     'type' => 'asset', 'sort_order' => 290],
            ['code' => '1292', 'name' => 'Djur som klassificeras som anläggningstillgång',              'type' => 'asset', 'sort_order' => 292],
            ['code' => '1298', 'name' => 'Ackumulerade nedskrivningar på övriga materiella anl.',       'type' => 'asset', 'sort_order' => 298],
            ['code' => '1299', 'name' => 'Ackumulerade avskrivningar på övriga materiella anl.',        'type' => 'asset', 'sort_order' => 299],

            // ── Finansiella anläggningstillgångar ─────────────────────────────
            ['code' => '1310', 'name' => 'Andelar i koncernföretag',                                    'type' => 'asset', 'sort_order' => 310],
            ['code' => '1311', 'name' => 'Aktier i noterade svenska koncernföretag',                    'type' => 'asset', 'sort_order' => 311],
            ['code' => '1312', 'name' => 'Aktier i onoterade svenska koncernföretag',                   'type' => 'asset', 'sort_order' => 312],
            ['code' => '1313', 'name' => 'Aktier i noterade utländska koncernföretag',                  'type' => 'asset', 'sort_order' => 313],
            ['code' => '1314', 'name' => 'Aktier i onoterade utländska koncernföretag',                 'type' => 'asset', 'sort_order' => 314],
            ['code' => '1316', 'name' => 'Andra andelar i svenska koncernföretag',                      'type' => 'asset', 'sort_order' => 316],
            ['code' => '1317', 'name' => 'Andra andelar i utländska koncernföretag',                    'type' => 'asset', 'sort_order' => 317],
            ['code' => '1318', 'name' => 'Ackumulerade nedskrivningar av andelar i koncernföretag',    'type' => 'asset', 'sort_order' => 318],
            ['code' => '1320', 'name' => 'Långfristiga fordringar hos koncernföretag',                  'type' => 'asset', 'sort_order' => 320],
            ['code' => '1321', 'name' => 'Långfristiga fordringar hos moderföretag',                    'type' => 'asset', 'sort_order' => 321],
            ['code' => '1322', 'name' => 'Långfristiga fordringar hos dotterföretag',                   'type' => 'asset', 'sort_order' => 322],
            ['code' => '1323', 'name' => 'Långfristiga fordringar hos andra koncernföretag',            'type' => 'asset', 'sort_order' => 323],
            ['code' => '1328', 'name' => 'Ackumulerade nedskrivningar av långfristiga fordringar',     'type' => 'asset', 'sort_order' => 328],
            ['code' => '1330', 'name' => 'Andelar i intresseföretag, gemensamt styrda företag',        'type' => 'asset', 'sort_order' => 330],
            ['code' => '1331', 'name' => 'Andelar i intresseföretag',                                   'type' => 'asset', 'sort_order' => 331],
            ['code' => '1332', 'name' => 'Ackumulerade nedskrivningar av andelar i intresseföretag',   'type' => 'asset', 'sort_order' => 332],
            ['code' => '1333', 'name' => 'Andelar i gemensamt styrda företag',                          'type' => 'asset', 'sort_order' => 333],
            ['code' => '1334', 'name' => 'Ackumulerade nedskrivningar av andelar i gemensamt styrda',  'type' => 'asset', 'sort_order' => 334],
            ['code' => '1336', 'name' => 'Andelar i övriga företag som det finns ett ägarintresse i',  'type' => 'asset', 'sort_order' => 336],
            ['code' => '1337', 'name' => 'Ackumulerade nedskrivningar av andelar i övriga företag',    'type' => 'asset', 'sort_order' => 337],
            ['code' => '1338', 'name' => 'Ackumulerade nedskrivningar av andelar i intresseföretag 2', 'type' => 'asset', 'sort_order' => 338],
            ['code' => '1340', 'name' => 'Långfristiga fordringar hos intresseföretag, gemensamma',    'type' => 'asset', 'sort_order' => 340],
            ['code' => '1341', 'name' => 'Långfristiga fordringar hos intresseföretag',                 'type' => 'asset', 'sort_order' => 341],
            ['code' => '1342', 'name' => 'Ackumulerade nedskrivningar av långfristiga fordringar 2',   'type' => 'asset', 'sort_order' => 342],
        ];
    }
}
