<?php

namespace Database\Seeders;

use App\Models\Accounting\PaymentTerm;
use Illuminate\Database\Seeder;

class PaymentTermSeeder extends Seeder
{
    public function run(): void
    {
        $terms = [
            ['code' => '0',   'name' => 'Kontant',        'due_days' => 0,  'sort_order' => 0],
            ['code' => '8',   'name' => '8 dagar netto',  'due_days' => 8,  'sort_order' => 10],
            ['code' => '10',  'name' => '10 dagar netto', 'due_days' => 10, 'sort_order' => 20],
            ['code' => '14',  'name' => '14 dagar netto', 'due_days' => 14, 'sort_order' => 30],
            ['code' => '20',  'name' => '20 dagar netto', 'due_days' => 20, 'sort_order' => 40],
            ['code' => '30',  'name' => '30 dagar netto', 'due_days' => 30, 'sort_order' => 50],
            ['code' => '45',  'name' => '45 dagar netto', 'due_days' => 45, 'sort_order' => 60],
            ['code' => '60',  'name' => '60 dagar netto', 'due_days' => 60, 'sort_order' => 70],
            ['code' => '90',  'name' => '90 dagar netto', 'due_days' => 90, 'sort_order' => 80],
        ];

        foreach ($terms as $term) {
            PaymentTerm::firstOrCreate(
                ['code' => $term['code']],
                array_merge($term, ['is_active' => true])
            );
        }
    }
}
