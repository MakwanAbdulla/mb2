<?php

namespace App\Console\Commands;

use App\Models\Organization\Section;
use App\Models\Wiki\WikiSpace;
use Illuminate\Console\Command;

// TODO(#168): iterate groups once sections become groups.
class CreateWikiGroupSpaces extends Command
{
    protected $signature = 'wiki:create-group-spaces';

    protected $description = 'Skapa wikiutrymmen för grupper som saknar ett (kan köras flera gånger)';

    public function handle(): int
    {
        $created = 0;

        Section::whereDoesntHave('wikiSpace')->orderBy('id')->each(function (Section $group) use (&$created) {
            WikiSpace::forGroup($group);
            $this->line("  [+] {$group->name}");
            $created++;
        });

        $this->info($created === 0 ? 'Alla grupper har redan ett wikiutrymme.' : "Skapade {$created} wikiutrymmen.");

        return self::SUCCESS;
    }
}
