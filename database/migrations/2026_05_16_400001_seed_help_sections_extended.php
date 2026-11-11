<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $sections = [

            // ════════════════════════════════════════════════════════════════════
            // PUBLIC
            // ════════════════════════════════════════════════════════════════════

            [
                'slug' => 'systemkrav',
                'title' => 'Systemkrav och webbläsare',
                'group_label' => null,
                'show_public' => true, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 25,
                'content' => <<<'HTML'
<p>Membrum fungerar i alla moderna webbläsare: Chrome, Firefox, Edge och Safari (inklusive iOS). Internet Explorer stöds inte.</p>
<h4>Mobil</h4>
<p>Systemet är mobilanpassat. Menyn öppnas via hamburgaren uppe till vänster. QR-koden för evenemangsbiljetter visas bra i mobilläge.</p>
<h4>Cookies</h4>
<p>Vi använder sessionscookies för inloggning. Inga spårnings- eller reklamcookies används.</p>
HTML,
            ],

            [
                'slug' => 'dataskydd-gdpr',
                'title' => 'Dataskydd och GDPR',
                'group_label' => null,
                'show_public' => true, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 27,
                'content' => <<<'HTML'
<p>Föreningen hanterar personuppgifter i enlighet med GDPR. Uppgifter som lagras är de som är nödvändiga för föreningsverksamheten: namn, e-post, betalningshistorik och aktivitetslogg.</p>
<h4>Dina rättigheter</h4>
<ul>
  <li><strong>Rätt till insyn</strong> — ladda ner all data om dig under <a href="/mina-uppgifter/export">Exportera mina uppgifter</a>.</li>
  <li><strong>Rätt att bli raderad</strong> — skicka en raderingsförfrågan via kontaktsidan. Ekonomiska uppgifter sparas enligt bokföringslagen (7 år) och kan inte raderas.</li>
  <li><strong>Rätt till rättelse</strong> — kontakta administratören om du vill rätta felaktiga uppgifter.</li>
</ul>
<h4>Kontakt</h4>
<p>Frågor om personuppgifter skickas till <a href="/kontakt">föreningens kontaktadress</a>.</p>
HTML,
            ],

            [
                'slug' => 'kontakta-foreningen',
                'title' => 'Kontakta föreningen',
                'group_label' => null,
                'show_public' => true, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 28,
                'content' => <<<'HTML'
<p>Det snabbaste sättet att nå föreningen är via kontaktformuläret på <a href="/kontakt">Kontaktsidan</a>.</p>
<h4>Vem kontaktar jag om...?</h4>
<ul>
  <li><strong>Tekniska problem i systemet</strong> — kontaktformuläret, märk ärenden med "Tekniskt".</li>
  <li><strong>Betalning eller faktura</strong> — ekonomiansvarig via kontaktformuläret.</li>
  <li><strong>Medlemskap eller konto</strong> — sekreteraren via kontaktformuläret.</li>
  <li><strong>Evenemang</strong> — evenemangsansvarig eller evenemangets kontaktperson.</li>
</ul>
HTML,
            ],

            // ════════════════════════════════════════════════════════════════════
            // KONTO & INLOGGNING
            // ════════════════════════════════════════════════════════════════════

            [
                'slug' => 'kan-inte-logga-in',
                'title' => 'Jag kan inte logga in',
                'group_label' => 'Konto & inloggning',
                'show_public' => true, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 53,
                'content' => <<<'HTML'
<p>De vanligaste orsakerna till inloggningsproblem:</p>
<ol>
  <li><strong>Fel Google-konto</strong> — väljer du ett konto utan koppling till ett aktivt medlemskap visas en felmeddelandesida. Prova ett annat Google-konto, eller kontakta administratören för att kontrollera vilket konto som är registrerat.</li>
  <li><strong>Konto avslutat</strong> — om ditt konto är inaktiverat kan du logga in men ser ett meddelande. Kontakta styrelsen.</li>
  <li><strong>Webbläsarproblem</strong> — rensa webbläsarens cookies och cache och försök igen. Prova i ett privat fönster (Inkognito/InPrivate).</li>
  <li><strong>Popup-blockerare</strong> — Google-inloggningen öppnar ett popup-fönster. Se till att webbläsarens popup-blockerare inte hindrar detta.</li>
</ol>
<h4>Fortfarande problem?</h4>
<p>Kontakta administratören via <a href="/kontakt">kontaktformuläret</a> med ditt namn och vilket Google-konto du försökte använda.</p>
HTML,
            ],

            [
                'slug' => 'lankade-google-konton',
                'title' => 'Länka flera Google-konton',
                'group_label' => 'Konto & inloggning',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 54,
                'content' => <<<'HTML'
<p>Du kan länka ditt privata Gmail och ditt <code>@armagedon.se</code>-konto till samma profil i Membrum — du loggar sedan in med vilket som helst av dem.</p>
<h4>Länka ett konto</h4>
<ol>
  <li>Logga in med ditt primära Google-konto.</li>
  <li>Gå till <a href="/profile">Mina inställningar</a>.</li>
  <li>Under "Länkade konton" — klicka <strong>Länka Google-konto</strong> och välj kontot du vill lägga till.</li>
</ol>
<h4>Ta bort en länkning</h4>
<p>Under "Länkade konton" — klicka på papperskorgen bredvid kontot du vill ta bort. Det primära kontot kan inte tas bort utan att ett annat finns kvar.</p>
HTML,
            ],

            [
                'slug' => 'min-profil',
                'title' => 'Ditt konto och profil',
                'group_label' => 'Konto & inloggning',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 55,
                'content' => <<<'HTML'
<p>Under <a href="/profile">Mina inställningar</a> hanteras ditt konto.</p>
<h4>Vad kan du ändra?</h4>
<ul>
  <li><strong>Visningsnamn</strong> — det namn som visas i systemet.</li>
  <li><strong>Kontonummer</strong> — bankkontonummer för utbetalning av godkända utlägg.</li>
  <li><strong>Temainställning</strong> — ljust, mörkt eller systemets inställning.</li>
  <li><strong>Länkade Google-konton</strong> — lägg till eller ta bort inloggningsalternativ.</li>
</ul>
<h4>Vad du inte kan ändra själv</h4>
<p>E-postadress, telefonnummer och andra känsliga uppgifter i din memberpost ändras av administratören. Kontakta styrelsen om något behöver uppdateras.</p>
HTML,
            ],

            [
                'slug' => 'radera-konto-gdpr',
                'title' => 'Radera mitt konto (GDPR)',
                'group_label' => 'Konto & inloggning',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 56,
                'content' => <<<'HTML'
<p>Du har rätt att begära radering av dina personuppgifter. Skicka en begäran via <a href="/kontakt">kontaktformuläret</a>.</p>
<h4>Vad raderas och vad bevaras?</h4>
<ul>
  <li><strong>Raderas</strong> — namn, e-post, inloggningsuppgifter, aktivitetslogg och ej ekonomirelaterade uppgifter.</li>
  <li><strong>Bevaras 7 år (bokföringslagen)</strong> — transaktioner, fakturor, utlägg och betalningshistorik. Dessa avidentifieras i möjligaste mån.</li>
</ul>
<h4>Tid</h4>
<p>Raderingsärenden handläggs inom 30 dagar. Du kan följa ditt ärende under <a href="/arenden">Mina ärenden</a>.</p>
HTML,
            ],

            [
                'slug' => 'vad-ingår-i-medlemskap',
                'title' => 'Vad ingår i ett aktivt medlemskap?',
                'group_label' => 'Konto & inloggning',
                'show_public' => true, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 57,
                'content' => <<<'HTML'
<p>Ett aktivt medlemskap ger tillgång till:</p>
<ul>
  <li>Deltagarrabatt på evenemang (pristyper märkta "Medlem")</li>
  <li>Medlemspris i webshopen</li>
  <li>Möjlighet att boka föreningens lokaler</li>
  <li>Möjlighet att hyra föreningens utrustning</li>
  <li>Rösträtt på föreningens möten</li>
  <li>Tillgång till sektioner och aktivitetsgrupper</li>
</ul>
<h4>Gäller även</h4>
<p>Familjemedlemmar (barn under 18) som lagts till av familjegruppens administratör räknas som aktiva utan att själva betala separat avgift (om familjemedlemskap är tecknat).</p>
HTML,
            ],

            [
                'slug' => 'aktiveringstid',
                'title' => 'Hur lång tid tar det att bli aktiverad?',
                'group_label' => 'Konto & inloggning',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 58,
                'content' => <<<'HTML'
<p>Aktiveringstiden beror på betalsätt:</p>
<ul>
  <li><strong>Kortbetalning (Stripe)</strong> — omedelbart. Logga ut och in igen om statusen inte uppdateras direkt.</li>
  <li><strong>Swish</strong> — normalt inom 1–2 minuter.</li>
  <li><strong>Bankgiro</strong> — 1–3 bankdagar, beroende på när betalningen behandlas av bankerna. Betalningsdagen är inte alltid samma som mottagningsdagen.</li>
</ul>
<p>Om din betalning gick igenom men kontot fortfarande inte är aktiverat — kontakta ekonomiansvarig med transaktionsnumret.</p>
HTML,
            ],

            [
                'slug' => 'rfid-kort',
                'title' => 'RFID-kort — koppla och använda',
                'group_label' => 'Konto & inloggning',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 59,
                'content' => <<<'HTML'
<p>Med ett RFID-kort kan du logga in på kiosken vid entrén utan att använda Google-inloggningen.</p>
<h4>Skaffa ett kort</h4>
<p>Kontakta en administratör för att få ett RFID-kort och koppla det till ditt konto. Ditt kortnummer är det fysiska numret tryckt på kortets baksida.</p>
<h4>Använda kortet</h4>
<p>Håll kortet nära kortläsaren på kiosken. Systemet identifierar dig direkt och loggar in dig i kiosk-läget. Kortet används också automatiskt för Paxton-passersystemet om föreningen använder det.</p>
<h4>Tappat kortet</h4>
<p>Kontakta administratören omedelbart. Kortet spärras vid nästa synkronisering mot passersystemet (normalt inom ett dygn).</p>
HTML,
            ],

            [
                'slug' => 'temaval',
                'title' => 'Mörkt och ljust tema',
                'group_label' => 'Konto & inloggning',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 60,
                'content' => <<<'HTML'
<p>Du väljer tema under <a href="/profile">Mina inställningar</a> — tre alternativ:</p>
<ul>
  <li><strong>Ljust</strong> — alltid ljust läge.</li>
  <li><strong>Mörkt</strong> — alltid mörkt läge.</li>
  <li><strong>System</strong> — följer operativsystemets inställning (dag/natt-läge).</li>
</ul>
<p>Inställningen sparas på ditt konto och gäller på alla enheter du loggar in på.</p>
HTML,
            ],

            // ════════════════════════════════════════════════════════════════════
            // EVENEMANG — specifika frågor
            // ════════════════════════════════════════════════════════════════════

            [
                'slug' => 'evenemang-kalender',
                'title' => 'Evenemangkalender och filter',
                'group_label' => 'Evenemang',
                'show_public' => true, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 108,
                'content' => <<<'HTML'
<p>Under <a href="/evenemang/kalender">Evenemang → Kalender</a> visas alla kommande evenemang i månads- eller veckovy. Under <a href="/evenemang">Evenemangslistan</a> visas dem som kort med beskrivning.</p>
<h4>Filtrera</h4>
<p>Filtrera på kategori, datum och söktext i listan. Gratis- och betalaevenemang visas med tydlig prisinformation på varje kort.</p>
HTML,
            ],

            [
                'slug' => 'evenemang-pristyper',
                'title' => 'Biljetttyper och prisnivåer',
                'group_label' => 'Evenemang',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 109,
                'content' => <<<'HTML'
<p>Evenemang kan ha flera prisnivåer — t.ex. "Vuxen", "Under 18" eller "Förtroendevald gratis". Välj den biljettyp som stämmer för dig.</p>
<h4>Obligatorisk prisnivå</h4>
<p>Vissa evenemang har bara en prisnivå och du kan inte välja. Vid gratisevenemang sker anmälan utan betalning.</p>
<h4>Fria platser</h4>
<p>Om en prisnivå visar "0 kr" eller "Gratis" bekräftas anmälan direkt utan kassaflöde.</p>
HTML,
            ],

            [
                'slug' => 'evenemang-tillagg',
                'title' => 'Tillägg (add-ons)',
                'group_label' => 'Evenemang',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 115,
                'content' => <<<'HTML'
<p>Många evenemang erbjuder valfria tillägg — t.ex. middag, transport eller merchandise. Tilläggen visas som kryssrutor i anmälningsformuläret.</p>
<h4>Pris</h4>
<p>Tillägg kan vara gratis eller debiteras. Totalpriset summeras i kassan tillsammans med biljetttypen.</p>
<h4>Ångra ett tillägg</h4>
<p>Kontakta evenemangsansvarig om du vill ändra dina tillval efter anmälan. Det görs inte via systemet utan via e-post eller kontaktformuläret.</p>
HTML,
            ],

            [
                'slug' => 'evenemang-vantelista',
                'title' => 'Väntelista — hur fungerar den?',
                'group_label' => 'Evenemang',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 116,
                'content' => <<<'HTML'
<p>Om ett evenemang är fullt erbjuds du väntelistan. Du placeras i kön i ordning efter anmälningstid.</p>
<h4>Notifiering</h4>
<p>Om en plats öppnas — t.ex. för att en annan deltagare avbokar — skickas ett e-postmeddelande till nästa person i kön. Du har normalt 24–48 timmar på dig att bekräfta och betala innan platsen erbjuds vidare.</p>
<h4>Ta bort sig från väntelistan</h4>
<p>Under <a href="/mina-anmalningar">Mina anmälningar</a> ser du dina väntelisteplatser och kan ta bort dig.</p>
HTML,
            ],

            [
                'slug' => 'evenemang-forhandskrav',
                'title' => 'Förhandskrav för evenemang',
                'group_label' => 'Evenemang',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 117,
                'content' => <<<'HTML'
<p>Vissa evenemang kräver att du uppfyller ett villkor för att kunna anmäla dig:</p>
<ul>
  <li><strong>Aktivt medlemskap</strong> — du måste ha betalt årsavgiften innevarande år.</li>
  <li><strong>Sektionsmedlemskap</strong> — du måste vara aktiv i en specifik sektion.</li>
  <li><strong>Ålderskrav</strong> — anges i evenemangets beskrivning.</li>
</ul>
<p>Om du inte uppfyller kravet är anmälningsknappen inaktiverad med en förklaring. Kontakta en administratör om du anser att det är ett misstag.</p>
HTML,
            ],

            [
                'slug' => 'evenemang-avboka',
                'title' => 'Avboka en anmälan',
                'group_label' => 'Evenemang',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 125,
                'content' => <<<'HTML'
<p>Gå till <a href="/mina-anmalningar">Mina anmälningar</a> och klicka <strong>Avboka</strong> på den aktuella raden.</p>
<h4>Avbokningspolicy</h4>
<p>Möjligheten att avboka stängs vid den tidpunkt evenemangsadministratören har satt. Passerat avbokningsfönstret måste du kontakta evenemangsansvarig direkt.</p>
<h4>Återbetalning</h4>
<p>Återbetalning sker inte automatiskt. Kontakta ekonomiansvarig med din orderinformation om du har betalat och avbokar inom rätt fönster. Evenemangspolicyn varierar.</p>
HTML,
            ],

            [
                'slug' => 'evenemang-inställt',
                'title' => 'Vad gäller vid inställt evenemang?',
                'group_label' => 'Evenemang',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 126,
                'content' => <<<'HTML'
<p>Om ett evenemang ställs in kontaktar evenemangsansvarig alla anmälda via e-post.</p>
<h4>Återbetalning</h4>
<p>Vid inställt evenemang återbetalas betalda biljetter. Kontakta ekonomiansvarig med din betalningsinformation om återbetalningen inte sker automatiskt inom en vecka.</p>
HTML,
            ],

            [
                'slug' => 'evenemang-öppet-arrangemang',
                'title' => 'Öppna arrangemang — vad betyder det?',
                'group_label' => 'Evenemang',
                'show_public' => true, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 127,
                'content' => <<<'HTML'
<p>Evenemang märkta "Öppet arrangemang" innebär att fler är välkomna att delta utöver de som redan anmält sig. I evenemangets beskrivning anges vilka som är välkomna och eventuella villkor.</p>
<p>Öppna arrangemang är också synliga i lokalbokningskalendern med ett blått märke.</p>
HTML,
            ],

            [
                'slug' => 'evenemang-qr-biljett',
                'title' => 'Min QR-kod och biljett',
                'group_label' => 'Evenemang',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 128,
                'content' => <<<'HTML'
<p>När din anmälan är bekräftad finns din QR-kod under <a href="/mina-anmalningar">Mina anmälningar</a> — klicka på anmälan för att öppna biljetten.</p>
<h4>Hur den används</h4>
<p>Visa QR-koden för den som checkar in deltagare vid evenemanget. Koden skannas med föreningens kiosk eller admin-mobil.</p>
<h4>En kod per anmälan</h4>
<p>Varje anmälan har en unik kod kopplad till din person och evenemanget. Koden kan bara skannas för incheckning en gång.</p>
HTML,
            ],

            // ════════════════════════════════════════════════════════════════════
            // WEBSHOP — specifika frågor
            // ════════════════════════════════════════════════════════════════════

            [
                'slug' => 'shop-betalsätt',
                'title' => 'Betalsätt i webshopen',
                'group_label' => 'Webshop',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 143,
                'content' => <<<'HTML'
<ul>
  <li><strong>Kortbetalning (Stripe)</strong> — Visa, Mastercard, American Express. Betalas direkt och ordern bekräftas omedelbart.</li>
  <li><strong>Swish</strong> — scanna QR-koden i Swish-appen. Bekräftas inom sekunder.</li>
  <li><strong>Bankgiro</strong> — betala med OCR-referensen till föreningens bankgiro. Ordern aktiveras när betalningen mottas (1–3 bankdagar). Välj detta alternativ om du inte vill ange kortuppgifter online.</li>
</ul>
HTML,
            ],

            [
                'slug' => 'shop-orderbekraftelse',
                'title' => 'Orderbekräftelse och kvitto',
                'group_label' => 'Webshop',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 144,
                'content' => <<<'HTML'
<p>En orderbekräftelse skickas till din e-postadress direkt vid betald order. Under <a href="/mina-bestallningar">Mina beställningar</a> kan du ladda ner fakturan som PDF.</p>
<h4>Fick inget mejl?</h4>
<p>Kontrollera skräpposten. Mejlet kommer från föreningens avsändaradress. Kontakta ekonomiansvarig om du inte hittar det efter att du kontrollerat skräpposten.</p>
HTML,
            ],

            [
                'slug' => 'shop-rabattkod-fungerar-inte',
                'title' => 'Rabattkod fungerar inte — varför?',
                'group_label' => 'Webshop',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 145,
                'content' => <<<'HTML'
<p>Vanliga orsaker till att en rabattkod inte godkänns:</p>
<ul>
  <li><strong>Utgången</strong> — koden har ett slutdatum som passerat.</li>
  <li><strong>Redan använd</strong> — engångskoder kan bara användas en gång och är nu förbrukade.</li>
  <li><strong>Fel tillämpningsområde</strong> — koden gäller kanske bara för evenemang, inte butiken.</li>
  <li><strong>Minsta belopp ej uppnått</strong> — koden kräver ett minsta ordervärde.</li>
  <li><strong>Stavfel</strong> — koder är skiftlägeskänsliga; kontrollera noga.</li>
</ul>
<p>Kontakta administratören med kodnamnet om du tror att det är ett systemfel.</p>
HTML,
            ],

            [
                'slug' => 'shop-varukorg-raderas',
                'title' => 'Varukorgen raderades — varför?',
                'group_label' => 'Webshop',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 146,
                'content' => <<<'HTML'
<p>Varukorgar som inte betalats raderas automatiskt efter 24 timmar. Det är en säkerhetsåtgärd för att frigöra reserverade lagerposter.</p>
<p>Om din varukorg försvann — lägg till varorna igen och slutför köpet. Väljer du bankgiro behöver du betala innan 24 timmar löpt ut, annars startar en ny process.</p>
HTML,
            ],

            [
                'slug' => 'shop-retur',
                'title' => 'Retur och reklamation',
                'group_label' => 'Webshop',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 147,
                'content' => <<<'HTML'
<p>Returer och reklamationer hanteras manuellt. Kontakta ekonomiansvarig via <a href="/kontakt">kontaktformuläret</a> med din ordernummer och en beskrivning av ärendet.</p>
<h4>Vad gäller?</h4>
<p>Föreningens retur- och reklamationspolicy framgår av köpevillkoren som visas i kassan.</p>
HTML,
            ],

            [
                'slug' => 'shop-min-pris',
                'title' => 'Varför ser jag ett annat pris än en kompis?',
                'group_label' => 'Webshop',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 148,
                'content' => <<<'HTML'
<p>Många produkter har ett lägre <strong>medlemspris</strong> för aktiva medlemmar och ett högre <strong>öppet pris</strong> för övriga. Om du är inloggad som aktivt medlem visas ditt pris automatiskt.</p>
<p>Är du aktiv member men ser ändå det högre priset? Logga ut och in igen — statusen uppdateras vid inloggning.</p>
HTML,
            ],

            [
                'slug' => 'shop-utan-inloggning',
                'title' => 'Kan jag handla utan att vara inloggad?',
                'group_label' => 'Webshop',
                'show_public' => true, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 149,
                'content' => <<<'HTML'
<p>Nej — köp kräver att du är inloggad. Butiken och priserna kan ses utan inloggning, men för att lägga till i varukorgen och slutföra ett köp behöver du ett konto.</p>
<p>Om du inte är aktiv member betalas det öppna priset.</p>
HTML,
            ],

            [
                'slug' => 'shop-spåra-order',
                'title' => 'Spåra en beställning',
                'group_label' => 'Webshop',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 153,
                'content' => <<<'HTML'
<p>Under <a href="/mina-bestallningar">Mina beställningar</a> ser du status på alla ordrar.</p>
<h4>Orderstatus</h4>
<ul>
  <li><strong>Väntar på betalning</strong> — bankgiro-order som ännu inte bekräftats.</li>
  <li><strong>Betald</strong> — betalning mottagen och registrerad.</li>
  <li><strong>Behandlas</strong> — ordern plockas/förbereds.</li>
  <li><strong>Skickad/Klar för avhämtning</strong> — produkterna är på väg eller väntar på dig.</li>
  <li><strong>Avbruten</strong> — ordern är annullerad.</li>
</ul>
HTML,
            ],

            // ════════════════════════════════════════════════════════════════════
            // UTHYRNING — specifika frågor
            // ════════════════════════════════════════════════════════════════════

            [
                'slug' => 'uthyrning-vad-kan-jag-hyra',
                'title' => 'Vilken utrustning kan jag hyra?',
                'group_label' => 'Uthyrning',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 158,
                'content' => <<<'HTML'
<p>Tillgänglig utrustning varierar. Kontakta styrelsen eller en administratör för att se en aktuell lista. Typisk uthyrningsutrustning inkluderar förvaringsutrymmen, AV-utrustning och möbler för evenemang.</p>
<h4>Priser</h4>
<p>Uthyrningspriset beror på utrustningstyp och faktureras normalt årsvis. Prismodellen sätts av styrelsen och framgår av ditt hyresavtal.</p>
HTML,
            ],

            [
                'slug' => 'uthyrning-avsluta',
                'title' => 'Avsluta en hyra',
                'group_label' => 'Uthyrning',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 163,
                'content' => <<<'HTML'
<p>Kontakta en administratör för att avsluta ett aktivt hyresavtal. Du behöver lämna tillbaka utrustningen i samma skick som vid uthyrningen.</p>
<h4>Slutfaktura</h4>
<p>Om hyran avslutas mitt under ett faktureringsår sker normalt debitering proportionellt till antalet dagar. Detta framgår av ditt avtal.</p>
HTML,
            ],

            [
                'slug' => 'uthyrning-skada',
                'title' => 'Skada på hyrd utrustning',
                'group_label' => 'Uthyrning',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 164,
                'content' => <<<'HTML'
<p>Kontakta styrelsen omedelbart om hyrd utrustning skadas. Ta gärna foton som dokumentation.</p>
<p>Ersättningspolicyn framgår av hyresavtalet. I normalfallet debiteras reparation eller ersättningskostnad om skadan beror på oaktsamhet.</p>
HTML,
            ],

            // ════════════════════════════════════════════════════════════════════
            // LOKALBOKNINGAR — specifika frågor
            // ════════════════════════════════════════════════════════════════════

            [
                'slug' => 'lokalbokning-vilka-rum',
                'title' => 'Vilka rum kan jag boka?',
                'group_label' => 'Lokalbokningar',
                'show_public' => true, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 168,
                'content' => <<<'HTML'
<p>Under <a href="/rum">Rum</a> i menyn listas alla bokningsbara lokaler med information om plats, kapacitet och eventuella begränsningar. Klicka på ett rum för att se detaljer och boka direkt.</p>
<h4>Kräver godkännande</h4>
<p>Vissa lokaler kräver administratörsgodkännande. Det framgår på rumsidan. Din bokning skickas som en förfrågan och du meddelas via e-post när den behandlats.</p>
HTML,
            ],

            [
                'slug' => 'lokalbokning-öppet-arrangemang',
                'title' => 'Öppet arrangemang vs sluten grupp',
                'group_label' => 'Lokalbokningar',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 173,
                'content' => <<<'HTML'
<p>När du skapar en bokning väljer du bokningstyp:</p>
<ul>
  <li><strong>Sluten grupp</strong> — ditt sällskap använder lokalen, inga utomstående bjuds in.</li>
  <li><strong>Öppet arrangemang</strong> — du välkomnar fler deltagare. Fyll i en kort beskrivning om vad som gäller (krav, välkomna, antal platser). Informationen visas i kalenderpoppen för andra användare.</li>
</ul>
HTML,
            ],

            [
                'slug' => 'lokalbokning-gräns',
                'title' => 'Bokningsgräns — vad räknas?',
                'group_label' => 'Lokalbokningar',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 174,
                'content' => <<<'HTML'
<p>Systemet räknar dina <em>aktiva framtida</em> bokningar mot din personliga bokningsgräns. När en bokning passerat räknas den inte längre.</p>
<h4>Undantag</h4>
<p>Bokningar som administratören markerat som "undantagna från gräns" räknas inte — detta används t.ex. för föreningsarrangemang. Kontakta en admin om ditt sällskap behöver boka mer än gränsen tillåter.</p>
HTML,
            ],

            [
                'slug' => 'lokalbokning-redigera',
                'title' => 'Redigera eller avboka en bokning',
                'group_label' => 'Lokalbokningar',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 175,
                'content' => <<<'HTML'
<p>Under <a href="/mina-bokningar">Mina bokningar</a> — klicka på en bokning för att öppna den och välj <strong>Redigera</strong> eller <strong>Avboka</strong>.</p>
<h4>Vad kan du ändra?</h4>
<p>Du kan ändra titel, aktivitetstyp, bokningstyp, tider och anteckningar. Rum och datum ändras via avbokning och ny bokning (systemet kontrollerar tillgänglighet).</p>
<h4>Avbokning kan inte ångras</h4>
<p>En avbokad bokning frigörs direkt för andra att boka. Det finns ingen ångerfunktion.</p>
HTML,
            ],

            [
                'slug' => 'lokalbokning-ical',
                'title' => 'iCal-prenumeration — synka med kalenderappen',
                'group_label' => 'Lokalbokningar',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 176,
                'content' => <<<'HTML'
<p>Du kan prenumerera på dina bokningar i Google Kalender, Apple Kalender, Outlook m.fl.</p>
<h4>Dina bokningar</h4>
<p>Under <a href="/mina-bokningar">Mina bokningar</a> finns en iCal-länk. Kopiera länken och lägg till den som prenumerationskalender i din app.</p>
<h4>Per rum</h4>
<p>Varje rum har en publik iCal-feed. Länken hittar du på rumsidan under <a href="/rum">Rum</a>.</p>
<p class="text-sm">Kalenderapparna hämtar normalt uppdateringar var 15–60 minut — ändringar syns inte alltid omedelbart.</p>
HTML,
            ],

            [
                'slug' => 'lokalbokning-godkännande',
                'title' => 'Bokning kräver godkännande — vad gäller?',
                'group_label' => 'Lokalbokningar',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 177,
                'content' => <<<'HTML'
<p>Vissa lokaler kräver att en administratör godkänner bokningar. Din bokning registreras med status "Väntar" och syns i din kalender, men är inte bekräftad.</p>
<h4>Svarstid</h4>
<p>Administratören behandlar normalt förfrågningar inom 1–2 dagar. Du meddelas via e-post oavsett om det godkänns eller nekas.</p>
<h4>Nekad bokning</h4>
<p>Om din bokning nekas frigörs tidsblocket. Du kan boka ett annat tillfälle. Anledningen till nekandet anges i mejlet.</p>
HTML,
            ],

            [
                'slug' => 'lokalbokning-lokallan',
                'title' => 'Ansöka om lokallån',
                'group_label' => 'Lokalbokningar',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 178,
                'content' => <<<'HTML'
<p>Lokallån är till för evenemang som kräver ett formellare godkännande — t.ex. om alkohol serveras, om det är ett externt arrangemang eller om det rör sig om ett stort evenemang.</p>
<h4>Ansök</h4>
<p>Fyll i <a href="/ansok-om-lokal">Ansökan om lokallån</a> med rum, datum, arrangör och beskrivning. En administratör granskar och svarar via e-post. Vid godkännande skapas bokningarna automatiskt.</p>
HTML,
            ],

            // ════════════════════════════════════════════════════════════════════
            // ENKÄTER & FORMULÄR — specifika frågor
            // ════════════════════════════════════════════════════════════════════

            [
                'slug' => 'enkät-länk-utgått',
                'title' => 'Enkätlänken fungerar inte längre',
                'group_label' => 'Enkäter & formulär',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 182,
                'content' => <<<'HTML'
<p>Enkätlänkar är unika per person och kan ha ett utgångsdatum. Om länken inte längre fungerar kan enkäten ha stängts av administratören eller din personliga länk ha löpt ut.</p>
<p>Kontakta evenemangsansvarig eller den person som skickade enkäten för att få en ny länk.</p>
HTML,
            ],

            [
                'slug' => 'enkät-ändra-svar',
                'title' => 'Kan jag ändra mina enkätsvar?',
                'group_label' => 'Enkäter & formulär',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 183,
                'content' => <<<'HTML'
<p>Nej — när du skickat in ett enkätsvar kan du inte ändra det via systemet. Kontakta den person som skickat enkäten om du behöver korrigera ett svar.</p>
HTML,
            ],

            [
                'slug' => 'enkät-anonym',
                'title' => 'Anonym enkät — vad loggas?',
                'group_label' => 'Enkäter & formulär',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 184,
                'content' => <<<'HTML'
<p>Vid anonyma enkäter loggas inte ditt användarnamn eller din e-post i samband med svaret. Administratören ser bara aggregerade svar, inte vem som svarat vad.</p>
<p>Din IP-adress sparas aldrig heller vid anonyma svar.</p>
HTML,
            ],

            [
                'slug' => 'formulär-varför-inte-skicka',
                'title' => 'Varför kan jag inte skicka formuläret?',
                'group_label' => 'Enkäter & formulär',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 187,
                'content' => <<<'HTML'
<p>Om formuläret inte skickas in finns vanligtvis ett obligatoriskt fält som inte fyllts i. Röda felmeddelanden visas bredvid respektive fält.</p>
<h4>Vanliga problem</h4>
<ul>
  <li>Obligatorisk fråga saknar svar (markerad med *)</li>
  <li>E-postfält innehåller ogiltig e-postadress</li>
  <li>Nummerfält utanför tillåtet intervall</li>
  <li>Produktfält med obligatorisk ja/nej-fråga besvarad med inget</li>
</ul>
HTML,
            ],

            // ════════════════════════════════════════════════════════════════════
            // UTLÄGG — specifika frågor
            // ════════════════════════════════════════════════════════════════════

            [
                'slug' => 'utlagg-format',
                'title' => 'Vilka format stöds för kvitton?',
                'group_label' => 'Utlägg',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 192,
                'content' => <<<'HTML'
<p>Accepterade filformat för kvitton: <strong>JPG, PNG, PDF</strong>. Maximal filstorlek: 10 MB.</p>
<h4>Tips</h4>
<ul>
  <li>Ta en tydlig bild av kvittot med bra belysning så att belopp och datum syns tydligt.</li>
  <li>Digitala kvitton (e-postbekräftelser) kan sparas som PDF och laddas upp direkt.</li>
  <li>Spara originalkvittot tills ärendet är godkänt och utbetalning skett.</li>
</ul>
HTML,
            ],

            [
                'slug' => 'utlagg-handläggningstid',
                'title' => 'Hur lång tid tar handläggning?',
                'group_label' => 'Utlägg',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 193,
                'content' => <<<'HTML'
<p>Normal handläggningstid är 1–2 veckor. Under perioder med mycket att göra (t.ex. direkt efter ett stort event) kan det ta längre tid.</p>
<h4>Utbetalning</h4>
<p>Godkända utlägg betalas ut i nästa utbetalningskörning via bankgiro. Körtiderna sätts av ekonomiansvarig — normalt en gång per månad eller vid behov.</p>
HTML,
            ],

            [
                'slug' => 'utlagg-kontonummer',
                'title' => 'Kontonummer för utbetalning',
                'group_label' => 'Utlägg',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 194,
                'content' => <<<'HTML'
<p>Ange ditt bankkontonummer under <a href="/profile">Mina inställningar</a> innan du skickar in utlägg. Utan ett registrerat kontonummer kan utbetalning inte genomföras.</p>
<p>Ange kontonumret i formatet som din bank anger det — clearingnummer och kontonummer. Systemet hanterar OCR-kodgenerering automatiskt.</p>
HTML,
            ],

            [
                'slug' => 'utlagg-avvisades',
                'title' => 'Mitt utlägg avvisades — vad gör jag?',
                'group_label' => 'Utlägg',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 195,
                'content' => <<<'HTML'
<p>En motivering skickas via e-post när ett utlägg avvisas. Vanliga orsaker:</p>
<ul>
  <li>Kvitto saknar tillräcklig information (datum, belopp eller företagsnamn otydligt)</li>
  <li>Utlägget faller utanför vad föreningen ersätter</li>
  <li>Fel belopp eller valuta angiven</li>
</ul>
<p>Åtgärda problemet och skicka in ett nytt ärende. Det går inte att redigera ett avvisat ärende — det måste skickas in på nytt.</p>
HTML,
            ],

            // ════════════════════════════════════════════════════════════════════
            // ORG-ROLLER & MÖTEN (ny grupp för inloggade)
            // ════════════════════════════════════════════════════════════════════

            [
                'slug' => 'mina-uppdrag',
                'title' => 'Mina förtroendeuppdrag',
                'group_label' => 'Org-roller & möten',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 200,
                'content' => <<<'HTML'
<p>Dina aktiva förtroendeuppdrag (styrelsemedlem, revisor, funktionär m.m.) syns under ditt konto och i sidomenyn under "Mina uppdrag" om du har några.</p>
<h4>Vad innebär ett uppdrag?</h4>
<p>Förtroendeuppdrag ger extra behörigheter i systemet baserat på vilken roll och period du är aktiv i. Styrelseledamöter ser t.ex. bokföringsmodulen, revisorer ser revision-fliken.</p>
<p>Behörigheter synkas automatiskt en gång per natt. Kontakta en administratör om du fått ett uppdrag men saknar behörighet.</p>
HTML,
            ],

            [
                'slug' => 'möten-protokoll',
                'title' => 'Styrelsemöten och protokoll',
                'group_label' => 'Org-roller & möten',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 201,
                'content' => <<<'HTML'
<p>Styrelsemötens dagordning och protokoll hanteras under <a href="/mina-moten">Mina möten</a>. Klicka på ett möte för att se dagordningspunkter, bifogade dokument och protokollet.</p>
<h4>Tillgänglighet</h4>
<p>Publicerade protokoll är tillgängliga för alla aktiva members. Opublicerade utkast är bara synliga för styrelsemedlemmar.</p>
HTML,
            ],

            [
                'slug' => 'omröstningar',
                'title' => 'Röstning och omröstningar',
                'group_label' => 'Org-roller & möten',
                'show_public' => false, 'show_auth' => true, 'required_permission' => null,
                'sort_order' => 202,
                'content' => <<<'HTML'
<p>Digitala omröstningar (polls) skapas av administratören och skickas ut till berörda members. En länk i notifieringsmejlet tar dig till omröstningen.</p>
<h4>Resultat</h4>
<p>Resultatet av en avslutad omröstning kan visas för alla röstberättigade. Anonyma omröstningar visar enbart aggregerat resultat, inte vem som röstat hur.</p>
HTML,
            ],

            // ════════════════════════════════════════════════════════════════════
            // ADMIN — MEDLEMMAR (detaljerade)
            // ════════════════════════════════════════════════════════════════════

            [
                'slug' => 'admin-member-söka',
                'title' => 'Söka och filtrera i medlemslistan',
                'group_label' => 'Administratör — Medlemmar',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'members.manage',
                'sort_order' => 301,
                'content' => <<<'HTML'
<p>Sökfältet i <a href="/admin/members">Medlemslistan</a> söker i namn, e-postadress och Google-konto i realtid.</p>
<h4>Statusfilter</h4>
<ul>
  <li><strong>Aktiva</strong> — har giltig avgift innevarande år.</li>
  <li><strong>Utgångna</strong> — saknar giltig avgift men kontot är kvar.</li>
  <li><strong>Inaktiva</strong> — manuellt inaktiverade konton.</li>
  <li><strong>Alla</strong> — visar allt oavsett status.</li>
</ul>
<p>Sortering på kolumnhuvudena (namn, datum, status) är tillgänglig med ett klick.</p>
HTML,
            ],

            [
                'slug' => 'admin-member-skapa',
                'title' => 'Skapa en ny member manuellt',
                'group_label' => 'Administratör — Medlemmar',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'members.manage',
                'sort_order' => 302,
                'content' => <<<'HTML'
<p>Gå till <a href="/admin/members/create">Ny member</a>.</p>
<h4>Obligatoriska fält</h4>
<ul>
  <li>Förnamn och efternamn</li>
  <li>E-postadress (används för notifikationer)</li>
</ul>
<h4>Valfria fält</h4>
<ul>
  <li>Google-konto — kopplar inloggning med Google SSO. Om det lämnas tomt kan personen inte logga in förrän du lägger till det.</li>
  <li>Telefonnummer</li>
  <li>Födelseår (krävs för familjemedlemskap med åldersgräns)</li>
</ul>
<h4>Skicka välkomstmejl</h4>
<p>Bocka "Skicka välkomstmejl" om du vill att personen ska få ett välkomstmeddelande med inloggningsinstruktioner direkt.</p>
HTML,
            ],

            [
                'slug' => 'admin-member-redigera',
                'title' => 'Redigera en members uppgifter',
                'group_label' => 'Administratör — Medlemmar',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'members.manage',
                'sort_order' => 303,
                'content' => <<<'HTML'
<p>Öppna en member i listan och välj fliken <strong>Redigera</strong>.</p>
<h4>Känsliga uppgifter</h4>
<p>E-postadress och Google-konto kan ändras. Om du ändrar e-postadressen ser personen ett bekräftelsemejl till den nya adressen. Om du ändrar Google-konto uppdateras SSO-koppling omedelbart.</p>
<h4>Betalningshistorik</h4>
<p>Betalda avgifter och ordrar visas på fliken <strong>Betalningar</strong>. Du kan inte radera betalningsposter — kontakta ekonomiansvarig vid fel.</p>
HTML,
            ],

            [
                'slug' => 'admin-member-aktivera-inaktivera',
                'title' => 'Aktivera eller inaktivera ett konto',
                'group_label' => 'Administratör — Medlemmar',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'members.manage',
                'sort_order' => 304,
                'content' => <<<'HTML'
<p>Under en members profilsida — knappen <strong>Inaktivera konto</strong> stänger av åtkomst utan att radera data. Personen kan inte längre logga in och bokar inga nya lokaler.</p>
<h4>Återaktivera</h4>
<p>Klicka <strong>Aktivera konto</strong> på en inaktiverad member. Om personen också saknar giltig avgift behöver den betalas igen för full åtkomst.</p>
<h4>Automatisk avaktivering</h4>
<p>Systemet kan schemaläggas att inaktivera members som inte förnyat sin avgift. Se <a href="#admin-avaktiveringskörning">Avaktiveringskörning</a>.</p>
HTML,
            ],

            [
                'slug' => 'admin-gdpr-raderingsförfrågan',
                'title' => 'GDPR — hantera raderingsförfrågan',
                'group_label' => 'Administratör — Medlemmar',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'members.manage',
                'sort_order' => 305,
                'content' => <<<'HTML'
<p>Inkommande raderingsförfrågningar listas under <a href="/admin/members/raderingsbegaranden">Raderingsförfrågningar</a>.</p>
<h4>Handläggning</h4>
<ol>
  <li>Öppna förfrågan och kontrollera att identiteten stämmer.</li>
  <li>Klicka <strong>Utför radering</strong>. Systemet raderar personuppgifter men bevarar ekonomiska poster (7 år enligt bokföringslagen) avidentifierade.</li>
  <li>Bekräftelsemejl skickas till den registrerades e-postadress.</li>
</ol>
<p>Processen är irreversibel — kontrollera noggrant innan du utför radering.</p>
HTML,
            ],

            [
                'slug' => 'admin-sektionsansökningar',
                'title' => 'Sektionsansökningar — godkänn/neka',
                'group_label' => 'Administratör — Medlemmar',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'sections.manage',
                'sort_order' => 321,
                'content' => <<<'HTML'
<p>När en member ansöker om att gå med i en sektion (t.ex. via ett formulär) skapas ett sektionsmedlemskap med status "Pending". Det hanteras under sektionens detaljsida under <a href="/admin/grupper">Sektioner</a>.</p>
<h4>Godkänn</h4>
<p>Klicka <strong>Godkänn</strong> — statusen ändras till "Aktiv" och personen får tillgång till sektionens evenemang och resurser.</p>
<h4>Neka</h4>
<p>Klicka <strong>Neka</strong> — sektionsmedlemskapet tas bort. Skicka gärna ett manuellt mejl med förklaring.</p>
HTML,
            ],

            [
                'slug' => 'admin-orgroll-period',
                'title' => 'Org-rollperiod — skapa och hantera',
                'group_label' => 'Administratör — Medlemmar',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'org-roles.manage',
                'sort_order' => 331,
                'content' => <<<'HTML'
<p>Under <a href="/admin/org-roles">Org-roller</a> — välj en roll och klicka <strong>Ny period</strong>.</p>
<h4>Periodtyper</h4>
<ul>
  <li><strong>Kalenderår</strong> — 1 jan–31 dec. Styrelse och revisorer använder normalt denna.</li>
  <li><strong>Anpassad period</strong> — fritt definierade datum, t.ex. oktober–mars för ett konvent.</li>
</ul>
<h4>Statusövergångar</h4>
<p>Forming → Aktiv → Rapportering → Stängd. Ändra status manuellt i periodens detaljsida. Varje statusövergång kan trigga automatiska behörighetsuppdateringar.</p>
<h4>Lägga till members i perioden</h4>
<p>Under periodens sida — klicka <strong>Lägg till member</strong>. Välj person och roll (t.ex. Ordförande, Kassör, Ledamot).</p>
HTML,
            ],

            // ════════════════════════════════════════════════════════════════════
            // ADMIN — EKONOMI (detaljerade)
            // ════════════════════════════════════════════════════════════════════

            [
                'slug' => 'admin-bankkonto-sätta-upp',
                'title' => 'Sätta upp bankkonton',
                'group_label' => 'Administratör — Ekonomi',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
                'sort_order' => 431,
                'content' => <<<'HTML'
<p>Under <a href="/admin/bokforing/bankkonton">Bokföring → Bankkonton</a> konfigureras alla bankkonton som ska synkas med bokföringen.</p>
<h4>Fält per konto</h4>
<ul>
  <li><strong>Kontonamn</strong> — internt namn (t.ex. "Föreningskontot SEB")</li>
  <li><strong>IBAN</strong> — bankkontots IBAN-nummer</li>
  <li><strong>Kopplat bokföringskonto</strong> — vilket konto i kontoplanen transaktionerna bokförs mot (t.ex. 1930)</li>
  <li><strong>Valuta</strong> — normalt SEK</li>
</ul>
<p>Lägg till ett bankkonto, konfigurera koppling till kontoplan och börja sedan importera kontoutdrag.</p>
HTML,
            ],

            [
                'slug' => 'admin-iso20022-import',
                'title' => 'Importera bankfil (ISO 20022 / camt.054)',
                'group_label' => 'Administratör — Ekonomi',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
                'sort_order' => 432,
                'content' => <<<'HTML'
<p>Under <a href="/admin/bokforing/bankkonton">Bankkonton</a> — klicka på kontot och välj <strong>Importera fil</strong>.</p>
<ol>
  <li>Ladda ner kontoutdraget i ISO 20022-format (camt.054/camt.053) från din bank.</li>
  <li>Ladda upp filen. Systemet parsar alla transaktioner och skapar poster.</li>
  <li>Kör sedan <strong>Stäm av</strong> för att matcha transaktioner mot bokföringsregler. Matchade transaktioner bokförs automatiskt.</li>
  <li>Omatchade transaktioner hanteras manuellt under <a href="/admin/bokforing/banktransaktioner">Banktransaktioner</a>.</li>
</ol>
<h4>Viktigt</h4>
<p>Importera alltid i kronologisk ordning. Undvik att importera överlappande perioder — dubbletter filtreras men kan skapa förvirring.</p>
HTML,
            ],

            [
                'slug' => 'admin-swish-import',
                'title' => 'Importera Swish-fil',
                'group_label' => 'Administratör — Ekonomi',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
                'sort_order' => 433,
                'content' => <<<'HTML'
<p>Under <a href="/admin/bokforing/bankkonton/swish-import">Bank → Swish-import</a> importeras Swish-transaktionsrapporter.</p>
<p>Ladda ner transaktionsrapporten från Swish Företag (CSV-format) och ladda upp den. Systemet matchar mot evenemang och webshop-ordrar automatiskt via betalningsreferensen.</p>
HTML,
            ],

            [
                'slug' => 'admin-manuell-verifikation',
                'title' => 'Skapa en manuell verifikation',
                'group_label' => 'Administratör — Ekonomi',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
                'sort_order' => 435,
                'content' => <<<'HTML'
<p>Gå till <a href="/admin/bokforing/verifikationer/ny">Bokföring → Ny verifikation</a>.</p>
<ol>
  <li>Välj verifikationsserie (normalt A-serien för manuella poster) och datum.</li>
  <li>Lägg till konteringsrader. Debet och kredit måste balansera (summa = 0).</li>
  <li>Lägg gärna till en beskrivande text per rad och en övergripande beskrivning.</li>
  <li>Spara. Verifikationen kan inte ändras efteråt — skapa en korrigeringsverifikation vid fel.</li>
</ol>
<h4>Bifoga dokument</h4>
<p>Du kan ladda upp PDF-underlag (kvitton, fakturor) direkt på verifikationen.</p>
HTML,
            ],

            [
                'slug' => 'admin-kontoplan',
                'title' => 'Kontoplansstruktur',
                'group_label' => 'Administratör — Ekonomi',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
                'sort_order' => 436,
                'content' => <<<'HTML'
<p>Under <a href="/admin/bokforing/kontoplan">Kontoplan</a> hanteras alla konton i BAS-kontoplanens struktur.</p>
<h4>Kontotyper</h4>
<ul>
  <li><strong>Tillgång (asset)</strong> — klass 1</li>
  <li><strong>Skuld (liability)</strong> — klass 2</li>
  <li><strong>Eget kapital (equity)</strong> — klass 2</li>
  <li><strong>Intäkt (income)</strong> — klass 3</li>
  <li><strong>Kostnad (expense)</strong> — klass 4–8</li>
</ul>
<h4>Gruppkonton</h4>
<p>Konton kan markeras som gruppkonton (is_group = true) — de används bara för summering i rapporter och kan inte användas i konteringsrader.</p>
HTML,
            ],

            [
                'slug' => 'admin-bankavstämning',
                'title' => 'Bankavstämning — stäm av',
                'group_label' => 'Administratör — Ekonomi',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
                'sort_order' => 440,
                'content' => <<<'HTML'
<p>Bankavstämning görs under <a href="/admin/bokforing/banktransaktioner/stam-av">Stäm av</a>.</p>
<h4>Process</h4>
<ol>
  <li>Ange bankens slutsaldo och datum för perioden.</li>
  <li>Granska listan med omatchade transaktioner. Kryssa i de som stämmer mot bokföringen.</li>
  <li>Kvarvarande differens visas i realtid. Målet är 0 kr i differens.</li>
  <li>Spara när avstämningen är komplett — den låses för vidare redigering.</li>
</ol>
HTML,
            ],

            [
                'slug' => 'admin-bokföringsregler',
                'title' => 'Bokföringsregler — konfigurera',
                'group_label' => 'Administratör — Ekonomi',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
                'sort_order' => 441,
                'content' => <<<'HTML'
<p>Bokföringsregler under <a href="/admin/bokforing/bokforingsregler">Bokföringsregler</a> avgör hur banktransaktioner automatiskt matchas mot bokföringskonton.</p>
<h4>Regelkomponenter</h4>
<ul>
  <li><strong>Trigger</strong> — vad regeln gäller (banktransaktion, leverantörsfaktura, e-post).</li>
  <li><strong>Filter</strong> — vilka transaktioner regeln matchar (nyckelord i beskrivning, belopp, konto, datum).</li>
  <li><strong>Motpartkonto</strong> — vilket konto transaktionen bokförs mot om regeln matchar.</li>
</ul>
<h4>Ordning</h4>
<p>Reglerna tillämpas i sortordning. Placera specifika regler (t.ex. exakt belopp) före generella (t.ex. nyckelord).</p>
HTML,
            ],

            [
                'slug' => 'admin-leverantörsfakturor',
                'title' => 'Leverantörsfakturor — attest och kontering',
                'group_label' => 'Administratör — Ekonomi',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
                'sort_order' => 445,
                'content' => <<<'HTML'
<p>Under <a href="/admin/bokforing/leverantorsfakturor">Leverantörsfakturor</a> registreras och hanteras inkommande fakturor från leverantörer.</p>
<h4>Registrera en faktura</h4>
<ol>
  <li>Klicka <strong>Ny faktura</strong>.</li>
  <li>Välj leverantör (skapa ny om den inte finns).</li>
  <li>Fyll i fakturanummer, belopp, förfallodatum och konteringsrad(er).</li>
  <li>Ladda upp PDF-fakturan.</li>
  <li>Spara och godkänn.</li>
</ol>
<h4>Fakturainkorg</h4>
<p>Fakturor skickade direkt till t.ex. <code>faktura@armagedon.se</code> hamnar automatiskt i <a href="/admin/bokforing/fakturainkorg">Fakturainkorgen</a>. Öppna e-posten och klicka <strong>Skapa faktura</strong> för att förregistrera uppgifterna.</p>
HTML,
            ],

            [
                'slug' => 'admin-revision',
                'title' => 'Revision — vad ser revisorn?',
                'group_label' => 'Administratör — Ekonomi',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.view',
                'sort_order' => 447,
                'content' => <<<'HTML'
<p>Revisorer med rollen <code>revisor</code> har läsåtkomst till bokföringsmodulen under aktiv och rapporteringsperiod.</p>
<h4>Vad revisorn kan se</h4>
<ul>
  <li>Alla verifikationer och konteringsrader</li>
  <li>Leverantörsfakturor och bifogade PDF:er</li>
  <li>Bankimporter och transaktioner</li>
  <li>Alla rapporter (resultaträkning, balansräkning, huvudbok)</li>
  <li>SIE4-export</li>
</ul>
<p>Under <a href="/admin/bokforing/revision">Revision</a> finns en samlad startpunkt med direktlänkar till alla relevanta vyer.</p>
HTML,
            ],

            [
                'slug' => 'admin-sie4-export',
                'title' => 'SIE4-export — steg för steg',
                'group_label' => 'Administratör — Ekonomi',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
                'sort_order' => 449,
                'content' => <<<'HTML'
<p>SIE4-filen exporteras per räkenskapsår och innehåller alla verifikationer i standardformat för import till revisionsverktyg (t.ex. Visma, Fortnox).</p>
<ol>
  <li>Gå till <a href="/admin/bokforing/rakenskapsar">Räkenskapsår</a>.</li>
  <li>Välj det år du vill exportera.</li>
  <li>Klicka <strong>Exportera SIE4</strong>.</li>
  <li>Filen laddas ner direkt.</li>
</ol>
<p>Kontrollera med din revisor att filen importeras korrekt. SIE4 är ett väldefinierat format och bör fungera i alla svenska bokföringsprogram.</p>
HTML,
            ],

            [
                'slug' => 'admin-räkenskapsår',
                'title' => 'Räkenskapsår — skapa och avsluta',
                'group_label' => 'Administratör — Ekonomi',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
                'sort_order' => 437,
                'content' => <<<'HTML'
<p>Under <a href="/admin/bokforing/rakenskapsar">Räkenskapsår</a> skapas ett nytt räkenskapsår inför varje bokföringsperiod.</p>
<h4>Skapa nytt år</h4>
<ol>
  <li>Klicka <strong>Nytt räkenskapsår</strong>.</li>
  <li>Ange start- och slutdatum (normalt 1 jan–31 dec).</li>
  <li>Bekräfta — systemet är nu redo att ta emot verifikationer för perioden.</li>
</ol>
<h4>Ingående balanser</h4>
<p>Under det nya räkenskapsårets sida — klicka <strong>Ingående balanser</strong> för att föra in föregående års utgående balanser. Systemet föreslår automatiskt rätt värden om föregående år är korrekt bokfört.</p>
<h4>Avsluta ett år</h4>
<p>Stäng räkenskapsåret under dess sida. Stängda år kan inte längre editeras men kan läsas och exporteras.</p>
HTML,
            ],

            [
                'slug' => 'admin-budget',
                'title' => 'Budget — skapa och följa upp',
                'group_label' => 'Administratör — Ekonomi',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
                'sort_order' => 439,
                'content' => <<<'HTML'
<p>Under <a href="/admin/bokforing/budget">Budget</a> skapas budgetar per räkenskapsår och kostnadsställe.</p>
<h4>Skapa</h4>
<p>Klicka <strong>Ny budget</strong> och ange budgeterade belopp per konto. Budgeten kan specificeras per kvartal eller per år.</p>
<h4>Uppföljning</h4>
<p>Rapporten <a href="/admin/bokforing/rapporter/budgetuppfoljning">Budgetuppföljning</a> visar utfall mot budget med avvikelseprocent per konto. Används för löpande ekonomisk uppföljning vid styrelsemöten.</p>
HTML,
            ],

            [
                'slug' => 'admin-betaltjänster',
                'title' => 'Stripe och Swish — koppla betaltjänster',
                'group_label' => 'Administratör — Ekonomi',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
                'sort_order' => 450,
                'content' => <<<'HTML'
<p>Betaltjänster konfigureras under <a href="/admin/bokforing/betaltjanster">Betaltjänster</a> och i systemets konfigurationsfiler.</p>
<h4>Stripe</h4>
<p>API-nycklar (Publishable key och Secret key) hämtas från Stripe Dashboard och konfigureras i <code>.env</code>-filen. Webhooks registreras för att systemet ska ta emot betalningsbekräftelser i realtid.</p>
<h4>Swish</h4>
<p>Swish-konfiguration kräver ett certifikat från Swish Handel. Certifikatet laddas upp och kopplas till föreningens Swish-nummer.</p>
<p>Kontakta en teknisk administratör om du behöver ändra betaltjänstkonfigurationen.</p>
HTML,
            ],

            // ════════════════════════════════════════════════════════════════════
            // ADMIN — EVENEMANG (detaljerade)
            // ════════════════════════════════════════════════════════════════════

            [
                'slug' => 'admin-event-prisnivåer',
                'title' => 'Prisnivåer — konfigurera',
                'group_label' => 'Administratör — Evenemang',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'events.manage',
                'sort_order' => 501,
                'content' => <<<'HTML'
<p>Under evenemangets redigeringssida — fliken <strong>Prisnivåer</strong>.</p>
<h4>Lägg till prisnivåer</h4>
<p>Klicka <strong>Ny prisnivå</strong>. Fyll i:</p>
<ul>
  <li><strong>Namn</strong> — t.ex. "Vuxen", "Under 18", "Supporter"</li>
  <li><strong>Pris i kr</strong> — ange 0 för gratis</li>
  <li><strong>Kapacitet</strong> — valfritt max antal platser på denna nivå</li>
  <li><strong>Synlighet</strong> — om nivån ska visas för alla eller bara inloggade members</li>
</ul>
<h4>Gratisevenemang</h4>
<p>Ange 0 kr — kassan hoppar då över betalsteget och bekräftar direkt.</p>
HTML,
            ],

            [
                'slug' => 'admin-event-tillagg',
                'title' => 'Tillägg (add-ons) — skapa',
                'group_label' => 'Administratör — Evenemang',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'events.manage',
                'sort_order' => 502,
                'content' => <<<'HTML'
<p>Tillägg är valbara extraalternativ vid anmälan — t.ex. middag, logi, t-shirt.</p>
<h4>Skapa ett tillägg</h4>
<p>Under evenemangets redigeringssida — fliken <strong>Tillägg</strong>. Klicka <strong>Nytt tillägg</strong> och fyll i:</p>
<ul>
  <li>Namn och beskrivning</li>
  <li>Pris (0 = gratis)</li>
  <li>Valfritt: max antal sålda</li>
</ul>
<p>Tillägg adderas till biljettypens pris och faktureras i kassan.</p>
HTML,
            ],

            [
                'slug' => 'admin-event-väntelista-hantera',
                'title' => 'Väntelista — hantera som admin',
                'group_label' => 'Administratör — Evenemang',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'events.manage',
                'sort_order' => 504,
                'content' => <<<'HTML'
<p>Under evenemangets antagningssida ser du väntelistan med position och anmälningstid.</p>
<h4>Flytta upp en person</h4>
<p>Om en plats öppnar sig och du vill bjuda in en specifik person — klicka <strong>Flytta till bekräftad</strong>. Systemet skickar ett notifieringsmejl om krav på betalning.</p>
<h4>Automatisk kö</h4>
<p>Om systemet är konfigurerat med automatisk kö notifieras nästa person automatiskt när en registrering avbokas.</p>
HTML,
            ],

            [
                'slug' => 'admin-event-settlement',
                'title' => 'Settlement — köra efter evenemang',
                'group_label' => 'Administratör — Evenemang',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'events.manage',
                'sort_order' => 513,
                'content' => <<<'HTML'
<p>Settlement körs efter att ett evenemang avslutats för att:</p>
<ul>
  <li>Stämma av betalda vs obetalda registreringar</li>
  <li>Skapa bokföringsunderlag för eventintäkterna</li>
  <li>Markera evenemanget som avslutat</li>
</ul>
<p>Gå till evenemangets admin-sida och klicka <strong>Kör settlement</strong>. Systemet presenterar en sammanfattning för godkännande innan bokföringsposter skapas.</p>
HTML,
            ],

            [
                'slug' => 'admin-event-avbryta',
                'title' => 'Avbryta ett publicerat evenemang',
                'group_label' => 'Administratör — Evenemang',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'events.manage',
                'sort_order' => 508,
                'content' => <<<'HTML'
<p>Under evenemangets admin-sida — klicka <strong>Avbryt evenemang</strong>. Alla registreringar med status "Bekräftad" sätts till "Avbokad".</p>
<h4>E-postnotifiering</h4>
<p>Systemet skickar automatiskt ett mejl till alla anmälda med information om inställningen. Lägg till en valfri meddeladetext som inkluderas i mejlet.</p>
<h4>Återbetalning</h4>
<p>Återbetalning sker inte automatiskt. Hantera dessa manuellt via ekonomiansvarig.</p>
HTML,
            ],

            [
                'slug' => 'admin-event-deltagarexport',
                'title' => 'Exportera deltagarlista',
                'group_label' => 'Administratör — Evenemang',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'events.manage',
                'sort_order' => 509,
                'content' => <<<'HTML'
<p>Under evenemangets admin-sida — klicka <strong>Exportera deltagare</strong> för att ladda ner en CSV med alla anmälda.</p>
<h4>Innehåll i CSV</h4>
<p>Namn, e-post, biljettyp, tillägg, betalningsstatus, anmälningsdatum och incheckningstidpunkt.</p>
HTML,
            ],

            // ════════════════════════════════════════════════════════════════════
            // ADMIN — KOMMUNIKATION (detaljerade)
            // ════════════════════════════════════════════════════════════════════

            [
                'slug' => 'admin-mailmallar',
                'title' => 'Mailmallar — redigera och variabler',
                'group_label' => 'Administratör — Kommunikation',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'mailings.manage',
                'sort_order' => 601,
                'content' => <<<'HTML'
<p>Under <a href="/admin/mail-mallar">Mail-mallar</a> redigeras alla 27 systemets transaktionsmejl — bekräftelser, påminnelser, välkomstmejl m.m.</p>
<h4>Variabeltoken</h4>
<p>Mallar stöder dynamiska tokens som ersätts med faktisk data vid utskick:</p>
<ul>
  <li><code>{{member_name}}</code> — mottagarens namn</li>
  <li><code>{{event_title}}</code> — evenemangstitel (vid eventmejl)</li>
  <li><code>{{amount}}</code> — belopp</li>
  <li><code>{{ocr}}</code> — OCR-referens för bankgiro</li>
  <li><code>{{link}}</code> — åtgärdslänk (t.ex. till betalning)</li>
</ul>
<p>Tillgängliga tokens varierar per mall — en lista visas bredvid redigeringsformuläret.</p>
HTML,
            ],

            [
                'slug' => 'admin-utskick-mottagargrupper',
                'title' => 'Utskick — mottagargrupper',
                'group_label' => 'Administratör — Kommunikation',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'mailings.manage',
                'sort_order' => 602,
                'content' => <<<'HTML'
<p>När du skapar ett utskick väljer du vilka som ska ta emot det:</p>
<ul>
  <li><strong>Alla aktiva members</strong> — samtliga med giltigt medlemskap.</li>
  <li><strong>Sektion</strong> — alla aktiva members i en specifik sektion.</li>
  <li><strong>Eventdeltagare</strong> — alla som är anmälda till ett specifikt evenemang (alla statusar, eller bara bekräftade).</li>
  <li><strong>Anpassad lista</strong> — klistra in en kommaseparerad lista med e-postadresser.</li>
</ul>
<p>Avprenumererade mottagare hoppas alltid över oavsett vald grupp.</p>
HTML,
            ],

            [
                'slug' => 'admin-utskick-avprenumeration',
                'title' => 'Avprenumerationshantering',
                'group_label' => 'Administratör — Kommunikation',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'mailings.manage',
                'sort_order' => 603,
                'content' => <<<'HTML'
<p>Varje utskick innehåller en avprenumerationslänk i footern. Klickar mottagaren på den registreras deras e-post som avprenumererad.</p>
<h4>Systemmejl</h4>
<p>Transaktionsmejl (orderbekräftelser, lösenordsåterställning) skickas alltid oavsett avprenumeration. Avprenumerationen gäller bara massutskick.</p>
<h4>Återprenumerera</h4>
<p>Kontakta administratören om en person felaktigt avregistrerade sig och vill ha utskick igen.</p>
HTML,
            ],

            [
                'slug' => 'admin-enkät-inbjudningar',
                'title' => 'Enkätinbjudningar — skicka ut',
                'group_label' => 'Administratör — Kommunikation',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'surveys.manage',
                'sort_order' => 611,
                'content' => <<<'HTML'
<p>Under enkätens flik <strong>Inbjudningar</strong> — välj vilka som ska bjudas in.</p>
<h4>Alternativ</h4>
<ul>
  <li>Välj mottagargrupp (samma som utskick).</li>
  <li>Klicka <strong>Skicka inbjudningar</strong>. Systemet genererar unika svarslänkar per mottagare och skickar mejl.</li>
</ul>
<h4>Påminnelse</h4>
<p>Under inbjudningssidan ser du vem som svarat och vem som inte svarat. Välj "Icke-svarare" och klicka <strong>Skicka påminnelse</strong>.</p>
HTML,
            ],

            [
                'slug' => 'admin-enkät-export',
                'title' => 'Enkätsvar — exportera',
                'group_label' => 'Administratör — Kommunikation',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'surveys.manage',
                'sort_order' => 612,
                'content' => <<<'HTML'
<p>Under enkätens flik <strong>Svar</strong> — klicka <strong>Exportera CSV</strong>.</p>
<p>CSV:n innehåller en rad per respondent (eller per svar vid anonyma enkäter) med alla frågor som kolumner. Öppna i Excel eller Google Sheets för vidare analys.</p>
HTML,
            ],

            [
                'slug' => 'admin-kontaktmeddelanden',
                'title' => 'Kontaktmeddelanden',
                'group_label' => 'Administratör — Kommunikation',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'content.manage',
                'sort_order' => 621,
                'content' => <<<'HTML'
<p>Meddelanden från <a href="/kontakt">Kontaktsidan</a> lagras under <a href="/admin/kontakt">Admin → Kontaktmeddelanden</a>.</p>
<p>Markera meddelanden som hanterade när du besvarat dem. Du kan svara direkt via din e-postklient — avsändarens e-post visas i meddelandet.</p>
HTML,
            ],

            // ════════════════════════════════════════════════════════════════════
            // ADMIN — SYSTEM (detaljerade)
            // ════════════════════════════════════════════════════════════════════

            [
                'slug' => 'admin-systeminställningar',
                'title' => 'Systeminställningar',
                'group_label' => 'Administratör — System',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'users.manage-roles',
                'sort_order' => 781,
                'content' => <<<'HTML'
<p>Under <a href="/admin/installningar">Admin → Inställningar</a> konfigureras systemets grundläggande beteende.</p>
<h4>Nyckelinställningar</h4>
<ul>
  <li><strong>Familjegrupp</strong> — max antal vuxna, barn och maxålder för barn.</li>
  <li><strong>Bokningsgränser</strong> — max antal aktiva bokningar per member och per superbookare.</li>
  <li><strong>Bokningsfönster</strong> — hur långt fram i tid bokningar tillåts.</li>
  <li><strong>Meddelanden</strong> — informationstext till icke-members, t.ex. hur man betalar avgiften.</li>
</ul>
HTML,
            ],

            [
                'slug' => 'admin-mail-logg',
                'title' => 'Mail-logg — diagnostik',
                'group_label' => 'Administratör — System',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'users.manage-roles',
                'sort_order' => 782,
                'content' => <<<'HTML'
<p>Under <a href="/admin/mail-logg">Admin → Mail-logg</a> loggas alla utgående mejl — med mottagare, ämne, mall och tidpunkt.</p>
<h4>Felsökning</h4>
<p>Om en member inte fick ett förväntat mejl — sök på deras e-postadress i loggen. Kontrollera om mejlet skickades och om det eventuellt hamnade i skräppost hos mottagaren.</p>
<p>Loggen visar inte leveransstatus (om mottagarens server accepterade mejlet) — det kräver djupare SMTP-loggning på servernivå.</p>
HTML,
            ],

            [
                'slug' => 'admin-hjälptexter-redigera',
                'title' => 'Hjälptexter — redigera',
                'group_label' => 'Administratör — System',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'content.manage',
                'sort_order' => 711,
                'content' => <<<'HTML'
<p>Under <a href="/admin/help-texts">Hjälptexter</a> hanteras korta in-app-instruktioner som visas bredvid fält och formulär i systemet.</p>
<h4>Redigera</h4>
<p>Sök på nyckel eller fritext och klicka på raden för att redigera. Hjälptexten sparas omedelbart och visas utan omstart.</p>
<h4>Nyckelformat</h4>
<p>Nycklar är på formatet <code>modul.kontext.beskrivning</code>, t.ex. <code>events.price_tiers.help</code>. Nya nycklar läggs till i kod av en utvecklare.</p>
HTML,
            ],

            [
                'slug' => 'admin-hjälpsektioner-redigera',
                'title' => 'Hjälpsektioner — redigera',
                'group_label' => 'Administratör — System',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'help.manage',
                'sort_order' => 712,
                'content' => <<<'HTML'
<p>Under <a href="/admin/hjalp-sektioner">Admin → Hjälpsektioner</a> redigerar du innehållet på den här hjälpsidan.</p>
<h4>Skapa en ny sektion</h4>
<p>Klicka <strong>Ny sektion</strong>. Fyll i titel, slug (URL-ankare), gruppnamn, innehåll (HTML) och synlighetsinställningar.</p>
<h4>Synlighet</h4>
<ul>
  <li><strong>Publik</strong> — syns för alla besökare.</li>
  <li><strong>Inloggad</strong> — syns bara för inloggade users.</li>
  <li><strong>Kräver behörighet</strong> — syns bara för users med angiven Spatie-behörighet (t.ex. <code>accounting.manage</code>).</li>
</ul>
HTML,
            ],

            [
                'slug' => 'admin-api-docs',
                'title' => 'API-dokumentation',
                'group_label' => 'Administratör — System',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'users.manage-roles',
                'sort_order' => 783,
                'content' => <<<'HTML'
<p>Systemet exponerar ett REST-API under <code>/api/v1/</code>. Fullständig dokumentation finns på <a href="/api-docs">/api-docs</a> (Swagger UI).</p>
<h4>Autentisering</h4>
<p>Externa klienter autentiseras med Bearer-tokens (Laravel Sanctum). Skapa en token under din profilsida eller via admin-gränssnittet för systemintegrationer.</p>
<h4>OpenAPI-spec</h4>
<p>Raw OpenAPI-specen finns på <code>/api/openapi.yaml</code> och kan importeras i Postman, Insomnia eller liknande verktyg.</p>
HTML,
            ],

            [
                'slug' => 'admin-artisan-kommandon',
                'title' => 'Artisan-kommandon — referens',
                'group_label' => 'Administratör — System',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'users.manage-roles',
                'sort_order' => 784,
                'content' => <<<'HTML'
<p>Systemet har ett antal artisan-kommandon för drift och underhåll. Kör dessa via SSH på servern.</p>
<ul>
  <li><code>php artisan mailings:process</code> — skickar schemalagda utskick (körs via cron var 5:e min).</li>
  <li><code>php artisan mail:import-imap</code> — hämtar ny e-post via IMAP (var 5:e min).</li>
  <li><code>php artisan memberships:renew</code> — skickar förnyelsepåminnelser.</li>
  <li><code>php artisan memberships:deactivate</code> — inaktiverar utgångna members.</li>
  <li><code>php artisan org-roles:sync-permissions</code> — synkar behörigheter mot org-rollperioder (dagligen 03:00).</li>
  <li><code>php artisan paxton:sync</code> — synkar RFID-taggar mot Paxton (via agent).</li>
  <li><code>php artisan import:cogworks</code> — engångsimport av historik från CogWorks.</li>
</ul>
HTML,
            ],

            [
                'slug' => 'admin-cron-jobb',
                'title' => 'Cron-jobb — vad körs när?',
                'group_label' => 'Administratör — System',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'users.manage-roles',
                'sort_order' => 785,
                'content' => <<<'HTML'
<p>Systemet schemaläggs via Laravel Scheduler som anropas av serverns cron-jobb. Lägg till i crontab:</p>
<code>* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&amp;1</code>
<h4>Schema</h4>
<ul>
  <li>Var 5:e minut — Utskick, IMAP-import</li>
  <li>Varje natt 01:00 — Förnyelsepåminnelser</li>
  <li>Varje natt 02:00 — Avaktivering av utgångna members</li>
  <li>Varje natt 03:00 — Org-rollerbehörighetssynk</li>
  <li>Varje natt 04:00 — Rekurrerandebokningar (generera nästa månad)</li>
</ul>
HTML,
            ],

            [
                'slug' => 'admin-kiosk-sätta-upp',
                'title' => 'Kiosk — sätta upp',
                'group_label' => 'Administratör — System',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'scanner.manage',
                'sort_order' => 721,
                'content' => <<<'HTML'
<p>Kiosken är ett dedikerat inloggningsläge för en surfplatta eller dator vid entrén.</p>
<h4>Steg</h4>
<ol>
  <li>Öppna <a href="/admin/scanna/kiosk">/admin/scanna/kiosk</a> i webbläsaren på enheten.</li>
  <li>Aktivera helskärmsläge (F11 på desktop, eller "Lägg till på hemskärmen" på iPad).</li>
  <li>Stäng av skärmsläckare och automatisk skärmlås på enheten.</li>
</ol>
<h4>RFID</h4>
<p>Anslut en USB RFID-läsare. Läsaren matas som tangentbord — kiosken plockar upp signalen och loggar in rätt member direkt.</p>
<h4>QR-incheckning</h4>
<p>Kiosken kan också använda enhetens kamera för att skanna biljettens QR-kod vid eventincheckning.</p>
HTML,
            ],

            [
                'slug' => 'admin-drive-behörigheter',
                'title' => 'Google Drive — behörigheter per sektion',
                'group_label' => 'Administratör — System',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'workspace.manage',
                'sort_order' => 732,
                'content' => <<<'HTML'
<p>Under <a href="/admin/workspace/drive">Workspace → Drive</a> hanteras delade mappar kopplade till sektioner och org-roller.</p>
<h4>Automatisk synkning</h4>
<p>När en member läggs till eller tas bort från en sektion uppdateras Drive-behörigheterna automatiskt (nästa nattliga synk). Kör <code>php artisan workspace:sync-drive</code> för omedelbar synkning.</p>
<h4>Manuell justering</h4>
<p>Klicka på en mapp och välj <strong>Hantera delning</strong> för att manuellt lägga till eller ta bort behörigheter utan att vänta på nästa synk.</p>
HTML,
            ],

            [
                'slug' => 'admin-bokningsinställningar',
                'title' => 'Lokalbokningsinställningar',
                'group_label' => 'Administratör — System',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'bookings.manage',
                'sort_order' => 751,
                'content' => <<<'HTML'
<p>Under <a href="/admin/installningar">Systeminställningar</a> — sektionen <strong>Lokalbokningar</strong>:</p>
<ul>
  <li><strong>Max bokningar per member</strong> — hur många aktiva framtida bokningar en vanlig member kan ha.</li>
  <li><strong>Max bokningar superbookare</strong> — högre gräns för users med extra behörighet.</li>
  <li><strong>Max månader framåt</strong> — hur lång tid in i framtiden bokningar tillåts.</li>
  <li><strong>Öppna arrangemang undantagna</strong> — räknas inte mot bokningsgränsen om aktiv.</li>
  <li><strong>Bokning öppnar datum</strong> — kan blockera bokning fram till ett datum (t.ex. vid ny säsongsstart).</li>
</ul>
HTML,
            ],

            [
                'slug' => 'admin-avaktiveringskörning',
                'title' => 'Avaktiveringskörning',
                'group_label' => 'Administratör — System',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'members.manage',
                'sort_order' => 306,
                'content' => <<<'HTML'
<p>Körs automatiskt nattligen av cron (<code>php artisan memberships:deactivate</code>) eller manuellt via <a href="/admin/members/avaktiveringar">Avaktivering</a>.</p>
<h4>Process</h4>
<ol>
  <li>Systemet identifierar members utan giltig avgift innevarande år efter konfigurerad grace period.</li>
  <li>En rapport visas med alla kandidater för avaktivering.</li>
  <li>Admins kan undanta specifika members (t.ex. vid utestående förnyelse).</li>
  <li>Bekräfta körningen — de kvarvarande members inaktiveras och meddelas via e-post.</li>
</ol>
HTML,
            ],

            [
                'slug' => 'admin-driftsättning',
                'title' => 'Driftsättning och deploy',
                'group_label' => 'Administratör — System',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'users.manage-roles',
                'sort_order' => 790,
                'content' => <<<'HTML'
<p>Systemet körs på delad webbhosting. Deploy-processen:</p>
<ol>
  <li>Ladda upp ny kod via SFTP/Git till servern.</li>
  <li>Kör <code>composer install --no-dev --optimize-autoloader</code>.</li>
  <li>Kör <code>php artisan migrate --force</code>.</li>
  <li>Kör <code>php artisan config:cache &amp;&amp; php artisan route:cache &amp;&amp; php artisan view:cache</code>.</li>
  <li>Kör <code>npm run build</code> (kräver rätt Node-version).</li>
  <li>Kör <code>./fix-permissions.sh</code>.</li>
</ol>
<h4>npm build-problem</h4>
<p>Om npm-byggning misslyckas med behörighetsfel: <code>find node_modules/@esbuild/linux-x64/bin/ -type f | xargs chmod +x</code></p>
HTML,
            ],

            [
                'slug' => 'admin-felsökning',
                'title' => 'Felsökning — vanliga systemfel',
                'group_label' => 'Administratör — System',
                'show_public' => false, 'show_auth' => false, 'required_permission' => 'users.manage-roles',
                'sort_order' => 791,
                'content' => <<<'HTML'
<h4>Sidan laddar inte</h4>
<p>Kontrollera Laravel-loggen i <code>storage/logs/laravel.log</code>. 500-fel beror ofta på felaktig .env-konfiguration eller mislyckad migrering.</p>
<h4>Mejl skickas inte</h4>
<p>Kontrollera SMTP-konfiguration i .env och mail-loggen under <a href="/admin/mail-logg">Mail-logg</a>. Vid delad hosting — se till att avsändaradressen är godkänd i serverns mailkonfiguration.</p>
<h4>Betalning bekräftas inte</h4>
<p>Stripe/Swish-webhooks kräver att servern är nåbar via HTTPS. Kontrollera webhook-loggen i Stripe Dashboard om bekräftelsen inte ankommit inom 5 minuter.</p>
<h4>Google SSO fungerar inte</h4>
<p>Kontrollera att Google OAuth-klientens redirect URI är korrekt konfigurerad och matchar serveradressen. Credentials konfigureras i .env (<code>GOOGLE_CLIENT_ID</code>, <code>GOOGLE_CLIENT_SECRET</code>).</p>
HTML,
            ],

        ];

        foreach ($sections as $s) {
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
            'systemkrav', 'dataskydd-gdpr', 'kontakta-foreningen',
            'kan-inte-logga-in', 'lankade-google-konton', 'min-profil', 'radera-konto-gdpr',
            'vad-ingår-i-medlemskap', 'aktiveringstid', 'rfid-kort', 'temaval',
            'evenemang-kalender', 'evenemang-pristyper', 'evenemang-tillagg',
            'evenemang-vantelista', 'evenemang-forhandskrav', 'evenemang-avboka',
            'evenemang-inställt', 'evenemang-öppet-arrangemang', 'evenemang-qr-biljett',
            'shop-betalsätt', 'shop-orderbekraftelse', 'shop-rabattkod-fungerar-inte',
            'shop-varukorg-raderas', 'shop-retur', 'shop-min-pris',
            'shop-utan-inloggning', 'shop-spåra-order',
            'uthyrning-vad-kan-jag-hyra', 'uthyrning-avsluta', 'uthyrning-skada',
            'lokalbokning-vilka-rum', 'lokalbokning-öppet-arrangemang',
            'lokalbokning-gräns', 'lokalbokning-redigera', 'lokalbokning-ical',
            'lokalbokning-godkännande', 'lokalbokning-lokallan',
            'enkät-länk-utgått', 'enkät-ändra-svar', 'enkät-anonym',
            'formulär-varför-inte-skicka',
            'utlagg-format', 'utlagg-handläggningstid', 'utlagg-kontonummer', 'utlagg-avvisades',
            'mina-uppdrag', 'möten-protokoll', 'omröstningar',
            'admin-member-söka', 'admin-member-skapa', 'admin-member-redigera',
            'admin-member-aktivera-inaktivera', 'admin-gdpr-raderingsförfrågan',
            'admin-sektionsansökningar', 'admin-orgroll-period',
            'admin-bankkonto-sätta-upp', 'admin-iso20022-import', 'admin-swish-import',
            'admin-manuell-verifikation', 'admin-kontoplan', 'admin-bankavstämning',
            'admin-bokföringsregler', 'admin-leverantörsfakturor', 'admin-revision',
            'admin-sie4-export', 'admin-räkenskapsår', 'admin-budget', 'admin-betaltjänster',
            'admin-event-prisnivåer', 'admin-event-tillagg', 'admin-event-väntelista-hantera',
            'admin-event-settlement', 'admin-event-avbryta', 'admin-event-deltagarexport',
            'admin-mailmallar', 'admin-utskick-mottagargrupper', 'admin-utskick-avprenumeration',
            'admin-enkät-inbjudningar', 'admin-enkät-export', 'admin-kontaktmeddelanden',
            'admin-systeminställningar', 'admin-mail-logg', 'admin-hjälptexter-redigera',
            'admin-hjälpsektioner-redigera', 'admin-api-docs', 'admin-artisan-kommandon',
            'admin-cron-jobb', 'admin-kiosk-sätta-upp', 'admin-drive-behörigheter',
            'admin-bokningsinställningar', 'admin-avaktiveringskörning',
            'admin-driftsättning', 'admin-felsökning',
        ];

        DB::table('help_sections')->whereIn('slug', $slugs)->delete();
    }
};
