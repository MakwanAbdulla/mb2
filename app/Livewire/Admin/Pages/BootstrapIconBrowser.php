<?php

namespace App\Livewire\Admin\Pages;

use Illuminate\Support\Facades\File;
use Livewire\Component;

class BootstrapIconBrowser extends Component
{
    public string $search = '';

    public array $icons = [];

    private static ?array $allIcons = null;

    private static array $aliases = [
        'fylld' => 'fill', 'ifylld' => 'fill', 'fil' => 'file', 'pil' => 'arrow',
        'cirkel' => 'circle', 'rund' => 'circle', 'fyrkant' => 'square', 'kvadrat' => 'square',
        'upp' => 'up', 'ner' => 'down', 'vänster' => 'left', 'höger' => 'right',
        'person' => 'person', 'folk' => 'people', 'hjärta' => 'heart', 'chatt' => 'chat',
        'pratstund' => 'chat', 'meddelande' => 'chat', 'kuvert' => 'envelope', 'brev' => 'envelope',
        'mail' => 'envelope', 'stäng' => 'x', 'kryss' => 'x', 'klar' => 'check', 'bock' => 'check',
        'godkänd' => 'check', 'kalender' => 'calendar', 'datum' => 'calendar',
        'händelse' => 'calendar', 'vecka' => 'week', 'dokument' => 'file', 'filtyp' => 'filetype',
        'öronmärke' => 'earmark', 'bokmärke' => 'bookmark', 'mapp' => 'folder',
        'katalog' => 'folder', 'klippbord' => 'clipboard', 'urklipp' => 'clipboard',
        'plus' => 'plus', 'minus' => 'minus', 'streck' => 'dash', 'snedstreck' => 'slash',
        'utropstecken' => 'exclamation', 'varning' => 'exclamation', 'frågetecken' => 'question',
        'fråga' => 'question', 'hjälp' => 'question', 'moln' => 'cloud', 'databas' => 'database',
        'kod' => 'code', 'programmering' => 'code', 'hårddisk' => 'hdd', 'disk' => 'hdd',
        'byggnad' => 'building', 'hus' => 'house', 'hem' => 'house', 'start' => 'house',
        'knapp' => 'btn', 'meny' => 'menu', 'vinkel' => 'chevron', 'triangel' => 'caret',
        'märke' => 'badge', 'låda' => 'box', 'ruta' => 'box', 'ram' => 'border',
        'kant' => 'border', 'skicka' => 'send', 'framåt' => 'forward', 'sortera' => 'sort',
        'spela' => 'play', 'stoppa' => 'stop', 'sköld' => 'shield', 'skydd' => 'shield',
        'lås' => 'lock', 'kort' => 'card', 'kreditkort' => 'credit', 'vagn' => 'cart',
        'kundvagn' => 'cart', 'väska' => 'bag', 'kasse' => 'bag', 'telefon' => 'telephone',
        'mobil' => 'phone', 'kamera' => 'camera', 'text' => 'text', 'journal' => 'journal',
        'dagbok' => 'journal', 'emoji' => 'emoji', 'känsla' => 'emoji', 'rutnät' => 'grid',
        'lista' => 'list', 'layout' => 'layout', 'sidofält' => 'sidebar', 'fönster' => 'window',
        'diamant' => 'diamond', 'romb' => 'diamond', 'oktagon' => 'octagon',
        'åttahörning' => 'octagon', 'kugghjul' => 'gear', 'inställningar' => 'gear',
        'verktyg' => 'tools', 'tärning' => 'dice', 'spel' => 'dice', 'musik' => 'music',
        'volym' => 'volume', 'ljud' => 'volume', 'hand' => 'hand', 'citat' => 'quote',
        'blockcitat' => 'blockquote', 'punkter' => 'dots', 'prickar' => 'dots',
        'vertikal' => 'vertical', 'lodrät' => 'vertical', 'bild' => 'image', 'foto' => 'image',
        'graf' => 'graph', 'diagram' => 'graph', 'ljusstyrka' => 'brightness', 'blixt' => 'lightning',
        'stjärna' => 'star', 'favorit' => 'star', 'tåg' => 'train', 'biljett' => 'ticket',
        'termometer' => 'thermometer', 'temperatur' => 'thermometer', 'regn' => 'rain',
        'måne' => 'moon', 'sol' => 'sun', 'terminal' => 'terminal', 'konsoll' => 'terminal',
        'dela' => 'split', 'vägvisare' => 'signpost', 'skylt' => 'signpost', 'paus' => 'pause',
        'video' => 'video', 'film' => 'video', 'nod' => 'node', 'knut' => 'node',
        'mikrofon' => 'mic', 'karta' => 'map', 'landkarta' => 'map', 'glödlampa' => 'lightbulb',
        'lampa' => 'lightbulb', 'timglas' => 'hourglass', 'information' => 'info',
        'jordglob' => 'globe', 'värld' => 'globe', 'markör' => 'cursor', 'pekare' => 'cursor',
        'skiftnyckel' => 'wrench', 'wifi' => 'wifi', 'trådlöst' => 'wifi', 'platta' => 'tablet',
        'surfplatta' => 'tablet', 'tumme' => 'thumbs', 'ryggsäck' => 'backpack',
        'portfölj' => 'briefcase', 'leende' => 'smile', 'le' => 'smile', 'raket' => 'rocket',
        'svar' => 'reply', 'svara' => 'reply', 'dator' => 'pc', 'papper' => 'paper',
        'hjärter' => 'heart', 'ruter' => 'diamond', 'klöver' => 'club', 'spader' => 'spade',
        'tärningar' => 'DICE_WILDCARD', 'väder' => 'WEATHER_WILDCARD',
        'pilar' => 'ARROW_WILDCARD', 'siffra' => 'NUMERIC_WILDCARD',
        'nummer' => 'NUMERIC_WILDCARD', 'bokstav' => 'LETTER_WILDCARD',
        'spelkort' => 'SUIT_WILDCARD', 'kortlek' => 'SUIT_WILDCARD',
        'känslor' => 'EMOJI_WILDCARD', 'ansikte' => 'EMOJI_WILDCARD',
        'snö' => 'snow', 'paraply' => 'umbrella', 'vind' => 'wind',
    ];

    public function mount(): void
    {
        $this->loadIcons();
    }

    public function updatedSearch(): void
    {
        $this->loadIcons();
    }

    public function loadIcons(): void
    {
        if (self::$allIcons === null) {
            $iconPath = public_path('images/icons/bootstrap');
            if (! is_dir($iconPath)) {
                self::$allIcons = [];
            } else {
                $files = File::files($iconPath);
                $icons = [];
                foreach ($files as $file) {
                    if ($file->getExtension() === 'svg') {
                        $name = $file->getFilenameWithoutExtension();
                        $icons[] = ['name' => $name, 'path' => '/images/icons/bootstrap/'.$file->getFilename()];
                    }
                }
                usort($icons, fn ($a, $b) => strcmp($a['name'], $b['name']));
                self::$allIcons = $icons;
            }
        }

        if (empty($this->search)) {
            $this->icons = array_slice(self::$allIcons, 0, 50);

            return;
        }

        $searchTerms = array_filter(array_map('trim', explode(' ', strtolower($this->search))));
        $translatedTerms = [];

        foreach ($searchTerms as $term) {
            $translations = [];
            if (isset(self::$aliases[$term])) {
                $translations[] = self::$aliases[$term];
            }
            foreach (self::$aliases as $swedish => $english) {
                if (str_contains($swedish, $term) && $term !== $swedish) {
                    $translations[] = $english;
                }
            }
            $translatedTerms[] = empty($translations) ? [$term] : array_unique($translations);
        }

        $filtered = array_filter(self::$allIcons, function ($icon) use ($translatedTerms) {
            $iconName = strtolower($icon['name']);
            foreach ($translatedTerms as $possibleTranslations) {
                $termMatched = false;
                foreach ($possibleTranslations as $translation) {
                    $termMatched = match ($translation) {
                        'NUMERIC_WILDCARD' => (bool) preg_match('/\d/', $iconName),
                        'LETTER_WILDCARD' => str_contains($iconName, 'type-') || str_contains($iconName, 'alphabet') || str_contains($iconName, 'text'),
                        'SUIT_WILDCARD' => str_contains($iconName, 'suit-'),
                        'DICE_WILDCARD' => str_contains($iconName, 'dice-'),
                        'EMOJI_WILDCARD' => str_contains($iconName, 'emoji-'),
                        'WEATHER_WILDCARD' => str_contains($iconName, 'cloud') || str_contains($iconName, 'rain') || str_contains($iconName, 'sun') || str_contains($iconName, 'moon') || str_contains($iconName, 'wind'),
                        'ARROW_WILDCARD' => str_contains($iconName, 'arrow-') || str_contains($iconName, 'caret-') || str_contains($iconName, 'chevron-'),
                        default => str_contains($iconName, $translation),
                    };
                    if ($termMatched) {
                        break;
                    }
                }
                if (! $termMatched) {
                    return false;
                }
            }

            return true;
        });

        $this->icons = array_values($filtered);
    }

    public function render()
    {
        return view('livewire.admin.pages.bootstrap-icon-browser');
    }
}
