<?php

namespace Database\Seeders;

use App\Models\Meeting\MeetingTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;

class MeetingTemplateSeeder extends Seeder
{
    /**
     * Formella grundpunkter som alltid ingår, i föreningens etablerade ordning.
     * Nästa möte tas direkt efter fastställande av dagordning — innan sakpunkterna.
     */
    private const BASE_ITEMS = [
        ['title' => 'Mötets öppnande',              'item_type' => 'standard',  'is_required' => true,  'description' => 'Ordföranden förklarar mötet öppnat.'],
        ['title' => 'Val av mötesordförande',         'item_type' => 'election',  'is_required' => true,  'description' => null],
        ['title' => 'Val av mötessekreterare',        'item_type' => 'election',  'is_required' => true,  'description' => null],
        ['title' => 'Val av justeringspersoner',      'item_type' => 'election',  'is_required' => true,  'description' => null],
        ['title' => 'Fastställande av dagordning',    'item_type' => 'vote',      'is_required' => true,  'description' => null],
        ['title' => 'Nästa möte',                     'item_type' => 'info',      'is_required' => true,  'description' => 'Datum och plats för nästa möte fastställs.'],
    ];

    private const CLOSING_ITEMS = [
        ['title' => 'Övriga frågor',    'item_type' => 'standard', 'is_required' => true, 'description' => null],
        ['title' => 'Mötets avslutande','item_type' => 'standard', 'is_required' => true, 'description' => 'Ordföranden förklarar mötet avslutat.'],
    ];

    public function run(): void
    {
        $admin = User::first();

        $this->createTemplate(
            name:        'Styrelsemöte',
            type:        'ordinary',
            noticeDays:  7,
            absences:    true,
            description: 'Standardmall för ordinarie styrelsemöten.',
            middleItems: [
                ['title' => 'Föregående protokoll',  'item_type' => 'vote',     'is_required' => false, 'description' => 'Föregående mötesprotokoll godkänns och läggs till handlingarna.'],
                ['title' => 'Kassörens rapport',     'item_type' => 'info',     'is_required' => false, 'description' => null],
                ['title' => 'Verksamhetsrapporter',  'item_type' => 'info',     'is_required' => false, 'description' => null],
            ],
            createdBy: $admin->id,
        );

        $this->createTemplate(
            name:        'Årsmöte',
            type:        'annual_general',
            noticeDays:  21,
            absences:    false,
            description: 'Mall för ordinarie årsmöte. Obligatoriska punkter enligt stadgarna.',
            middleItems: [
                ['title' => 'Fråga om mötet är stadgeenligt utlyst', 'item_type' => 'vote', 'is_required' => true,  'description' => null],
                ['title' => 'Fastställande av röstlängd',            'item_type' => 'vote', 'is_required' => true,  'description' => null],
                ['title' => 'Styrelsens verksamhetsberättelse',      'item_type' => 'info', 'is_required' => true,  'description' => null],
                ['title' => 'Styrelsens ekonomiska berättelse',      'item_type' => 'info', 'is_required' => true,  'description' => null],
                ['title' => 'Revisorernas berättelse',               'item_type' => 'info', 'is_required' => true,  'description' => null],
                ['title' => 'Fastställande av resultat- och balansräkning', 'item_type' => 'vote', 'is_required' => true, 'description' => null],
                ['title' => 'Beslut om disposition av årets resultat','item_type' => 'vote', 'is_required' => true, 'description' => null],
                ['title' => 'Fråga om ansvarsfrihet för styrelsen',  'item_type' => 'vote', 'is_required' => true,  'description' => null],
                ['title' => 'Val av ordförande',                     'item_type' => 'election', 'is_required' => true, 'description' => null],
                ['title' => 'Val av styrelseledamöter',              'item_type' => 'election', 'is_required' => true, 'description' => null],
                ['title' => 'Val av revisorer',                      'item_type' => 'election', 'is_required' => true, 'description' => null],
                ['title' => 'Val av valberedning',                   'item_type' => 'election', 'is_required' => true, 'description' => null],
                ['title' => 'Fastställande av verksamhetsplan',      'item_type' => 'vote', 'is_required' => false, 'description' => null],
                ['title' => 'Fastställande av budget och avgifter',  'item_type' => 'vote', 'is_required' => false, 'description' => null],
            ],
            createdBy: $admin->id,
        );

        $this->createTemplate(
            name:        'Extra stämma',
            type:        'extraordinary',
            noticeDays:  14,
            absences:    false,
            description: 'Mall för extra föreningsstämma.',
            middleItems: [
                ['title' => 'Fråga om mötet är stadgeenligt utlyst', 'item_type' => 'vote', 'is_required' => true, 'description' => null],
                ['title' => 'Fastställande av röstlängd',            'item_type' => 'vote', 'is_required' => true, 'description' => null],
            ],
            createdBy: $admin->id,
        );

        $this->createTemplate(
            name:        'Sektionsmöte',
            type:        'section',
            noticeDays:  7,
            absences:    true,
            description: 'Standardmall för sektioners interna möten.',
            middleItems: [
                ['title' => 'Föregående protokoll', 'item_type' => 'vote', 'is_required' => false, 'description' => 'Föregående mötesprotokoll godkänns och läggs till handlingarna.'],
                ['title' => 'Rapporter',            'item_type' => 'info', 'is_required' => false, 'description' => null],
            ],
            createdBy: $admin->id,
        );
    }

    private function createTemplate(
        string $name,
        string $type,
        int    $noticeDays,
        bool   $absences,
        string $description,
        array  $middleItems,
        int    $createdBy,
    ): void {
        $template = MeetingTemplate::firstOrCreate(
            ['name' => $name],
            [
                'meeting_type'               => $type,
                'default_notice_days'        => $noticeDays,
                'include_absences_in_minutes' => $absences,
                'description'               => $description,
                'created_by'                => $createdBy,
            ]
        );

        if ($template->items()->exists()) {
            return;
        }

        $allItems = array_merge(self::BASE_ITEMS, $middleItems, self::CLOSING_ITEMS);

        foreach ($allItems as $i => $item) {
            $template->items()->create([
                'sort_order'  => $i,
                'title'       => $item['title'],
                'description' => $item['description'],
                'item_type'   => $item['item_type'],
                'is_required' => $item['is_required'],
            ]);
        }
    }
}
