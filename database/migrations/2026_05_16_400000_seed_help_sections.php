<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $sections = [

            // ── Allmänt (publik, ingen grupp) ─────────────────────────────────────

            [
                'slug'                => 'vad-ar-membrum',
                'title'               => 'Vad är det här för system?',
                'group_label'         => '',
                'show_public'         => true,
                'show_auth'           => true,
                'required_permission' => null,
                'sort_order'          => 10,
                'content'             => <<<'HTML'
<p>Det här är Armagedon Spelförenings medlemssystem — kallat Membrum. Här hanteras allt från medlemskap och betalningar till evenemang, webshop, lokalbokningar och föreningens interna kommunikation.</p>
<h4>Vad kan du göra här?</h4>
<ul>
  <li>Anmäla dig till evenemang och betala entréavgifter</li>
  <li>Handla i föreningens webshop</li>
  <li>Boka en av föreningens lokaler för ditt sällskap</li>
  <li>Se dina hyresavtal om du hyr utrustning av föreningen</li>
  <li>Svara på enkäter och fylla i formulär</li>
  <li>Lämna in utläggsärenden</li>
  <li>Hålla koll på ditt medlemskap och betala årsavgiften</li>
</ul>
<h4>Behöver du logga in?</h4>
<p>Evenemangslistan, butiken och grundläggande information är öppna för alla. För att anmäla dig, handla, boka eller se dina uppgifter behöver du logga in med ditt Google-konto (<code>@gmail.com</code> eller <code>@armagedon.se</code>).</p>
HTML,
            ],

            [
                'slug'                => 'hitta-ratt',
                'title'               => 'Hitta rätt i systemet',
                'group_label'         => '',
                'show_public'         => true,
                'show_auth'           => true,
                'required_permission' => null,
                'sort_order'          => 20,
                'content'             => <<<'HTML'
<p>Systemet är uppdelat i ett antal avsnitt som du når via vänstermenyn (desktop) eller hamburgermenyn (mobil):</p>
<ul>
  <li><strong>Evenemang</strong> — evenemangslista, kalender, anmälningar och biljetter (<a href="/evenemang">/evenemang</a>)</li>
  <li><strong>Butik</strong> — föreningens webshop (<a href="/butik">/butik</a>)</li>
  <li><strong>Lokalbokningar</strong> — boka rum och se bokningskalendern (<a href="/kalender">/kalender</a>)</li>
  <li><strong>Uthyrning</strong> — hyra utrustning, se aktiva hyresavtal</li>
  <li><strong>Utlägg</strong> — skicka in och följa utläggsärenden (<a href="/utlagg">/utlagg</a>)</li>
  <li><strong>Mina sidor</strong> — ditt konto, beställningar, bokningar och anmälningar</li>
  <li><strong>Hjälp</strong> — den här sidan (<a href="/hjalp">/hjalp</a>)</li>
</ul>
<p>Admins ser dessutom ett <strong>Admin</strong>-avsnitt längst ner i menyn med tillgång till alla hanteringsverktyg.</p>
HTML,
            ],

            // ── Konto & inloggning ──────────────────────────────────────────────

            [
                'slug'                => 'logga-in',
                'title'               => 'Logga in',
                'group_label'         => 'Konto & inloggning',
                'show_public'         => true,
                'show_auth'           => true,
                'required_permission' => null,
                'sort_order'          => 51,
                'content'             => <<<'HTML'
<p>Du loggar in med ditt Google-konto via knappen <strong>Logga in med Google</strong> på inloggningssidan. Inga separata lösenord — ditt Google-konto <em>är</em> ditt konto här.</p>
<h4>Vilket Google-konto ska jag använda?</h4>
<ul>
  <li>Använd ditt vanliga privata <code>@gmail.com</code>-konto.</li>
  <li>Om du har ett <code>@armagedon.se</code>-konto (styrelse/funktionär) kan du använda det istället eller länka det som ett sekundärt konto.</li>
  <li>Du kan länka flera Google-konton till samma profil under <a href="/profile">Mina inställningar</a>.</li>
</ul>
<h4>Kiosk-inloggning</h4>
<p>Vid föreningens skanningstation kan du logga in genom att hålla ditt RFID-kort mot läsaren istället för att använda Google. Fråga en administratör om du vill koppla ett RFID-kort till ditt konto.</p>
<h4>Inloggningsproblem?</h4>
<p>Om du inte kan logga in med Google, kontrollera att du väljer rätt Google-konto. Om ditt konto inte är kopplat till ett aktivt medlemskap kan du ändå logga in men åtkomsten till vissa funktioner är begränsad.</p>
HTML,
            ],

            [
                'slug'                => 'bli-medlem',
                'title'               => 'Betala medlemsavgiften',
                'group_label'         => 'Konto & inloggning',
                'show_public'         => true,
                'show_auth'           => true,
                'required_permission' => null,
                'sort_order'          => 52,
                'content'             => <<<'HTML'
<p>För att bli fullvärdig medlem och låsa upp alla funktioner behöver du betala årsavgiften. Gå till <a href="/bli-medlem">Bli medlem</a> i menyn.</p>
<h4>Betalsätt</h4>
<ul>
  <li><strong>Kortbetalning (Stripe)</strong> — betalas direkt, medlemskapet aktiveras omedelbart.</li>
  <li><strong>Swish</strong> — scanna QR-koden med Swish-appen. Aktiveras inom någon minut.</li>
  <li><strong>Bankgiro</strong> — betala med OCR-referensen till föreningens bankgiro. Aktiveras när betalningen registrerats (normalt 1–3 bankdagar).</li>
</ul>
<h4>Familjemedlemskap</h4>
<p>Föreningen erbjuder familjemedlemskap för hushåll med barn. Ta kontakt med styrelsen för mer information om du vill lägga till familjemedlemmar.</p>
<h4>Förnyelse</h4>
<p>Ditt medlemskap gäller ett kalenderår. Du får en påminnelse via e-post när det är dags att förnya. Hittar du inga mejl — kolla skräpposten.</p>
HTML,
            ],

            // Google Workspace is already seeded at sort_order=100

            // ── Evenemang ────────────────────────────────────────────────────────

            [
                'slug'                => 'evenemang-anmalan',
                'title'               => 'Anmäla sig till evenemang',
                'group_label'         => 'Evenemang',
                'show_public'         => false,
                'show_auth'           => true,
                'required_permission' => null,
                'sort_order'          => 110,
                'content'             => <<<'HTML'
<p>Föreningens evenemang hittar du under <a href="/evenemang">Evenemang</a>. Klicka på ett evenemang för att se beskrivning, tid, plats och tillgängliga biljetttyper.</p>
<h4>Anmäla sig</h4>
<ol>
  <li>Öppna evenemanget och välj önskad biljettyp (om det finns flera prisnivåer).</li>
  <li>Välj eventuella tillägg (t.ex. middag, t-shirt).</li>
  <li>Klicka <strong>Anmäl mig</strong>. Gratisevenemang bekräftas direkt; betalda evenemang skickar dig vidare till kassan.</li>
</ol>
<h4>Statusar</h4>
<ul>
  <li><strong>Bekräftad</strong> — du är anmäld och klar.</li>
  <li><strong>Väntar på betalning</strong> — din plats är reserverad men betalning måste slutföras.</li>
  <li><strong>Väntelista</strong> — evenemanget är fullt; du meddelas om en plats öppnas.</li>
</ul>
<h4>Platsbegränsningar</h4>
<p>Om ett evenemang har begränsat antal platser visas det på evenemangets sida. Är evenemanget fullt kan du anmäla dig till väntelistan.</p>
HTML,
            ],

            [
                'slug'                => 'evenemang-betalning',
                'title'               => 'Betala för ett evenemang',
                'group_label'         => 'Evenemang',
                'show_public'         => false,
                'show_auth'           => true,
                'required_permission' => null,
                'sort_order'          => 120,
                'content'             => <<<'HTML'
<p>Betalda evenemang hanteras via föreningens kassa. Du väljer betalsätt när du checkar ut.</p>
<h4>Betalsätt</h4>
<ul>
  <li><strong>Kort (Stripe)</strong> — betalas direkt, anmälan bekräftas omedelbart.</li>
  <li><strong>Swish</strong> — scanna QR-koden, betalning bekräftas inom sekunder.</li>
  <li><strong>Bankgiro</strong> — betala till föreningens bankgiro med din OCR-referens. Anmälan aktiveras när betalningen inkommer (1–3 bankdagar). <strong>Obs!</strong> Du förlorar din plats om betalning inte inkommit inom utsatt tid.</li>
</ul>
<h4>Ej betalat?</h4>
<p>Håll koll på din anmälan under <a href="/mina-anmalningar">Mina anmälningar</a>. Om statusen fortfarande är "Väntar på betalning" har betalningen ännu inte registrerats. Kontakta en administratör om du vet att du betalat via bankgiro men anmälan fortfarande är ej bekräftad.</p>
HTML,
            ],

            [
                'slug'                => 'mina-anmalningar',
                'title'               => 'Mina anmälningar och biljetter',
                'group_label'         => 'Evenemang',
                'show_public'         => false,
                'show_auth'           => true,
                'required_permission' => null,
                'sort_order'          => 130,
                'content'             => <<<'HTML'
<p>Under <a href="/mina-anmalningar">Mina anmälningar</a> ser du alla dina eventregistreringar — kommande och historiska.</p>
<h4>Biljetter och incheckning</h4>
<p>Varje bekräftad anmälan innehåller en QR-kod som används vid incheckning på plats. Visa QR-koden för den som checkar in deltagarna.</p>
<h4>Avboka</h4>
<p>Du kan avboka en anmälan fram tills dess att evenemangets avbokningsgräns passerat (om en sådan finns). Klicka på <strong>Avboka</strong> på anmälningens rad. Återbetalning hanteras av administratören och sker ej automatiskt.</p>
HTML,
            ],

            // ── Webshop ──────────────────────────────────────────────────────────

            [
                'slug'                => 'shop-handla',
                'title'               => 'Handla i butiken',
                'group_label'         => 'Webshop',
                'show_public'         => true,
                'show_auth'           => true,
                'required_permission' => null,
                'sort_order'          => 140,
                'content'             => <<<'HTML'
<p>Föreningens webshop hittar du under <a href="/butik">Butik</a>. Här säljs allt från konvent-merchandise till spel och tillbehör.</p>
<h4>Priser</h4>
<p>De flesta produkter har ett <strong>medlemspris</strong> och ett <strong>öppet pris</strong>. Om du är inloggad som aktivt medlem visas medlemspriset automatiskt.</p>
<h4>Varianter</h4>
<p>Produkter med varianter (t.ex. t-shirt i olika storlekar) låter dig välja variant innan du lägger i varukorgen. Lager visas per variant — är en storlek slutsåld kan du inte lägga till den.</p>
<h4>Lagerstatus</h4>
<p>Produkter kan vara <strong>i lager</strong>, <strong>begränsat lager</strong> (visas med antal) eller <strong>slut i lager</strong>. Slut i lager-produkter kan inte beställas om.</p>
HTML,
            ],

            [
                'slug'                => 'shop-kassa',
                'title'               => 'Varukorg och kassa',
                'group_label'         => 'Webshop',
                'show_public'         => false,
                'show_auth'           => true,
                'required_permission' => null,
                'sort_order'          => 150,
                'content'             => <<<'HTML'
<p>Din varukorg sparas automatiskt så länge du är inloggad. Klicka på varukorgsikonen i övre högra hörnet för att se innehållet.</p>
<h4>Genomföra ett köp</h4>
<ol>
  <li>Gå till <a href="/kassa">Kassan</a> när du är redo.</li>
  <li>Välj betalsätt: kort, Swish eller bankgiro.</li>
  <li>Slutför betalningen. En orderbekräftelse skickas till din e-post.</li>
</ol>
<h4>Rabattkoder</h4>
<p>Har du en rabattkod? Ange den i kassans rabattfält. Rabatten dras av direkt och koden markeras som använd.</p>
<h4>Mina beställningar</h4>
<p>Under <a href="/mina-bestallningar">Mina beställningar</a> ser du alla dina tidigare ordrar, status och fakturanedladdning.</p>
HTML,
            ],

            // ── Uthyrning ────────────────────────────────────────────────────────

            [
                'slug'                => 'uthyrning-hyra',
                'title'               => 'Hyra utrustning',
                'group_label'         => 'Uthyrning',
                'show_public'         => false,
                'show_auth'           => true,
                'required_permission' => null,
                'sort_order'          => 160,
                'content'             => <<<'HTML'
<p>Föreningen hyr ut utrustning — till exempel bord, stolar och AV-utrustning — till bra priser för medlemmar.</p>
<h4>Hur man hyr</h4>
<p>Kontakta en administratör för att starta ett hyresavtal. Du behöver uppge:</p>
<ul>
  <li>Vilken typ av utrustning du vill hyra</li>
  <li>Önskad hyresperiod</li>
</ul>
<p>Hyran faktureras årsvis och läggs till ditt konto.</p>
<h4>Aktiva hyresavtal</h4>
<p>Dina aktiva hyresavtal och fakturor syns under ditt konto i Mina sidor.</p>
HTML,
            ],

            [
                'slug'                => 'uthyrning-intresselista',
                'title'               => 'Intresselista för uthyrning',
                'group_label'         => 'Uthyrning',
                'show_public'         => false,
                'show_auth'           => true,
                'required_permission' => null,
                'sort_order'          => 165,
                'content'             => <<<'HTML'
<p>Om en utrustningstyp är fullt uthyrd kan du anmäla ditt intresse via intresselistan. Du meddelas när ett hyresavtal avslutas och utrustningen blir ledig.</p>
<h4>Anmäla intresse</h4>
<p>Klicka på <strong>Anmäl intresse</strong> på utrustningens sida, eller fyll i ett formulär som administratörerna skickat ut via systemet. Du kan vara med på intresselistan för flera utrustningstyper samtidigt.</p>
<h4>Ta bort sig från listan</h4>
<p>Kontakta en administratör om du vill tas bort från en intresselista.</p>
HTML,
            ],

            // ── Lokalbokningar ───────────────────────────────────────────────────

            [
                'slug'                => 'lokalbokning-boka',
                'title'               => 'Boka en lokal',
                'group_label'         => 'Lokalbokningar',
                'show_public'         => false,
                'show_auth'           => true,
                'required_permission' => null,
                'sort_order'          => 170,
                'content'             => <<<'HTML'
<p>Föreningens lokaler kan bokas av aktiva medlemmar. Se bokningskalendern under <a href="/kalender">Kalender</a> för att se lediga tider.</p>
<h4>Skapa en bokning</h4>
<ol>
  <li>Gå till <a href="/boka">Boka</a> i menyn eller klicka på en ledig tid i kalendern.</li>
  <li>Välj lokal, datum, start- och sluttid.</li>
  <li>Ge bokningen en titel och välj aktivitetstyp.</li>
  <li>Ange om det är en sluten grupp eller ett öppet arrangemang.</li>
  <li>Bekräfta. Lokaler som kräver godkännande skickar en förfrågan till administratörerna.</li>
</ol>
<h4>Bokningsgräns</h4>
<p>Varje medlem kan ha ett begränsat antal aktiva bokningar. Kontakta administratörerna om du behöver undantag för ett föreningsarrangemang.</p>
<h4>Mina bokningar</h4>
<p>Under <a href="/mina-bokningar">Mina bokningar</a> ser du alla dina kommande och historiska lokalbokningar. Härifrån kan du redigera eller avboka.</p>
HTML,
            ],

            // ── Enkäter & formulär ───────────────────────────────────────────────

            [
                'slug'                => 'enkater',
                'title'               => 'Svara på en enkät',
                'group_label'         => 'Enkäter & formulär',
                'show_public'         => false,
                'show_auth'           => true,
                'required_permission' => null,
                'sort_order'          => 180,
                'content'             => <<<'HTML'
<p>Enkäter skickas ut av administratörerna via e-post. Länken i mejlet tar dig direkt till enkäten — ingen separat inloggning behövs för att svara.</p>
<h4>Svarstyper</h4>
<p>Enkäter kan innehålla:</p>
<ul>
  <li>Enkelsvar och flersvar (radioknappar, kryssrutor)</li>
  <li>Fritext (kort och lång)</li>
  <li>Skala/betyg (stjärnor, hjärtan, siffror)</li>
  <li>Datum- och nummerfält</li>
</ul>
<h4>Anonymitet</h4>
<p>Anonyma enkäter registrerar inga personuppgifter kopplade till ditt svar. Om enkäten inte är anonym ser administratörerna vem som svarat.</p>
HTML,
            ],

            [
                'slug'                => 'formulär-fylla-i',
                'title'               => 'Fylla i ett formulär',
                'group_label'         => 'Enkäter & formulär',
                'show_public'         => false,
                'show_auth'           => true,
                'required_permission' => null,
                'sort_order'          => 185,
                'content'             => <<<'HTML'
<p>Formulär används för strukturerade intresseanmälningar och beställningar — till skillnad från enkäter är formulär ofta direkt handlingsdrivande.</p>
<h4>Vad kan hända när du skickar in ett formulär?</h4>
<p>Beroende på vilka fält formuläret innehåller kan din inlämning utlösa automatiska åtgärder:</p>
<ul>
  <li><strong>Produktfält</strong> — ett ja-svar lägger till en vara i din varukorg (t.ex. förbeställning av t-shirt).</li>
  <li><strong>Eventfält</strong> — väljer du ett event skapas en eventregistrering automatiskt.</li>
  <li><strong>Sektionsfält</strong> — väljer du sektioner skickas en ansökan om att gå med (status: väntar på godkännande).</li>
  <li><strong>Medlemskapsfält</strong> — ett ja-svar lägger en medlemsavgift i varukorgen.</li>
  <li><strong>Intresselista</strong> — anmäler ditt intresse för en specifik utrustningstyp.</li>
</ul>
<p>Formulär nås via den länk du fått eller via <a href="/formulär">direktlänk</a> om administratören delat den.</p>
HTML,
            ],

            // ── Utlägg ──────────────────────────────────────────────────────────

            [
                'slug'                => 'utlagg',
                'title'               => 'Lämna in ett utläggsärende',
                'group_label'         => 'Utlägg',
                'show_public'         => false,
                'show_auth'           => true,
                'required_permission' => null,
                'sort_order'          => 190,
                'content'             => <<<'HTML'
<p>Har du lagt ut pengar för föreningen och vill ha ersättning? Gå till <a href="/utlagg/skicka-in">Skicka in utlägg</a>.</p>
<h4>Fyll i ärendet</h4>
<ol>
  <li>Ange titel och belopp (i kronor).</li>
  <li>Välj datum för utlägget.</li>
  <li>Beskriv vad köpet gäller.</li>
  <li>Ladda upp kvittot (foto eller PDF).</li>
  <li>Skicka in.</li>
</ol>
<h4>Vad händer sen?</h4>
<p>En ekonomiansvarig granskar ärendet och godkänner eller avslår det. Du ser statusen under <a href="/utlagg">Mina utlägg</a>. Vid godkännande betalas ersättningen ut via bankgiro till det kontonummer du angett i din profil.</p>
<h4>Viktigt</h4>
<p>Spara alltid originalkvittot tills ärendet är godkänt och utbetalt. Digitala kvitton (e-postbekräftelser) fungerar bra.</p>
HTML,
            ],

            // ── ADMIN — Medlemmar ────────────────────────────────────────────────

            [
                'slug'                => 'admin-medlemmar',
                'title'               => 'Hantera medlemmar',
                'group_label'         => 'Administratör — Medlemmar',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'members.manage',
                'sort_order'          => 300,
                'content'             => <<<'HTML'
<p>Under <a href="/admin/members">Admin → Medlemmar</a> hanterar du föreningens alla medlemmar.</p>
<h4>Listan</h4>
<p>Filtrera på status: aktiv, vilande, utgången, alla. Sök på namn eller e-post. Klicka på en rad för att öppna medlemsprofilen.</p>
<h4>Skapa ny medlem</h4>
<p>Gå till <a href="/admin/members/create">Ny medlem</a>. Fyll i namn, e-post och eventuellt Google-konto. En inbjudningslänk skickas automatiskt om du aktiverar det alternativet.</p>
<h4>Statusflöde</h4>
<ul>
  <li><strong>Aktiv</strong> — betalat årsavgiften, full åtkomst.</li>
  <li><strong>Vilande</strong> — inaktiverat manuellt eller via inaktiveringskörning.</li>
  <li><strong>Utgången</strong> — ingen giltig avgift innevarande år.</li>
</ul>
<h4>Avaktiveringrapport</h4>
<p>Under <a href="/admin/members/avaktiveringar">Avaktivering</a> ser du vilka medlemmar som är kandidater för avaktivering baserat på utebliven förnyelse. Du kan bekräfta eller undanta individuella poster.</p>
HTML,
            ],

            [
                'slug'                => 'admin-familjegrupper',
                'title'               => 'Familjegrupper',
                'group_label'         => 'Administratör — Medlemmar',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'members.manage',
                'sort_order'          => 310,
                'content'             => <<<'HTML'
<p>Under <a href="/admin/familjegrupper">Familjegrupper</a> hanterar du hushåll med familjemedlemskap.</p>
<h4>Struktur</h4>
<p>En familjegrupp har en <strong>administratör</strong> (vuxen ansvarig) och kan innehålla upp till ett konfigurerat antal vuxna och barn (standard: 2 vuxna, 5 barn under 18 år).</p>
<h4>Egna konton</h4>
<p>Varje familjemedlem kan ges ett eget konto (<code>has_own_account = true</code>). Barn under konfigurerad åldersgräns kan ha konto men med begränsade rättigheter.</p>
<h4>Avgifter</h4>
<p>Familjemedlemskap hanteras som en separat avgiftstyp. Kontrollera inställningarna under Medlemsavgifter för att se hur familjerabatten är konfigurerad.</p>
HTML,
            ],

            [
                'slug'                => 'admin-sektioner',
                'title'               => 'Sektioner och aktivitetsgrupper',
                'group_label'         => 'Administratör — Medlemmar',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'sections.manage',
                'sort_order'          => 320,
                'content'             => <<<'HTML'
<p>Under <a href="/admin/grupper">Admin → Sektioner</a> hanterar du föreningens aktivitetsgrupper och sektioner.</p>
<h4>Trädstruktur</h4>
<p>Sektioner kan ha undersektioner i obegränsat djup (t.ex. Brädspel → Strategispel → Ameritrash). Flytta sektioner i trädet via redigeringsvyn.</p>
<h4>Sektionsmedlemskap</h4>
<p>Varje sektion visar sina aktiva och väntande medlemmar. Acceptera eller neka väntande ansökningar (t.ex. från formuläranmälningar) under sektionens detaljsida.</p>
<h4>Sektionsroller</h4>
<p>Inom en sektion kan specifika roller tilldelas (t.ex. sektionsordförande) med giltighetsperioder.</p>
HTML,
            ],

            [
                'slug'                => 'admin-org-roller',
                'title'               => 'Org-roller och perioder',
                'group_label'         => 'Administratör — Medlemmar',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'org-roles.manage',
                'sort_order'          => 330,
                'content'             => <<<'HTML'
<p>Under <a href="/admin/org-roles">Org-roller</a> hanteras föreningens förtroendeuppdrag — styrelse, revisorer, valberedning m.m.</p>
<h4>Perioder och statusflöde</h4>
<p>Varje roll har perioder med ett tydligt statusflöde:</p>
<ul>
  <li><strong>Forming</strong> — perioden förbereds, ingen åtkomst ännu.</li>
  <li><strong>Aktiv</strong> — löpande period med full åtkomst.</li>
  <li><strong>Rapportering</strong> — perioden avslutad, årsredovisning pågår. Revisorer ser räkenskaperna.</li>
  <li><strong>Stängd</strong> — helt avslutad.</li>
</ul>
<h4>Behörigheters synkning</h4>
<p>Systemet synkar varje natt Spatie-roller baserat på aktiva perioder. Styrelsemedlemmar får rollen <code>styrelse</code>, revisorer får <code>revisor</code>. Kör <code>php artisan org-roles:sync-permissions</code> manuellt vid behov.</p>
<h4>Grace period</h4>
<p>Ex-styrelsemedlemmar behåller läsåtkomst till bokföringen i 6 månader efter periodens <code>reporting_end</code>.</p>
HTML,
            ],

            // ── ADMIN — Ekonomi ─────────────────────────────────────────────────

            [
                'slug'                => 'admin-medlemsavgifter',
                'title'               => 'Medlemsavgifter',
                'group_label'         => 'Administratör — Ekonomi',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'membership-fees.manage',
                'sort_order'          => 400,
                'content'             => <<<'HTML'
<p>Under <a href="/admin/membership-fees">Medlemsavgifter</a> konfigureras föreningens avgiftsstrukturer.</p>
<h4>Avgiftsperioder</h4>
<p>Varje avgift är kopplad till ett år och kan ha olika priser beroende på:</p>
<ul>
  <li>Typ (standard, familj, ungdom m.m.)</li>
  <li>Betalsätt (kan ge olika avgifter, t.ex. för bankgiro)</li>
</ul>
<h4>Förnyelsekörning</h4>
<p>Kommandot <code>php artisan memberships:renew</code> skickar förnyelsepåminnelser och skapar förnyelsefakturor. Det schemaläggs via cron.</p>
<h4>Bankgiro-import</h4>
<p>Inkommande bankgiro-betalningar importeras via ISO 20022-filer under <a href="/admin/bokforing/bankkonton">Bank</a>. Matchning mot avi-OCR sker automatiskt via bokföringsregler.</p>
HTML,
            ],

            [
                'slug'                => 'admin-shop-ordrar',
                'title'               => 'Webshop och ordrar',
                'group_label'         => 'Administratör — Ekonomi',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'shop.manage',
                'sort_order'          => 410,
                'content'             => <<<'HTML'
<p>Under <a href="/admin/butik/produkter">Admin → Butik</a> hanteras produkter, lager och ordrar.</p>
<h4>Produkter</h4>
<p>Varje produkt kan ha:</p>
<ul>
  <li><strong>Varianter</strong> — t.ex. storlekar (S/M/L). Lager hanteras per variant.</li>
  <li><strong>Dubbla priser</strong> — ett medlemspris och ett öppet pris.</li>
  <li><strong>Kategorier och taggar</strong> — för filtrering i butiken.</li>
  <li><strong>Synlighet</strong> — publik, bara inloggade, eller dold.</li>
</ul>
<h4>Ordrar</h4>
<p>Under <a href="/admin/butik/ordrar">Ordrar</a> ser du alla beställningar med status, betalningssätt och belopp. Du kan manuellt ändra status, godkänna eller avbryta ordrar och skicka om orderbekräftelsen.</p>
<h4>Lagerhantering</h4>
<p>Lagerjusteringar görs under produktens lagersida. Systemet loggar alla lagerrörelser.</p>
HTML,
            ],

            [
                'slug'                => 'admin-rabatter',
                'title'               => 'Rabatter och kampanjkoder',
                'group_label'         => 'Administratör — Ekonomi',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'discounts.manage',
                'sort_order'          => 420,
                'content'             => <<<'HTML'
<p>Under <a href="/admin/rabatter">Admin → Rabatter</a> hanteras automatiska rabatter och kampanjkoder.</p>
<h4>Rabatttyper</h4>
<ul>
  <li><strong>Procentuell</strong> — t.ex. 20% på allt.</li>
  <li><strong>Fast belopp</strong> — t.ex. 50 kr av.</li>
</ul>
<h4>Tillämpning</h4>
<p>En rabatt kan begränsas till specifika tillämpningsområden:</p>
<ul>
  <li><strong>Alla</strong> — gäller allt i kassan.</li>
  <li><strong>Webshop</strong> — gäller bara butiksprodukter.</li>
  <li><strong>Evenemang</strong> — gäller eventregistreringar.</li>
  <li><strong>Medlemsavgift</strong> — gäller bara årsavgiften.</li>
</ul>
<h4>Kampanjkoder</h4>
<p>Skapa koder kopplade till en rabatt. En kod kan vara engångsanvändning (priser, giveaway) eller delbar (kampanj). Koder kan skrivas ut som QR-PDF under rabattens kodlista.</p>
<h4>Tak och villkor</h4>
<p>Du kan sätta maxrabatt i kronor, maxandel av totalsumman och krav som "gäller bara vid första köpet".</p>
HTML,
            ],

            [
                'slug'                => 'admin-bokforing',
                'title'               => 'Bokföring',
                'group_label'         => 'Administratör — Ekonomi',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'accounting.manage',
                'sort_order'          => 430,
                'content'             => <<<'HTML'
<p>Under <a href="/admin/bokforing">Admin → Bokföring</a> finns föreningens fullständiga bokföringssystem.</p>
<h4>Kontoplan</h4>
<p>Hantera kontostrukturen under <a href="/admin/bokforing/kontoplan">Kontoplan</a>. Konton är organiserade i grupper (tillgångar, skulder, intäkter, kostnader).</p>
<h4>Bankkonton och import</h4>
<p>Importera kontoutdrag (ISO 20022/camt.054) under <a href="/admin/bokforing/bankkonton">Bankkonton</a>. Bokföringsregler matchar transaktioner mot konton automatiskt. Kvarvarande oarbetade transaktioner stäms av manuellt.</p>
<h4>Leverantörsfakturor</h4>
<p>Under <a href="/admin/bokforing/leverantorsfakturor">Leverantörsfakturor</a> registreras inkommande fakturor. Du kan ladda upp PDF:er som kopplas till respektive verifikat. Fakturainkorg (<a href="/admin/bokforing/fakturainkorg">Fakturainkorg</a>) fångar upp fakturor skickade direkt till ett e-postkonto.</p>
<h4>Rapporter</h4>
<p>Tillgängliga rapporter under <a href="/admin/bokforing/rapporter/resultatrakning">Rapporter</a>:</p>
<ul>
  <li>Resultaträkning</li>
  <li>Balansräkning</li>
  <li>Huvudbok</li>
  <li>Verifikationslista</li>
  <li>Budgetuppföljning</li>
</ul>
<h4>SIE4-export</h4>
<p>Exportera räkenskapsårets data i SIE4-format under räkenskapsårets inställningssida — kompatibelt med de flesta svenska revisionsverktyg.</p>
HTML,
            ],

            // ── ADMIN — Evenemang ────────────────────────────────────────────────

            [
                'slug'                => 'admin-evenemang',
                'title'               => 'Skapa och hantera evenemang',
                'group_label'         => 'Administratör — Evenemang',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'events.manage',
                'sort_order'          => 500,
                'content'             => <<<'HTML'
<p>Under <a href="/admin/evenemang">Admin → Evenemang</a> skapar och redigerar du föreningens evenemang.</p>
<h4>Skapa ett evenemang</h4>
<ol>
  <li>Gå till <a href="/admin/evenemang/skapa">Skapa evenemang</a>.</li>
  <li>Fyll i titel, beskrivning, datum/tid och plats.</li>
  <li>Lägg till <strong>prisnivåer</strong> (t.ex. Vuxen 150 kr, Under 18 75 kr, Gratis).</li>
  <li>Lägg till eventuella <strong>tillägg</strong> (add-ons, t.ex. middag).</li>
  <li>Konfigurera kapacitet, avbokningspolicy och förhandskrav (t.ex. "kräver aktivt medlemskap").</li>
  <li>Publicera när allt ser bra ut.</li>
</ol>
<h4>Antagning</h4>
<p>Under evenemangets <a href="/admin/evenemang">Antagning</a>-flik hanteras anmälningar som kräver godkännande (om antagningsläget är aktiverat). Godkänn, flytta till väntelista eller neka.</p>
<h4>Flerdagsevenemang och förekomster</h4>
<p>Återkommande evenemang (t.ex. månadsmöten) kan skapas som en serie med förekomster. Varje förekomst kan ha egna tider men delar beskrivning och konfiguration med moderevenemanget.</p>
HTML,
            ],

            [
                'slug'                => 'admin-incheckning',
                'title'               => 'Registreringar och incheckning',
                'group_label'         => 'Administratör — Evenemang',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'events.manage',
                'sort_order'          => 510,
                'content'             => <<<'HTML'
<p>Under evenemangets detaljsida hittar du en fullständig lista med registreringar och deras betalningsstatus.</p>
<h4>Incheckning med QR-kod</h4>
<p>Vid incheckning på plats — använd <a href="/admin/scanna">Skanningsstationen</a>. Deltagaren visar sin QR-kod och du skannar den med kameran. Systemet visar direkt om incheckning lyckas eller om det finns problem (ej betalt, fel event m.m.).</p>
<h4>Settlement</h4>
<p>Efter eventemanget kan du köra settlement för att stämma av betalningar, skapa bokföringsunderlag och markera eventet som avslutat.</p>
<h4>Exportera deltagarlistor</h4>
<p>Deltagarlistor kan exporteras som CSV eller skrivas ut direkt från evenemangets admin-sida.</p>
HTML,
            ],

            // ── ADMIN — Kommunikation ─────────────────────────────────────────────

            [
                'slug'                => 'admin-utskick',
                'title'               => 'Utskick (mailings)',
                'group_label'         => 'Administratör — Kommunikation',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'mailings.manage',
                'sort_order'          => 600,
                'content'             => <<<'HTML'
<p>Under <a href="/admin/utskick">Admin → Utskick</a> skapar du e-postutskick till valda mottagargrupper.</p>
<h4>Skapa ett utskick</h4>
<ol>
  <li>Gå till <a href="/admin/utskick/skapa">Nytt utskick</a>.</li>
  <li>Välj en befintlig <strong>mall</strong> eller skriv innehållet direkt. Mallar finns under <a href="/admin/utskick/mallar">Mailmallar</a>.</li>
  <li>Välj mottagare: alla aktiva medlemmar, en specifik sektion, deltagare på ett evenemang, eller en anpassad grupp.</li>
  <li>Schemalägg en tidpunkt eller spara som utkast.</li>
</ol>
<h4>Sändning</h4>
<p>Utskick skickas via schemalagd cron-körning (<code>artisan mailings:process</code>, var 5:e minut). Ingen daemon-process krävs. Var god och schemalägg inte utskick för att gå ut omedelbart — systemet köar dem i nästa körning.</p>
<h4>Avprenumeration</h4>
<p>Varje utskick innehåller automatiskt en avprenumerationslänk. Avregistrerade mottagare hoppas över vid framtida utskick till samma mottagargrupp.</p>
HTML,
            ],

            [
                'slug'                => 'admin-enkater',
                'title'               => 'Enkäter',
                'group_label'         => 'Administratör — Kommunikation',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'surveys.manage',
                'sort_order'          => 610,
                'content'             => <<<'HTML'
<p>Under <a href="/admin/enkater">Admin → Enkäter</a> skapar du enkäter och hanterar svar.</p>
<h4>Skapa en enkät</h4>
<ol>
  <li>Gå till <a href="/admin/enkater/skapa">Ny enkät</a>.</li>
  <li>Lägg till frågor av önskad typ (text, radio, kryssrutor, skala, datum m.m.).</li>
  <li>Konfigurera anonymitetsinställning.</li>
  <li>Bjud in respondenter via enkätens <strong>Inbjudningar</strong>-flik — välj mottagare och systemet skickar unika svarslänkar.</li>
</ol>
<h4>Frågetyper</h4>
<ul>
  <li>Kort svar / Långt svar</li>
  <li>Enkelsvar (radio) / Flersvar (checkboxar)</li>
  <li>Dropdown</li>
  <li>Skala/betyg (stjärnor, hjärtan, siffror)</li>
  <li>Datum &amp; tid, Siffra, E-post</li>
  <li>Rubrik/avdelare (strukturell)</li>
</ul>
<h4>Svarsöversikt</h4>
<p>Under enkätens <a href="/admin/enkater">Svar</a>-flik visas aggregerade svar med diagram per fråga. Exporter kan laddas ner som CSV.</p>
HTML,
            ],

            [
                'slug'                => 'admin-formulärbyggaren',
                'title'               => 'Formulärbyggaren',
                'group_label'         => 'Administratör — Kommunikation',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'forms.manage',
                'sort_order'          => 620,
                'content'             => <<<'HTML'
<p>Under <a href="/admin/formulär">Admin → Formulär</a> skapar du handlingsdrivande formulär — anmälningsblanketter som automatiskt utlöser åtgärder i systemet när de skickas in.</p>
<h4>Skillnad mot enkäter</h4>
<p>Enkäter samlar <em>åsikter och svar</em>. Formulär samlar <em>intresseanmälningar och beställningar</em> och kan automatiskt skapa registreringar, varukorgsposter, sektionsansökningar m.m.</p>
<h4>Alla 13 fälttyper</h4>
<ul>
  <li><strong>Kort svar, Långt svar, E-post, Siffra</strong> — klassiska inmatningsfält.</li>
  <li><strong>Enkelsvar (radio), Flersvar (kryssrutor), Dropdown</strong> — valbara alternativ.</li>
  <li><strong>Skala/betyg, Datum &amp; tid</strong> — specialiserade inmatningar.</li>
  <li><strong>Rubrik/avdelare</strong> — strukturell, samlar inget svar.</li>
  <li><strong>Produkt (förbeställning)</strong> — ja/nej + variant → läggs i varukorgen.</li>
  <li><strong>Välj event</strong> — välj event → skapar eventregistrering automatiskt.</li>
  <li><strong>Välj sektioner</strong> — välj en eller flera sektioner → skapar sektionsansökan (pending).</li>
  <li><strong>Bli medlem</strong> — ja/nej → läggs i varukorgen om ja.</li>
  <li><strong>Intresselista för uthyrning</strong> — ja/nej → lägger dig på uthyrningsväntelista.</li>
</ul>
<h4>Svarsöversikt</h4>
<p>Under formulärets Svar-flik ser du alla inlämningar med aggregerade stapeldiagram för valbara fält och textlistor för fritextfält.</p>
HTML,
            ],

            // ── ADMIN — System ───────────────────────────────────────────────────

            [
                'slug'                => 'admin-roller-behorigheter',
                'title'               => 'Roller och behörigheter',
                'group_label'         => 'Administratör — System',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'users.manage-roles',
                'sort_order'          => 700,
                'content'             => <<<'HTML'
<p>Under <a href="/admin/roller">Admin → Roller</a> hanteras vilka användare som har tillgång till vilka delar av systemet.</p>
<h4>Tre lager av behörigheter</h4>
<ol>
  <li><strong>Spatie-rättigheter</strong> — granulära rättigheter som <code>members.manage</code>, <code>events.manage</code> m.fl. Tilldelas per användare eller via en roll.</li>
  <li><strong>Org-roller</strong> — synkas automatiskt (nattlig cron). Styrelsemedlemmar → <code>styrelse</code>-rollen, revisorer → <code>revisor</code>-rollen.</li>
  <li><strong>Sektionsroller</strong> — lokala roller inom en sektion (t.ex. sektionsordförande).</li>
</ol>
<h4>Tilldela rättigheter</h4>
<p>Klicka på en användare under <a href="/admin/members">Medlemmar</a> och välj sedan fliken <strong>Rättigheter</strong>. Bocka av de rättigheter du vill ge. Ändringar träder i kraft direkt.</p>
<h4>Rollgrupper</h4>
<p>I rollvyn ser du alla rättigheter grupperade (Innehåll, Evenemang, Ekonomi, Kommunikation, System m.m.). Rättigheter som beviljas via org-roller visas som read-only.</p>
HTML,
            ],

            [
                'slug'                => 'admin-cms-navigation',
                'title'               => 'CMS och navigation',
                'group_label'         => 'Administratör — System',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'content.manage',
                'sort_order'          => 710,
                'content'             => <<<'HTML'
<p>Under <a href="/admin/pages">Admin → Sidor</a> och <a href="/admin/navigation">Navigation</a> hanteras föreningens statiska webbsidor och menyn.</p>
<h4>Sidor</h4>
<p>Sidor har ett utkast-workflow: skapa utkast → redigera → publicera. Publicerade sidor är synliga för besökare. Sluggen bestämmer URL:en (t.ex. <code>om-oss</code> → <code>/om-oss</code>). Vissa sluggar är skyddade (<code>hem</code>, <code>om-oss</code>, <code>kontakt</code>) och kan inte tas bort.</p>
<h4>Navigation</h4>
<p>Navigationsträdet (topp-nav och footer) redigeras som ett drag-and-drop-träd. Varje post kan länka till en intern sida, en extern URL eller vara en rubrik-grupp.</p>
<h4>Hjälptexter</h4>
<p>Under <a href="/admin/help-texts">Hjälptexter</a> redigerar du de korta in-app-texter som visas bredvid fält och formulär i systemet. Hjälptexterna är nyckel-värdepar (<code>help_text('nyckel')</code>) och kan uppdateras utan koddriftsättning.</p>
HTML,
            ],

            [
                'slug'                => 'admin-skanningsstation',
                'title'               => 'Skanningsstation och RFID',
                'group_label'         => 'Administratör — System',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'scanner.manage',
                'sort_order'          => 720,
                'content'             => <<<'HTML'
<p>Under <a href="/admin/scanna">Admin → Skanna</a> hanteras föreningens skanningsstation — för incheckning på evenemang och vid porten.</p>
<h4>Kiosk-läge</h4>
<p>Aktivera kiosk-läget på en dedikerad surfplatta eller dator vid entrén via <a href="/admin/scanna/kiosk">Kiosk</a>. Kiosken visar en minimalistisk vy och loggar in besökare via RFID-kort utan att exponera admin-gränssnittet.</p>
<h4>RFID-taggar</h4>
<p>Under <a href="/admin/rfid-taggar">RFID-taggar</a> hanteras alla registrerade RFID-kort. Koppla ett kort till ett konto via kortnumret på kortet.</p>
<h4>Paxton-synkronisering</h4>
<p>Föreningens passersystem (Paxton) synkas automatiskt mot aktiva RFID-taggar via en lokal Windows-agent. Inaktiverade kort spärras automatiskt inom nästa synkning.</p>
HTML,
            ],

            [
                'slug'                => 'admin-workspace',
                'title'               => 'Google Workspace — administration',
                'group_label'         => 'Administratör — System',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'workspace.manage',
                'sort_order'          => 730,
                'content'             => <<<'HTML'
<p>Under <a href="/admin/workspace">Admin → Workspace</a> hanterar du föreningens Google Workspace-konton och delad Drive-lagring.</p>
<h4>Provisioning</h4>
<p>Skapa ett nytt <code>@armagedon.se</code>-konto för en förtroendevald direkt från Workspace-vyn. Ett välkomstmejl med inloggningsuppgifter skickas automatiskt till kontots e-postadress.</p>
<h4>Lösenordsåterställning</h4>
<p>Om ett konto är utelåst kan du generera ett nytt tillfälligt lösenord direkt i Workspace-vyn och skicka det till kontots backup-adress.</p>
<h4>Delegerade postlådor</h4>
<p>Under <a href="/admin/workspace/inkorgar">Inkorgar</a> hanteras de gemensamma funktionskontona (t.ex. <code>kansli@armagedon.se</code>). Tilldela delegerad åtkomst till specifika användare.</p>
<h4>Drive</h4>
<p>Under <a href="/admin/workspace/drive">Drive</a> hanteras delade mappar per sektion och org-roll. Åtkomst synkas automatiskt när sektionsmedlemskap och org-rollperioder ändras.</p>
HTML,
            ],

            [
                'slug'                => 'admin-imap-import',
                'title'               => 'IMAP-import och fakturainkorg',
                'group_label'         => 'Administratör — System',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'accounting.manage',
                'sort_order'          => 740,
                'content'             => <<<'HTML'
<p>Systemet kan automatiskt hämta e-post från ett IMAP-konto och lägga in dem som ärenden (t.ex. inkommande fakturor eller kontaktmeddelanden).</p>
<h4>IMAP-import för self-service</h4>
<p>Under <a href="/installningar/importera-mail">Inställningar → Importera mail</a> kan du konfigurera ditt eget IMAP-konto för att importera e-post till ditt ärendeflöde. Importen körs av cron (<code>artisan mail:import-imap</code>) var 5:e minut.</p>
<h4>Fakturainkorg</h4>
<p>Under <a href="/admin/bokforing/fakturainkorg">Bokföring → Fakturainkorg</a> syns alla inkommande e-postmeddelanden skickade till föreningens faktura-adress (t.ex. <code>faktura@armagedon.se</code>). Bifogade PDF:er kan direkt kopplas till en leverantörsfaktura i systemet.</p>
<h4>Kansliinkorg</h4>
<p>Under <a href="/admin/kansliinkorg">Kansliinkorg</a> samlas meddelanden skickade till föreningens kansliadress. Hantera och svara direkt i systemet.</p>
HTML,
            ],

            [
                'slug'                => 'admin-lokalbokningar',
                'title'               => 'Lokalbokningar — administration',
                'group_label'         => 'Administratör — System',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'bookings.manage',
                'sort_order'          => 750,
                'content'             => <<<'HTML'
<p>Under <a href="/admin/bokningar">Admin → Bokningar</a> ser du alla lokalbokningar och hanterar dessa.</p>
<h4>Godkänna och neka</h4>
<p>Lokaler som konfigurerats med krav på godkännande visas i filtret <strong>Väntar</strong>. Godkänn eller neka en bokning med ett klick. Sökanden meddelas via e-post.</p>
<h4>Återkommande bokningar</h4>
<p>Under <a href="/admin/upprepande-bokningar">Återkommande bokningar</a> skapar du seriebokningar — t.ex. varje måndag kl. 18–22 under en hel termin. Systemet skapar automatiskt enskilda bokningar från mallen en gång i månaden.</p>
<h4>Lokalansökningar</h4>
<p>Externa aktörer (t.ex. föreningar eller privatpersoner) som vill låna lokalen för ett större event kan skicka in en <a href="/admin/lokalansokningar">Lokalansökan</a>. Vid godkännande skapas bekräftade bokningar automatiskt.</p>
<h4>Rum och kategorier</h4>
<p>Konfigurera bokningsbara rum under <a href="/admin/rum">Rum</a>: kapacitet, min/max bokningstid, godkännanderegel och aktiv/inaktiv-status.</p>
HTML,
            ],

            [
                'slug'                => 'admin-uthyrning',
                'title'               => 'Uthyrning — administration',
                'group_label'         => 'Administratör — System',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'rentals.manage',
                'sort_order'          => 760,
                'content'             => <<<'HTML'
<p>Under <a href="/admin/hyror">Admin → Uthyrning</a> hanteras föreningens alla hyresavtal.</p>
<h4>Aktiva hyror</h4>
<p>Listan visar alla aktiva hyresavtal med hyrestagare, objekt, startdatum och nästa fakturadatum.</p>
<h4>Skapa ett nytt hyresavtal</h4>
<p>Klicka på <strong>Ny hyra</strong> och välj:</p>
<ul>
  <li>Hyrestagare (måste vara aktiv medlem)</li>
  <li>Utrustningstyp och specifikt objekt</li>
  <li>Startdatum och prismodell</li>
</ul>
<h4>Fakturering</h4>
<p>Under <a href="/admin/hyror/fakturor">Hyresfakturor</a> skapas och hanteras årsvis fakturering för hyresavtalen. Fakturorna integreras med bokföringen automatiskt.</p>
<h4>Intresselista</h4>
<p>När alla objekt av en typ är uthyrda kan medlemmar anmäla intresse. Intresselistan visas per utrustningstyp under objektets detaljsida.</p>
HTML,
            ],

            [
                'slug'                => 'admin-utlagg',
                'title'               => 'Utlägg — administration',
                'group_label'         => 'Administratör — System',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'expense-claims.manage',
                'sort_order'          => 770,
                'content'             => <<<'HTML'
<p>Under <a href="/admin/arenden">Admin → Ärenden</a> granskas och handläggs inkomna utläggsärenden.</p>
<h4>Granska ett ärende</h4>
<p>Klicka på ett ärende för att se alla detaljer: belopp, datum, beskrivning och uppladdat kvitto. Kvittot kan visas direkt i webbläsaren eller laddas ner.</p>
<h4>Godkänn eller avvisa</h4>
<ul>
  <li><strong>Godkänn</strong> — utlägget markeras som godkänt och körs för utbetalning i nästa utbetalningskörning via bankgiro.</li>
  <li><strong>Avvisa</strong> — ange en motivering som skickas till sökanden via e-post.</li>
</ul>
<h4>Bokföringsintegration</h4>
<p>Godkända utlägg skapar automatiskt bokföringsposter i systemet. Kontrollera att kontomappningen för utlägg är korrekt inställd under Bokföringsregler.</p>
HTML,
            ],

            [
                'slug'                => 'admin-statistik-handelser',
                'title'               => 'Statistik och händelselogg',
                'group_label'         => 'Administratör — System',
                'show_public'         => false,
                'show_auth'           => false,
                'required_permission' => 'users.manage-roles',
                'sort_order'          => 780,
                'content'             => <<<'HTML'
<p>Under <a href="/admin/statistik/medlemmar">Statistik</a> hittar du tre dashboards med nyckeltal för föreningen.</p>
<h4>Dashboards</h4>
<ul>
  <li><strong>Medlemmar</strong> (<a href="/admin/statistik/medlemmar">/admin/statistik/medlemmar</a>) — aktiva/utgångna/nya per månad, sektionsfördelning.</li>
  <li><strong>Evenemang</strong> (<a href="/admin/statistik/evenemang">/admin/statistik/evenemang</a>) — deltagarantal, populäraste event, intäkter per event.</li>
  <li><strong>Intäkter</strong> (<a href="/admin/statistik/intakter">/admin/statistik/intakter</a>) — totala intäkter per kategori och månad.</li>
</ul>
<h4>Händelselogg (Audit log)</h4>
<p>Under <a href="/admin/handelser">Admin → Händelser</a> loggas alla viktiga åtgärder i systemet: skapade, redigerade och borttagna poster, statusändringar, betalningshändelser m.m.</p>
<p>Varje loggpost visar vem som utförde åtgärden, vid vilken tidpunkt och vad som ändrades. Loggen är skrivskyddad och kan inte redigeras eller raderas.</p>
HTML,
            ],

        ];

        foreach ($sections as &$s) {
            if (DB::table('help_sections')->where('slug', $s['slug'])->exists()) {
                continue;
            }
            DB::table('help_sections')->insert(array_merge($s, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        $slugs = [
            'vad-ar-membrum', 'hitta-ratt', 'logga-in', 'bli-medlem',
            'evenemang-anmalan', 'evenemang-betalning', 'mina-anmalningar',
            'shop-handla', 'shop-kassa',
            'uthyrning-hyra', 'uthyrning-intresselista',
            'lokalbokning-boka',
            'enkater', 'formulär-fylla-i',
            'utlagg',
            'admin-medlemmar', 'admin-familjegrupper', 'admin-sektioner', 'admin-org-roller',
            'admin-medlemsavgifter', 'admin-shop-ordrar', 'admin-rabatter', 'admin-bokforing',
            'admin-evenemang', 'admin-incheckning',
            'admin-utskick', 'admin-enkater', 'admin-formulärbyggaren',
            'admin-roller-behorigheter', 'admin-cms-navigation', 'admin-skanningsstation',
            'admin-workspace', 'admin-imap-import',
            'admin-lokalbokningar', 'admin-uthyrning', 'admin-utlagg', 'admin-statistik-handelser',
        ];

        DB::table('help_sections')->whereIn('slug', $slugs)->delete();
    }
};
