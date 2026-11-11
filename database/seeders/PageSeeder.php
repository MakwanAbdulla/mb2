<?php

namespace Database\Seeders;

use App\Models\Content\HelpText;
use App\Models\Content\NavigationItem;
use App\Models\Content\Page;
use Illuminate\Database\Seeder;

class PageSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPages();
        $this->seedNavigation();
        $this->seedHelpTexts();
    }

    private function seedPages(): void
    {
        $pages = [

            // ── Startsida ──────────────────────────────────────────────────
            [
                'seeder_key'   => 'hem',
                'slug'         => 'hem',
                'path_prefix'  => null,
                'title'        => 'Välkommen till Armagedon',
                'subtitle'     => 'Borås spelförening för brädspel, miniatyrspel och rollspel',
                'is_published' => true,
                'show_in_nav'  => false,
                'sort_order'   => 0,
                'content'      => <<<HTML
<p>Armagedon är Borås spelförening för dig som är intresserad av spel i alla former. Vi håller till på Katrinehillsgatan 7b och arrangerar regelbundna spelkvällar varje vecka. Du får gärna komma och kika – nya besökare kan delta upp till tre gånger innan medlemskap krävs.</p>

<h2>Veckans spelkvällar</h2>

<h3>Tisdag – Figurspel</h3>
<p>Varje tisdag kväll träffas vi för miniatyrspel. Populära titlar är Warhammer 40 000, Age of Sigmar, Kill Team, Marvel Crisis Protocol och Star Wars: Shatterpoint. Här bygger, målar och spelar vi tillsammans.</p>

<h3>Torsdag – Brädspel</h3>
<p>Torsdagar klockan 18 är det brädspelskväll. Det finns alltid plats för nya och gamla spelare. Vi har ett bibliotek med över 100 brädspel att låna.</p>

<h3>Söndag – Kortspel</h3>
<p>Nästan varje söndag från ungefär klockan 10 samlas vi för kortspel. Magic: The Gathering och Disney Lorcana är vanliga titlar. Kolla i Discord för att bekräfta att det är speldag.</p>

<h2>Hitta oss</h2>
<p>Vi håller till på <strong>Katrinehillsgatan 7b, 504 52 Borås</strong>. Närmaste busshållplats är Radiohuset (linje 6). Parkering finns längs Katrinehillsgatan.</p>

<p>Gå med på vår <a href="http://discord.armagedon.se">Discord-server</a> för att hålla koll på vad som händer.</p>
HTML,
            ],

            // ── Om oss ─────────────────────────────────────────────────────
            [
                'seeder_key'   => 'om-oss',
                'slug'         => 'om-oss',
                'path_prefix'  => null,
                'title'        => 'Om oss',
                'subtitle'     => 'Vad är Armagedon?',
                'is_published' => true,
                'show_in_nav'  => false,
                'sort_order'   => 10,
                'content'      => <<<HTML
<p>Armagedon är en ideell förening i Borås med fokus på spelhobbyn. Vi är anslutna till SVEROK – Sveriges största ungdomsförbund för spel och rollspel.</p>

<p>Föreningen drivs helt av frivilliga medlemmar. Det finns ingen avlönad personal som sköter lokalen eller arrangerar aktiviteter – allt görs av och för medlemmarna. Det innebär att du som medlem både tar del av och bidrar till föreningens verksamhet.</p>

<p>Vi erbjuder ett brett utbud av spelaktiviteter: från nybörjarvänliga brädspelskväll till skräddarsydda rollspelskampanjer och turneringar i figurspel och kortspel. Oavsett vad du är intresserad av finns det plats för dig.</p>

<p>Nya besökare är välkomna att delta upp till tre gånger utan medlemskap. Är du osäker på vilket spelintresse som passar dig bäst? Prata med oss, kolla in SVEROKs spelguider, eller ta med en kompis och testa tillsammans.</p>
HTML,
            ],

            // ── Vår lokal ──────────────────────────────────────────────────
            [
                'seeder_key'   => 'var-lokal',
                'slug'         => 'var-lokal',
                'path_prefix'  => 'om-oss',
                'title'        => 'Vår lokal',
                'subtitle'     => 'Fem spelrum och fullt utrustat hobbyutrymme',
                'is_published' => true,
                'show_in_nav'  => false,
                'sort_order'   => 11,
                'content'      => <<<HTML
<p>Vi håller till på <strong>Katrinehillsgatan 7b, 504 52 Borås</strong>. Lokalen har fem spelrum i olika storlekar – allt från mindre sällskapsspelsrum till större utrymmen för turneringar och figurspel.</p>

<h2>Vad finns i lokalen?</h2>
<ul>
  <li>Kök med spis, kyl och kaffemaskin</li>
  <li>Wi-Fi</li>
  <li>Dusch och omklädningsrum</li>
  <li>Toaletter med barnvänliga faciliteter</li>
  <li>Automat med snacks och dryck</li>
  <li>Spraybox för miniatyrmålning</li>
  <li>Målningsstation med bra belysning</li>
  <li>Fem spelrum i varierande storlekar</li>
  <li>Expedition med kontorsutrustning</li>
</ul>

<h2>Spelbibliotek</h2>
<p>Medlemmar kan låna från ett bibliotek med över 100 brädspel och 100+ rollspelsspel. Vi har också tärningar, tärningtorn, figurer och spelmattor tillgängliga.</p>

<h2>Mat och dryck</h2>
<p>Du får ta med egen mat och använda köket. Håll ordning i gemensamma utrymmen och kör diskmaskin om den är full. Mat och dryck får inte ställas på spelbordet utan att spelägaren godkänner det.</p>

<h2>Hitta hit</h2>
<p><strong>Buss:</strong> Hållplats Radiohuset (linje 6) är närmast.</p>
<p><strong>Parkering:</strong> Längs Katrinehillsgatan samt längre upp på backen.</p>
HTML,
            ],

            // ── Så funkar vi ───────────────────────────────────────────────
            [
                'seeder_key'   => 'sa-funkar-vi',
                'slug'         => 'sa-funkar-vi',
                'path_prefix'  => 'om-oss',
                'title'        => 'Så funkar vi',
                'subtitle'     => 'En ideell förening driven av sina medlemmar',
                'is_published' => true,
                'show_in_nav'  => false,
                'sort_order'   => 12,
                'content'      => <<<HTML
<p>Armagedon är en ideell förening, vilket innebär att allt arbete görs av frivilliga. Ingen är anställd – styrelse, arrangörer och de som håller lokalen snygg är alla vanliga medlemmar som lägger ner sin fritid.</p>

<h2>Vad förväntas av dig som medlem?</h2>
<ul>
  <li>Håll lokalen i gott skick och städa efter dig</li>
  <li>Visa respekt för andras utrustning och spel</li>
  <li>Förstå att föreningen inte alltid kan tillgodose alla önskemål – vi är beroende av att det finns intresserade medlemmar som driver aktiviteter</li>
</ul>

<h2>Aktiviteter</h2>
<p>Vi erbjuder aktiviteter i fyra huvudkategorier: brädspel, rollspel, kortspel och figurspel. Aktiviteterna varierar från öppna drop-in-kvällar till mer strukturerade kampanjer och turneringar. Vad som händer beror på vilka engagerade medlemmar som driver det.</p>

<h2>Hur man kommer igång</h2>
<p>Ny i föreningen? Kom på en spelkväll och prata med oss. Gå med på Discord för att se vad som händer och ställa frågor. Har du vänner som vill testa ett visst spel kan ni starta en grupp – vi hjälper gärna till.</p>

<p>Är du osäker på vilket spelintresse du vill utforska? SVEROKs spelguider är ett bra ställe att börja.</p>

<h2>Styrelse</h2>

[organizational-role slug="styrelsen"]

<h2>Valberedning</h2>

[organizational-role slug="valberedningen"]

<h2>Revisorer</h2>

[organizational-role slug="revisorerna"]
HTML,
            ],

            // ── Medlemskap och förmåner ────────────────────────────────────
            [
                'seeder_key'   => 'medlemskap',
                'slug'         => 'medlemskap',
                'path_prefix'  => 'om-oss',
                'title'        => 'Medlemskap och förmåner',
                'subtitle'     => 'Avgifter, förmåner och hur du går med',
                'is_published' => true,
                'show_in_nav'  => false,
                'sort_order'   => 13,
                'content'      => <<<HTML
<p>Bli member i Armagedon och få tillgång till vår lokal, spelbibliotek och alla regelbundna aktiviteter. Medlemskapet löper 1 januari–31 december. Årsmötet hålls vanligtvis i februari.</p>

<h2>Avgifter</h2>

[membership-fees]

<h2>Förmåner</h2>
<ul>
  <li>Tillgång till lokalen vid alla öppna aktiviteter</li>
  <li>Lån ur spelbiblioteket (100+ brädspel, 100+ rollspelsspel)</li>
  <li>Möjlighet att boka lokalen för egna arrangemang</li>
  <li>Rösträtt på föreningsmöten</li>
</ul>

<h2>Skåpshyra och passerkort</h2>
<p>Medlemmar kan hyra ett förvaringsskåp i lokalen:</p>
<ul>
  <li>Stort skåp: 400 kr/år</li>
  <li>Litet skåp: 100 kr/år</li>
</ul>
<p>Efter tre månaders medlemskap kan du ansöka om ett passerkort som ger dig tillgång till lokalen dygnet runt. Kortet kostar 75 kr (engångskostnad). Medlemmar under 18 år behöver målsmans godkännande.</p>

<h2>Prova innan du bestämmer dig</h2>
<p>Nya besökare kan delta vid upp till tre tillfällen utan att vara medlem. Välkommen att testa!</p>

<h2>Integritetspolicy</h2>
<p>Vi samlar in namn, adress och telefonnummer för föreningsadministration. Uppgifterna används vid bidragsansökningar hos kommunen och studieorganisationer. Personuppgifter publiceras aldrig, delas inte med privatpersoner och används inte för direktmarknadsföring.</p>
HTML,
            ],

            // ── Våra stadgar ───────────────────────────────────────────────
            [
                'seeder_key'   => 'vara-stadgar',
                'slug'         => 'vara-stadgar',
                'path_prefix'  => 'om-oss',
                'title'        => 'Våra stadgar',
                'subtitle'     => 'Föreningens regelbok',
                'is_published' => true,
                'show_in_nav'  => false,
                'sort_order'   => 14,
                'content'      => <<<HTML
<p>Stadgarna är föreningens främsta regelbok. De beskriver hur föreningen styrs, vad som gäller på årsmötet och vad det innebär att vara medlem.</p>

<p>Stadgarna fastställs av föreningens medlemmar på årsmötet och kan bara ändras med beslut av två på varandra följande möten.</p>

<p><a href="https://www.armagedon.se/foreningens-stadgar/">Ladda ner stadgarna som PDF</a></p>
HTML,
            ],

            // ── Aktiviteter (översikt) ─────────────────────────────────────
            [
                'seeder_key'   => 'aktiviteter',
                'slug'         => 'aktiviteter',
                'path_prefix'  => null,
                'title'        => 'Föreningsaktiviteter',
                'subtitle'     => 'Vad händer i föreningen?',
                'is_published' => true,
                'show_in_nav'  => false,
                'sort_order'   => 20,
                'content'      => <<<HTML
<p>Vi arrangerar regelbundna spelkvällar varje vecka. Oavsett om du föredrar brädspel, rollspel, kortspel eller figurspel finns det något för dig.</p>

<ul>
  <li><strong>Tisdag</strong> – Figurspel (Warhammer 40K, Age of Sigmar med mera)</li>
  <li><strong>Torsdag</strong> – Brädspel från klockan 18</li>
  <li><strong>Söndag</strong> – Kortspel från ungefär klockan 10</li>
</ul>

<p>Håll utkik på Discord för aktuell information om vad som händer varje vecka.</p>
HTML,
            ],

            // ── Rollspel ───────────────────────────────────────────────────
            [
                'seeder_key'   => 'rollspel',
                'slug'         => 'rollspel',
                'path_prefix'  => 'aktiviteter',
                'title'        => 'Rollspel',
                'subtitle'     => 'Fantasi, berättande och äventyr',
                'is_published' => true,
                'show_in_nav'  => false,
                'sort_order'   => 21,
                'content'      => <<<HTML
<p>I rollspel tar du rollen som en karaktär – en magiker, krigare, jediriddare eller pirat – i en berättelse som formas av dina val. En spelledare beskriver världen och händelserna, och resultaten avgörs av dina beslut och ibland ett tärningskast.</p>

<p>Rollspel handlar om fantasi, berättande och samarbete. Kända svenska titlar är Drakar och Demoner samt Mutant, men vi spelar allt från Dungeons &amp; Dragons till moderna indiespel.</p>

<p>Vi har ett bibliotek med över 100 rollspelsspel att låna. Vill du starta en kampanj eller leta efter en grupp att spela med? Hör av dig på Discord!</p>
HTML,
            ],

            // ── Brädspel ───────────────────────────────────────────────────
            [
                'seeder_key'   => 'bradspel',
                'slug'         => 'bradspel',
                'path_prefix'  => 'aktiviteter',
                'title'        => 'Brädspel',
                'subtitle'     => 'Från Fia med knuff till Age of Steam',
                'is_published' => true,
                'show_in_nav'  => false,
                'sort_order'   => 22,
                'content'      => <<<HTML
<p>Brädspel är kanske den spelform som flest känner igen. Från klassiker som Fia med knuff och Monopol till moderna favoriter som Carcassonne och Settlers of Catan – utbudet är enormt.</p>

<p>På Armagedon spelar vi allt: enkla familjespel, kooperativa äventyr, strategitunga euro-spel och massiva historiska krigsspel. Oavsett om du söker strategiska och taktiska utmaningar eller ett avslappnat sällskapsspel finns det plats för dig.</p>

<p>Vi träffas <strong>varje torsdag från klockan 18</strong>. Det finns alltid plats för nya spelare. Spelbiblioteket med 100+ spel är tillgängligt för alla medlemmar.</p>

<p>Håll koll på vår Facebook-grupp och Discord för kampanjer och nyheter.</p>
HTML,
            ],

            // ── Kortspel ───────────────────────────────────────────────────
            [
                'seeder_key'   => 'kortspel',
                'slug'         => 'kortspel',
                'path_prefix'  => 'aktiviteter',
                'title'        => 'Kortspel',
                'subtitle'     => 'Samlarkortspel och turneringar',
                'is_published' => true,
                'show_in_nav'  => false,
                'sort_order'   => 23,
                'content'      => <<<HTML
<p>Med kortspel avser vi samlarkortspel (CCG/TCG) – inte poker eller vanliga kortspel. Det liknar samlarkort som kan spelas mot varandra. Kända exempel är Pokémon och Magic: The Gathering.</p>

<p>Det gäller att ha rätt kombination av kort och spela dem i rätt ordning och vid rätt tillfälle. En viktig del av spelet är att läsa motspelaren och förutse vad de tänker göra.</p>

<p>Vi spelar <strong>nästan varje söndag från ungefär klockan 10</strong>. Populära titlar hos oss är Magic: The Gathering och Disney Lorcana. Kolla i Discord för att bekräfta att det är speldag.</p>
HTML,
            ],

            // ── Figurspel ──────────────────────────────────────────────────
            [
                'seeder_key'   => 'figurspel',
                'slug'         => 'figurspel',
                'path_prefix'  => 'aktiviteter',
                'title'        => 'Figurspel',
                'subtitle'     => 'Taktik, strategi och konsthantverket att måla miniatyrer',
                'is_published' => true,
                'show_in_nav'  => false,
                'sort_order'   => 24,
                'content'      => <<<HTML
<p>Figurspel kombinerar taktiskt och strategiskt spel med hobbyn att bygga och måla miniatyrer och terräng. Spelbrädet med sin bemålade terräng och sina figurer ska se lika imponerande ut som det ska spelas.</p>

<h2>Hur fungerar det?</h2>
<p>Figurer representerar karaktärer och monster i strid. Du mäter avstånd med linjal istället för att flytta på ett rutnät. Viktiga regler är siktlinje – en figur kan inte skjuta på mål den inte kan se – och avstånd. Tärningskast avgör om skott och besvärjelser lyckas.</p>

<h2>Hobbydelen</h2>
<p>Utöver spelet lägger många spelare ned stor möda på att bygga och måla sina figurer och terränger. Föreningen har en dedikerad målningsstation och spraybox.</p>

<h2>Vår verksamhet</h2>
<ul>
  <li><strong>Figurspelstisdag:</strong> Öppen spelkväll varje tisdag</li>
  <li><strong>40K Games Day:</strong> Warhammer 40 000-dag den andra lördagen varje månad</li>
</ul>

<p>Vi spelar ett stort antal olika spel – från Warhammer 40 000 och Age of Sigmar till Star Wars: Legion och Necromunda. Gå med på Discord för att hitta motspelare och hålla koll på aktiviteter.</p>
HTML,
            ],

            // ── Kontakt ────────────────────────────────────────────────────
            [
                'seeder_key'   => 'kontakt',
                'slug'         => 'kontakt',
                'path_prefix'  => null,
                'title'        => 'Kontakt',
                'subtitle'     => 'Hör av dig till oss',
                'is_published' => true,
                'show_in_nav'  => false,
                'sort_order'   => 90,
                'content'      => <<<HTML
<p>Har du frågor om Armagedon? Hör av dig via e-post, Discord eller besök oss på plats.</p>

<h2>Kontaktuppgifter</h2>
<ul>
  <li><strong>E-post:</strong> <a href="mailto:kansli@armagedon.se">kansli@armagedon.se</a></li>
  <li><strong>Discord:</strong> <a href="http://discord.armagedon.se">discord.armagedon.se</a></li>
  <li><strong>Adress:</strong> Katrinehillsgatan 7b, 504 52 Borås</li>
</ul>

<h2>Facebook</h2>
<ul>
  <li><a href="https://www.facebook.com/groups/armagedonboras/">Armagedon Borås</a></li>
  <li><a href="https://www.facebook.com/groups/ArmagedonBoardgames">Armagedon Brädspel Borås</a></li>
</ul>

<h2>Boka lokalen</h2>
<p>Medlemmar kan boka lokalen för egna arrangemang. Hämta bokningsformuläret och skicka det till <a href="mailto:kansli@armagedon.se">kansli@armagedon.se</a>.</p>

<h2>Borås Spelkonvent</h2>
<p>Frågor om Borås Spelkonvent besvaras på <a href="https://www.borasspelkonvent.se">borasspelkonvent.se</a>.</p>
HTML,
            ],
        ];

        foreach ($pages as $data) {
            // Använd seeder_key som stabil identifierare om den finns,
            // annars fall tillbaka på slug + path_prefix (bakåtkompatibilitet).
            $key = $data['seeder_key'] ?? null;
            $existing = $key ? Page::withTrashed()->where('seeder_key', $key)->first() : null;

            if ($existing) {
                // Uppdatera inte — befintlig post kan ha admin-ändringar
                continue;
            }

            Page::updateOrCreate(
                ['slug' => $data['slug'], 'path_prefix' => $data['path_prefix']],
                $data
            );
        }
    }

    private function seedNavigation(): void
    {
        // Använd seeder_key för att undvika dubbletter vid re-seed.
        // Skapar bara poster som inte redan finns — admin-ändringar bevaras.
        $omOss = $this->navItem('topnav.om-oss', [
            'label'    => 'Om oss',
            'url'      => '/om-oss',
            'location' => NavigationItem::LOCATION_TOPNAV,
            'order'    => 10,
        ]);

        $aktiviteter = $this->navItem('topnav.aktiviteter', [
            'label'    => 'Aktiviteter',
            'url'      => '/aktiviteter',
            'location' => NavigationItem::LOCATION_TOPNAV,
            'order'    => 20,
        ]);

        $this->navItem('topnav.kontakt', [
            'label'    => 'Kontakt',
            'url'      => '/kontakt',
            'location' => NavigationItem::LOCATION_TOPNAV,
            'order'    => 90,
        ]);

        // ── Om oss – underpunkter ──────────────────────────────────────────
        if ($omOss) {
            $this->navItem('topnav.om-oss.var-lokal', ['label' => 'Vår lokal',              'url' => '/om-oss/var-lokal',    'location' => NavigationItem::LOCATION_TOPNAV, 'order' => 1, 'parent_id' => $omOss->id]);
            $this->navItem('topnav.om-oss.sa-funkar-vi', ['label' => 'Så funkar vi',        'url' => '/om-oss/sa-funkar-vi', 'location' => NavigationItem::LOCATION_TOPNAV, 'order' => 2, 'parent_id' => $omOss->id]);
            $this->navItem('topnav.om-oss.medlemskap', ['label' => 'Medlemskap och förmåner','url' => '/om-oss/medlemskap',   'location' => NavigationItem::LOCATION_TOPNAV, 'order' => 3, 'parent_id' => $omOss->id]);
            $this->navItem('topnav.om-oss.vara-stadgar', ['label' => 'Våra stadgar',        'url' => '/om-oss/vara-stadgar', 'location' => NavigationItem::LOCATION_TOPNAV, 'order' => 4, 'parent_id' => $omOss->id]);
        }

        // ── Aktiviteter – underpunkter ─────────────────────────────────────
        if ($aktiviteter) {
            $this->navItem('topnav.aktiviteter.rollspel',  ['label' => 'Rollspel',  'url' => '/aktiviteter/rollspel',  'location' => NavigationItem::LOCATION_TOPNAV, 'order' => 1, 'parent_id' => $aktiviteter->id]);
            $this->navItem('topnav.aktiviteter.bradspel',  ['label' => 'Brädspel',  'url' => '/aktiviteter/bradspel',  'location' => NavigationItem::LOCATION_TOPNAV, 'order' => 2, 'parent_id' => $aktiviteter->id]);
            $this->navItem('topnav.aktiviteter.kortspel',  ['label' => 'Kortspel',  'url' => '/aktiviteter/kortspel',  'location' => NavigationItem::LOCATION_TOPNAV, 'order' => 3, 'parent_id' => $aktiviteter->id]);
            $this->navItem('topnav.aktiviteter.figurspel', ['label' => 'Figurspel', 'url' => '/aktiviteter/figurspel', 'location' => NavigationItem::LOCATION_TOPNAV, 'order' => 4, 'parent_id' => $aktiviteter->id]);
        }

        // ── Footer ─────────────────────────────────────────────────────────
        $this->navItem('footer.om-oss',      ['label' => 'Om oss',      'url' => '/om-oss',              'location' => NavigationItem::LOCATION_FOOTER, 'order' => 10]);
        $this->navItem('footer.aktiviteter', ['label' => 'Aktiviteter', 'url' => '/aktiviteter',         'location' => NavigationItem::LOCATION_FOOTER, 'order' => 20]);
        $this->navItem('footer.kontakt',     ['label' => 'Kontakt',     'url' => '/kontakt',             'location' => NavigationItem::LOCATION_FOOTER, 'order' => 30]);
        $this->navItem('footer.stadgar',     ['label' => 'Stadgar',     'url' => '/om-oss/vara-stadgar', 'location' => NavigationItem::LOCATION_FOOTER, 'order' => 40]);
    }

    /**
     * Skapar ett NavigationItem om seeder_key inte redan finns.
     * Returnerar det befintliga eller nyskapade item:et.
     */
    private function navItem(string $seederKey, array $data): ?NavigationItem
    {
        $existing = NavigationItem::withTrashed()->where('seeder_key', $seederKey)->first();

        if ($existing) {
            return $existing->trashed() ? null : $existing;
        }

        return NavigationItem::create(array_merge($data, [
            'seeder_key' => $seederKey,
            'is_active'  => true,
        ]));
    }

    private function seedHelpTexts(): void
    {
        $helpTexts = [
            [
                'key'          => 'footer.description',
                'default_text' => 'Armagedon är Borås spelförening för brädspel, miniatyrspel och rollspel. Vi håller till på Katrinehillsgatan 7b, 504 52 Borås.',
                'group'        => 'footer',
                'field_label'  => 'Beskrivning i sidfoten',
            ],
            [
                'key'          => 'footer.contact',
                'default_text' => 'kansli@armagedon.se',
                'group'        => 'footer',
                'field_label'  => 'E-postadress i sidfoten',
            ],
        ];

        foreach ($helpTexts as $data) {
            HelpText::updateOrCreate(['key' => $data['key']], $data);
        }
    }
}
