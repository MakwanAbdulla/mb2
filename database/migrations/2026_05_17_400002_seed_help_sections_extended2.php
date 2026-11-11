<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $sections = [

            // ── Allmänt (add 8, sort 29-36) ────────────────────────────────
            ['slug' => 'om-foreningen', 'group_label' => '', 'sort_order' => 29,
             'show_public' => true, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Om Armagedon',
             'content' => '<p>Armagedon är en ideell spelförening i Borås med fokus på sällskapsspel, miniatyrspel och rollspel. Föreningen arrangerar spelkvällar, turneringar och stämmor, förvaltar en spelsamling och ett lokalbokningssystem för medlemmar.</p><p>Föreningen styrs av en vald styrelse. Verksamheten finansieras av medlemsavgifter och arrangemangsbiljetter.</p>'],

            ['slug' => 'anvander-data', 'group_label' => '', 'sort_order' => 30,
             'show_public' => true, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Hur används mina uppgifter?',
             'content' => '<p>Dina personuppgifter används uteslutande för att administrera ditt medlemskap — kommunikation om evenemang, avgifter och föreningsärenden. Uppgifterna delas inte med tredje part utöver vad som krävs för betalningshantering (Stripe, Swish).</p><p>Du kan begära ut, korrigera eller radera dina uppgifter när som helst. Se avsnittet <a href="/hjalp/konto#radera-konto-gdpr">Radera konto (GDPR)</a>.</p>'],

            ['slug' => 'spel-och-aktiviteter', 'group_label' => '', 'sort_order' => 31,
             'show_public' => true, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Vad erbjuder föreningen?',
             'content' => '<ul><li><strong>Spellån</strong> — föreningen har en samling spel som medlemmar kan låna.</li><li><strong>Lokalbokningar</strong> — boka föreningens lokaler för spelmöten.</li><li><strong>Evenemang</strong> — turneringar, spelkvällar och stämmor.</li><li><strong>Uthyrning</strong> — spelbord, figurer och annat material att hyra.</li><li><strong>Webshop</strong> — föreningens egna produkter och merchandise.</li></ul>'],

            ['slug' => 'konto-utan-aktivt-medlemskap', 'group_label' => '', 'sort_order' => 32,
             'show_public' => true, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Kan jag ha konto utan aktivt medlemskap?',
             'content' => '<p>Ja. Du kan skapa ett konto och logga in även om din medlemsavgift inte är betald eller har gått ut. Funktioner som kräver aktivt medlemskap (rabatterade priser, vissa evenemang, uthyrning) är då begränsade. Du ser vilka funktioner som kräver aktivt medlemskap i respektive sektion av systemet.</p>'],

            ['slug' => 'rapportera-fel', 'group_label' => '', 'sort_order' => 33,
             'show_public' => true, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Rapportera fel eller ge feedback',
             'content' => '<p>Hittar du ett fel eller har förslag på förbättringar? Kontakta styrelsen via <a href="/kontakta-oss">kontaktformuläret</a> och beskriv vad som hände och i vilket steg felet uppstod. Ange gärna vilken enhet och webbläsare du använder.</p>'],

            ['slug' => 'webblesare-stod', 'group_label' => '', 'sort_order' => 34,
             'show_public' => true, 'show_auth' => false, 'required_permission' => null,
             'title' => 'Vilka webbläsare stöds?',
             'content' => '<p>Systemet fungerar i alla moderna webbläsare — Chrome, Firefox, Safari och Edge (senaste versionerna). Internet Explorer stöds inte. På mobil fungerar Chrome för Android och Safari för iOS.</p>'],

            ['slug' => 'anvanda-mobil', 'group_label' => '', 'sort_order' => 35,
             'show_public' => true, 'show_auth' => false, 'required_permission' => null,
             'title' => 'Fungerar systemet på mobil?',
             'content' => '<p>Ja, systemet är responsivt och fungerar på mobil. QR-biljetter vid incheckning och RFID-scanning kräver att du antingen har skärmen tillgänglig eller ett fysiskt RFID-kort. Komplexa admin-gränssnitt (bokföring, formulärbyggaren) är enklast att använda på en större skärm.</p>'],

            ['slug' => 'cookies', 'group_label' => '', 'sort_order' => 36,
             'show_public' => true, 'show_auth' => false, 'required_permission' => null,
             'title' => 'Cookies och sessioner',
             'content' => '<p>Systemet använder en sessions-cookie för att hålla dig inloggad. Inga spårningscookies eller reklam-cookies används. Du förblir inloggad tills du aktivt loggar ut eller sessionen går ut (typiskt efter 2 timmar inaktivitet).</p>'],

            // ── Konto & inloggning (add 8, sort 61-68) ──────────────────────
            ['slug' => 'byta-epost', 'group_label' => 'Konto & inloggning', 'sort_order' => 61,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Byta e-postadress',
             'content' => '<p>Kontakta styrelsen för att byta e-postadress. Din e-post används som inloggningsuppgift och för att skicka bekräftelser, så bytet kräver manuell verifiering.</p><p>Om du använder Google-inloggning: byt e-post i ditt Google-konto och länka om kontot i <a href="/installningar">Inställningar</a>.</p>'],

            ['slug' => 'familjegrupp-oversikt', 'group_label' => 'Konto & inloggning', 'sort_order' => 62,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Min familjegrupp',
             'content' => '<p>Om du är del av en familjegrupp kan du se det i din profil. Familjegrupper administreras av en utsedd familjeadministratör (vanligtvis en vuxen). Administratören kan lägga till och ta bort familjemedlemmar.</p><p>Barn under 18 utan eget konto hanteras av familjeadministratören. Kontakta styrelsen om du vill gå ur eller byta familjegrupp.</p>'],

            ['slug' => 'barn-konto', 'group_label' => 'Konto & inloggning', 'sort_order' => 63,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Konton för barn',
             'content' => '<p>Barn kan vara medlemmar med eller utan eget inloggningskonto. Det bestäms av familjeadministratören. Barn utan eget konto hanteras via familjekontot — deras deltagande i evenemang och avgifter visas under familjeadministratörens vy.</p><p>Barn med eget konto loggar in med sin egen e-post och kan använda systemet som vanliga användare med ålderslämpliga begränsningar.</p>'],

            ['slug' => 'kiosk-inloggning', 'group_label' => 'Konto & inloggning', 'sort_order' => 64,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Logga in på kiosken med RFID',
             'content' => '<p>Vid föreningens lokaler finns en självbetjäningskiosk. Håll ditt RFID-kort mot läsaren för att identifiera dig. Du behöver inte ange lösenord — kortet fungerar som din identifiering för incheckning vid evenemang och lokalåtkomst.</p><p>Saknar du RFID-kort? Kontakta styrelsen för att få ett tilldelat. Se även <a href="/hjalp/konto#rfid-kort">Mitt RFID-kort</a>.</p>'],

            ['slug' => 'notiser-epost', 'group_label' => 'Konto & inloggning', 'sort_order' => 65,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'E-postaviseringar',
             'content' => '<p>Systemet skickar automatiska e-poster för: bekräftelse av anmälan, orderbekräftelse, påminnelser inför evenemang, godkänt/avvisat utlägg och fakturor.</p><p>Du kan avprenumerera från marknadsföringsutskick via länken längst ned i varje sådant mail. Transaktionella mail (orderbekräftelser, fakturor) kan inte stängas av.</p>'],

            ['slug' => 'session-utloggning', 'group_label' => 'Konto & inloggning', 'sort_order' => 66,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Automatisk utloggning',
             'content' => '<p>Av säkerhetsskäl loggas du ut automatiskt efter en periods inaktivitet. Om du är mitt i ett formulär och sessionen går ut: logga in igen — om du inte har navigerat bort sparas ifylld data ofta kvar i webbläsaren.</p><p>Logga alltid ut manuellt på delade datorer via menyn uppe till höger.</p>'],

            ['slug' => 'mina-sektioner', 'group_label' => 'Konto & inloggning', 'sort_order' => 67,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Mina sektioner',
             'content' => '<p>Föreningen är indelad i sektioner — t.ex. sällskapsspel, miniatyrspel, rollspel. Du kan gå med i sektioner som intresserar dig via din profil eller via ett anmälningsformulär.</p><p>Sektionsmedlemskap kan kräva godkännande av sektionsansvarig. Status visas i din profil: <em>väntande</em> eller <em>aktiv</em>.</p>'],

            ['slug' => 'inloggning-problem-google', 'group_label' => 'Konto & inloggning', 'sort_order' => 68,
             'show_public' => false, 'show_auth' => false, 'required_permission' => null,
             'title' => 'Problem med Google-inloggning',
             'content' => '<p>Ser du ett felmeddelande vid Google-inloggning?</p><ol><li>Kontrollera att du använder rätt Google-konto (samma e-post som du registrerade hos föreningen).</li><li>Rensa webbläsarens cache och cookies och försök igen.</li><li>Om du nyligen bytt Google-konto: logga in med e-post/lösenord och länka om Google-kontot under <a href="/installningar">Inställningar</a>.</li><li>Fungerar det fortfarande inte? Kontakta styrelsen med en skärmdump av felmeddelandet.</li></ol>'],

            // ── Evenemang (add 10, sort 131-140) ────────────────────────────
            ['slug' => 'evenemang-hitta', 'group_label' => 'Evenemang', 'sort_order' => 131,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Hitta evenemang',
             'content' => '<p>Alla publicerade evenemang visas under <a href="/evenemang">Evenemang</a>. Du kan filtrera på datum, kategori och om du är anmäld. Kommande evenemang visas även på startsidan.</p><p>Saknar du ett specifikt event? Kontrollera att du är inloggad — vissa evenemang är bara synliga för inloggade medlemmar.</p>'],

            ['slug' => 'evenemang-gratis', 'group_label' => 'Evenemang', 'sort_order' => 132,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Gratis evenemang',
             'content' => '<p>Om ett evenemang är gratis bekräftas din plats direkt vid anmälan utan betalningssteg. Du får ändå en bekräftelse via e-post med din QR-biljett. Anmälan är bindande — avboka i god tid om du inte kan komma.</p>'],

            ['slug' => 'evenemang-bekraftelse-epost', 'group_label' => 'Evenemang', 'sort_order' => 133,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Bekräftelsemail och biljett',
             'content' => '<p>Efter godkänd anmälan skickas en bekräftelse till din e-post med:</p><ul><li>Datum, tid och plats för evenemanget</li><li>Din QR-kod (biljett)</li><li>Betalningsstatus (om avgift krävs)</li></ul><p>Hittar du inte mailet? Kolla skräppost. Du kan alltid se dina anmälningar och QR-koder under <a href="/mina-anmalningar">Mina anmälningar</a>.</p>'],

            ['slug' => 'evenemang-biljett-visa', 'group_label' => 'Evenemang', 'sort_order' => 134,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Var hittar jag min biljett?',
             'content' => '<p>Dina biljetter (QR-koder) finns under <a href="/mina-anmalningar">Mina anmälningar</a>. Klicka på ett evenemang för att visa QR-koden som incheckning sker mot. Du kan ta en skärmdump av QR-koden om du vill ha den offline.</p>'],

            ['slug' => 'evenemang-checkin-process', 'group_label' => 'Evenemang', 'sort_order' => 135,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Incheckning på plats',
             'content' => '<p>Vid evenemanget checkar du in på ett av två sätt:</p><ol><li><strong>QR-kod</strong> — visa QR-koden på skärmen eller utskriven för evenemangsansvarig.</li><li><strong>RFID-kort</strong> — håll kortet mot kiosk-läsaren vid entrén.</li></ol><p>Du behöver ha betalat (om eventet har avgift) för att checka in. Obetalda anmälningar markeras som väntande och kan nekas inträde.</p>'],

            ['slug' => 'evenemang-kvitto', 'group_label' => 'Evenemang', 'sort_order' => 136,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Kvitto på betalt evenemang',
             'content' => '<p>Betalningsbekräftelsen i ditt e-postinkorg fungerar som kvitto. Du hittar även din orderhistorik under <a href="/installningar/ordrar">Mina beställningar</a>. Behöver du ett formellt kvitto för utläggsredovisning? Kontakta styrelsen.</p>'],

            ['slug' => 'evenemang-pris-skillnad', 'group_label' => 'Evenemang', 'sort_order' => 137,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Prissskillnad mellan prisnivåer',
             'content' => '<p>Många evenemang har flera prisnivåer — t.ex. <em>Medlem</em> och <em>Icke-medlem</em>. Systemet väljer automatiskt rätt prisnivå baserat på ditt medlemsstatus vid anmälningstillfället.</p><p>Aktiverar du ditt medlemskap efter anmälan ändras inte priset retroaktivt. Kontakta arrangören om du anser att fel pris tillämpats.</p>'],

            ['slug' => 'evenemang-tillfallen', 'group_label' => 'Evenemang', 'sort_order' => 138,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Evenemang med flera tillfällen',
             'content' => '<p>Vissa evenemang körs vid flera tillfällen (t.ex. veckovisa spelmöten). Du anmäler dig till ett specifikt tillfälle. Om du vill delta i flera tillfällen anmäler du dig separat för varje.</p>'],

            ['slug' => 'evenemang-paminnelse', 'group_label' => 'Evenemang', 'sort_order' => 139,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Påminnelsemail',
             'content' => '<p>Systemet skickar automatiska påminnelser inför evenemang du är anmäld till — vanligtvis 48 timmar innan. Påminnelsen innehåller tid, plats och länk till din QR-biljett.</p><p>Får du inga påminnelser? Kontrollera att din e-postadress är korrekt i <a href="/installningar">Inställningar</a> och att mail från föreningen inte hamnar i skräppost.</p>'],

            ['slug' => 'evenemang-forhandskrav-missat', 'group_label' => 'Evenemang', 'sort_order' => 140,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Jag uppfyller inte förhandskraven',
             'content' => '<p>Om ett evenemang kräver t.ex. aktivt medlemskap eller deltagande i ett tidigare event och du inte uppfyller kraven, kan du inte anmäla dig. Kontakta evenemangsarrangören om du anser att kravet bör vara undantaget i ditt fall — de kan manuellt godkänna din anmälan.</p>'],

            // ── Webshop (add 7, sort 154-160) ────────────────────────────────
            ['slug' => 'shop-variantval', 'group_label' => 'Webshop', 'sort_order' => 154,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Välja produktvarianter',
             'content' => '<p>Produkter med varianter (t.ex. storlek eller färg) visar ett val i produktsidan. Välj din önskade variant innan du lägger produkten i varukorgen. Varianter kan ha olika priser och lagersaldon.</p>'],

            ['slug' => 'shop-betalning-bankgiro', 'group_label' => 'Webshop', 'sort_order' => 155,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Betala med bankgiro',
             'content' => '<p>Väljer du bankgiro får du ett inbetalningskort med OCR-nummer. Betalning måste ske inom den angivna betalningstiden (vanligtvis 10 dagar). Ordern aktiveras när betalningen matchats automatiskt mot föreningens bankfil.</p><p>Obs: bankgirobetalningar tar 1–3 bankdagar att registreras. Välj Swish eller kort för omedelbar bekräftelse.</p>'],

            ['slug' => 'shop-orderhistorik', 'group_label' => 'Webshop', 'sort_order' => 156,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Var ser jag mina beställningar?',
             'content' => '<p>Alla dina beställningar finns under <a href="/installningar/ordrar">Mina beställningar</a>. Där visas orderstatus, betalningsstatus och kvitton. Klicka på en order för fullständiga detaljer.</p>'],

            ['slug' => 'shop-produkt-ej-tillganglig', 'group_label' => 'Webshop', 'sort_order' => 157,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Produkten är slutsåld eller borttagen',
             'content' => '<p>Om en produkt visar <em>Slutsåld</em> eller inte syns i shoppen längre är lagret tömt eller produkten avpublicerad. Kontakta föreningen om du är intresserad av att köpa produkten — de kan avgöra om återbeställning är möjlig.</p>'],

            ['slug' => 'shop-rabatt-typer', 'group_label' => 'Webshop', 'sort_order' => 158,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Typer av rabatter',
             'content' => '<p>Systemet stöder flera rabatttyper:</p><ul><li><strong>Medlemsrabatt</strong> — automatisk, syns som reducerat pris för inloggade aktiva medlemmar.</li><li><strong>Rabattkod</strong> — ange koden i kassan för att aktivera rabatten.</li><li><strong>Kampanjrabatt</strong> — automatisk tidsbegränsad rabatt, ingen kod behövs.</li></ul>'],

            ['slug' => 'shop-kvitto', 'group_label' => 'Webshop', 'sort_order' => 159,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Kvitto och orderbekräftelse',
             'content' => '<p>Orderbekräftelsen skickas till din e-post direkt efter genomförd betalning. Den innehåller vad du köpt, belopp och betalningsmetod. Du hittar alla ordrar under <a href="/installningar/ordrar">Mina beställningar</a>.</p><p>Behöver du ett kvitto med föreningens organisationsnummer? Kontakta styrelsen.</p>'],

            ['slug' => 'shop-betalning-steg', 'group_label' => 'Webshop', 'sort_order' => 160,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Kassan steg för steg',
             'content' => '<ol><li>Lägg produkter i varukorgen och klicka <strong>Till kassan</strong>.</li><li>Verifiera innehållet och eventuell rabattkod.</li><li>Välj betalningsmetod (kort/Swish/bankgiro).</li><li>Genomför betalningen enligt vald metods instruktioner.</li><li>Du omdirigeras till en bekräftelsesida och får ett mail.</li></ol>'],

            // ── Uthyrning (add 8, sort 166-173) ──────────────────────────────
            ['slug' => 'uthyrning-hur-fungerar', 'group_label' => 'Uthyrning', 'sort_order' => 166,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Hur fungerar uthyrningssystemet?',
             'content' => '<p>Föreningen hyr ut utrustning (spelbord, figurer, material m.m.) till sina medlemmar på längre perioder — typiskt per år. Du ansöker om att hyra en artikel, betalar hyresavgiften och ansvarar för artikeln under hyresperioden.</p><p>Se tillgängliga artiklar under <a href="/uthyrning">Uthyrning</a>.</p>'],

            ['slug' => 'uthyrning-pris', 'group_label' => 'Uthyrning', 'sort_order' => 167,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Hur beräknas hyrespriset?',
             'content' => '<p>Hyran anges per år. Priset visas på artikelsidan. Hyresavgiften betalas i förskott och faktureras som en order i systemet — du kan betala med kort, Swish eller bankgiro.</p>'],

            ['slug' => 'uthyrning-forlangning', 'group_label' => 'Uthyrning', 'sort_order' => 168,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Förlänga en hyra',
             'content' => '<p>Vill du förlänga din hyra för ytterligare en period? Kontakta styrelsen innan din nuvarande hyresperiod löper ut. Systemet hanterar inte automatisk förnyelse — du måste aktivt ansöka om förlängning.</p>'],

            ['slug' => 'uthyrning-aterlamnande', 'group_label' => 'Uthyrning', 'sort_order' => 169,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Lämna tillbaka hyrad artikel',
             'content' => '<p>Kontakta styrelsen när du vill avsluta en hyra. Artikeln lämnas tillbaka vid en överenskommen tidpunkt. Säkerställ att artikeln är i samma skick som när du tog emot den — se <a href="/hjalp/uthyrning#uthyrning-skada">Skada på hyrd artikel</a> om något hänt.</p>'],

            ['slug' => 'uthyrning-max-antal', 'group_label' => 'Uthyrning', 'sort_order' => 170,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Hur många artiklar kan jag hyra?',
             'content' => '<p>Maximalt antal aktiva hyror per person styrs av systemets inställningar. Om gränsen är nådd visas detta när du försöker hyra ytterligare. Kontakta styrelsen om du har speciella behov.</p>'],

            ['slug' => 'uthyrning-intresselista-hur-fungerar', 'group_label' => 'Uthyrning', 'sort_order' => 171,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Hur fungerar intresselistan?',
             'content' => '<p>Om en artikel är fullt uthyrd kan du ställa dig på intresselista. När artikeln blir tillgänglig kontaktas de på listan i turordning. Notera att intresselistan inte är bindande — du kan tacka nej om du inte längre är intresserad.</p>'],

            ['slug' => 'uthyrning-prioritet', 'group_label' => 'Uthyrning', 'sort_order' => 172,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Vem får hyra om flera söker?',
             'content' => '<p>Om fler söker än vad som finns tillgängligt gäller intresselistan i anmälningsordning. Aktiva medlemmar ges generellt förtur framför icke-medlemmar. Styrelsen fattar slutgiltigt beslut vid tvister.</p>'],

            ['slug' => 'uthyrning-kvitto', 'group_label' => 'Uthyrning', 'sort_order' => 173,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Hyreskvitto',
             'content' => '<p>När din hyra är registrerad och betalad hittar du kvittot under <a href="/installningar/ordrar">Mina beställningar</a>. Kvittot innehåller artikelnamn, hyresperiod och betalat belopp.</p>'],

            // ── Lokalbokningar (add 8, sort 179-186) ─────────────────────────
            ['slug' => 'lokalbokning-hur-fungerar', 'group_label' => 'Lokalbokningar', 'sort_order' => 179,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Hur fungerar lokalbokningssystemet?',
             'content' => '<p>Föreningens lokaler kan bokas för möten, spelkvällar och arrangemang. Du väljer lokal, datum och tid. Bokningar kan kräva godkännande av administratör beroende på lokaltyp och arrangemangstyp.</p><p>Se tillgängliga lokaler under <a href="/lokalbokningar">Lokalbokningar</a>.</p>'],

            ['slug' => 'lokalbokning-tider', 'group_label' => 'Lokalbokningar', 'sort_order' => 180,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Tillgängliga tider',
             'content' => '<p>Lokalbokningskalendern visar vilka tider som är lediga. Röda tidsluckor är bokade. Gröna är tillgängliga. Grå kan vara blockerade av administratör (t.ex. städning eller underhåll).</p><p>Du kan inte boka tider som kolliderar med en befintlig bokning.</p>'],

            ['slug' => 'lokalbokning-avboka', 'group_label' => 'Lokalbokningar', 'sort_order' => 181,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Avboka en lokal',
             'content' => '<p>Hitta din bokning under <a href="/mina-bokningar">Mina bokningar</a> och klicka <strong>Avboka</strong>. Avbokning bör göras minst 24 timmar i förväg. Sena avbokningar vid återkommande problem kan leda till tillfällig bokningsspärr.</p>'],

            ['slug' => 'lokalbokning-utrustning', 'group_label' => 'Lokalbokningar', 'sort_order' => 182,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Tillgänglig utrustning i lokalerna',
             'content' => '<p>Vilken utrustning som finns i respektive lokal (bord, stolar, projektor, whiteboard m.m.) beskrivs i lokalbeskrivningen. Behöver du specialutrustning som inte finns på plats? Kontakta styrelsen i förväg.</p>'],

            ['slug' => 'lokalbokning-priser', 'group_label' => 'Lokalbokningar', 'sort_order' => 183,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Kostar det något att boka lokal?',
             'content' => '<p>Interna föreningsaktiviteter (spelmöten, sektionsträffar) är vanligtvis kostnadsfria för aktiva medlemmar. Externa arrangemang eller kommersiell användning kan ha en avgift. Se respektive lokals information för aktuella priser.</p>'],

            ['slug' => 'lokalbokning-gaster', 'group_label' => 'Lokalbokningar', 'sort_order' => 184,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Ta med gäster',
             'content' => '<p>Vid ett öppet arrangemang kan du ta med gäster. Ange förväntad deltagarantal i bokningsformuläret så att lokalen dimensioneras rätt. Bokaren ansvarar för att gästerna följer föreningens regler under evenemanget.</p>'],

            ['slug' => 'lokalbokning-ical-anvand', 'group_label' => 'Lokalbokningar', 'sort_order' => 185,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Prenumerera på bokningskalendern',
             'content' => '<p>Under <a href="/lokalbokningar">Lokalbokningar</a> finns en iCal-länk som du kan prenumerera på i Google Kalender, Outlook eller liknande. Kalendern uppdateras automatiskt när bokningar läggs till eller ändras.</p><p>Kopiera iCal-länken och lägg till den som en "abonnemangskalender" i din kalenderapp.</p>'],

            ['slug' => 'lokalbokning-overlap', 'group_label' => 'Lokalbokningar', 'sort_order' => 186,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Vad händer om det uppstår konflikt?',
             'content' => '<p>Systemet förhindrar dubbelbokningar — du kan inte boka en tid som redan är tagen. Om du ser en konflikt som inte borde finnas (t.ex. en bokning som borde ha avbokats), kontakta administratören via <a href="/kontakta-oss">kontaktformuläret</a>.</p>'],

            // ── Enkäter & formulär (add 8, sort 188-195) ─────────────────────
            ['slug' => 'enkaat-vad-ar', 'group_label' => 'Enkäter & formulär', 'sort_order' => 188,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Vad är en enkät?',
             'content' => '<p>En enkät är ett frågeformulär som föreningen skickar ut för att samla in åsikter eller information — t.ex. nöjdhetsmätning efter ett evenemang eller planering inför en ny aktivitet. Du bjuds in via e-post med en länk till enkäten.</p>'],

            ['slug' => 'enkaat-obligatorisk', 'group_label' => 'Enkäter & formulär', 'sort_order' => 189,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Obligatoriska frågor',
             'content' => '<p>Frågor markerade med en asterisk (*) är obligatoriska och måste besvaras för att skicka in enkäten. Valfria frågor kan lämnas tomma.</p>'],

            ['slug' => 'enkaat-fragortyper', 'group_label' => 'Enkäter & formulär', 'sort_order' => 190,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Typer av frågor',
             'content' => '<p>Enkäter kan innehålla: fritext, val (en svar), flerval (flera svar), skala (1–5 eller liknande), ja/nej och datumfrågor. Formulär kan dessutom innehålla produktval, sektionsval och anmälan till evenemang direkt i formuläret.</p>'],

            ['slug' => 'formular-produkt-varukorg', 'group_label' => 'Enkäter & formulär', 'sort_order' => 191,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Produktval i formulär',
             'content' => '<p>Vissa formulär innehåller ett produktval — t.ex. "Vill du beställa en t-shirt?". Om du väljer ja och skickar formuläret läggs produkten automatiskt i din varukorg. Du behöver sedan gå till <a href="/kassa">kassan</a> för att slutföra köpet och betala.</p>'],

            ['slug' => 'formular-sektionsval', 'group_label' => 'Enkäter & formulär', 'sort_order' => 192,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Sektionsval i formulär',
             'content' => '<p>Anmälningsformulär kan ha ett sektionsval — du väljer vilken eller vilka sektioner du vill gå med i. En sektionsansökan skapas automatiskt vid inskick. Statusen är <em>väntande</em> tills sektionsansvarig godkänner.</p>'],

            ['slug' => 'formular-spara-utkast', 'group_label' => 'Enkäter & formulär', 'sort_order' => 193,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Kan jag spara och fortsätta senare?',
             'content' => '<p>Nej, formulär och enkäter stöder inte att spara utkast. Fyll i hela formuläret i ett svep innan du skickar. Lämnar du sidan utan att skicka försvinner dina svar. Om formuläret är långt rekommenderar vi att du förbereder dina svar i förväg.</p>'],

            ['slug' => 'enkaat-dela', 'group_label' => 'Enkäter & formulär', 'sort_order' => 194,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Dela en enkätlänk',
             'content' => '<p>Enkätlänken i din e-post är personlig. Dela den inte med andra — varje svar registreras mot ditt konto. Vill du att en annan person svarar? Be administratören skicka en separat inbjudan till dem.</p>'],

            ['slug' => 'formular-skickat', 'group_label' => 'Enkäter & formulär', 'sort_order' => 195,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Bekräftelse efter inskick',
             'content' => '<p>När du skickat in ett formulär eller en enkät visas en bekräftelsesida direkt i webbläsaren. Du får normalt inget bekräftelsemail för enkätsvar (om inte arrangören aktiverat det). Vill du se vad du svarat? Kontakta arrangören.</p>'],

            // ── Utlägg (add 6, sort 196-201) ─────────────────────────────────
            ['slug' => 'utlagg-vad-kvalificerar', 'group_label' => 'Utlägg', 'sort_order' => 196,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Vad räknas som ett utlägg?',
             'content' => '<p>Utlägg är kostnader du betalat ur egen ficka för föreningens räkning — t.ex. material till ett evenemang, porto eller förbrukningsvaror. Privata kostnader eller inköp som inte godkänts i förväg av styrelsen ersätts vanligtvis inte.</p><p>Är du osäker? Stäm av med styrelsen innan du gör inköpet.</p>'],

            ['slug' => 'utlagg-kvitto-krav', 'group_label' => 'Utlägg', 'sort_order' => 197,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Krav på kvitto',
             'content' => '<p>Alla utlägg kräver ett kvitto eller en faktura som underlag. Godkända format:</p><ul><li>Foto av papperskvitto (tydlig, rak, hela kvittot synligt)</li><li>PDF-kvitto från e-handel</li><li>Skärmdump av digital betalning med belopp och mottagare</li></ul><p>Kvittot ska visa datum, belopp, säljare och vad som köpts.</p>'],

            ['slug' => 'utlagg-moms', 'group_label' => 'Utlägg', 'sort_order' => 198,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Moms på utlägg',
             'content' => '<p>Ange det faktiska beloppet du betalat (inkl. moms) i utläggsformuläret. Ange eventuell momssats separat om du ser det fältet — bokföringsansvarig hanterar momsavdraget i redovisningen. Är du osäker, lämna momsrutan tom.</p>'],

            ['slug' => 'utlagg-flera-kvitton', 'group_label' => 'Utlägg', 'sort_order' => 199,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Flera kvitton i ett utlägg',
             'content' => '<p>Har du flera kvitton från samma inköpsresa? Du kan ladda upp flera filer till ett och samma utlägg. Lägg alla bilagor till en och samma utläggspost — det är enklare att hantera för bokföringsansvarig.</p>'],

            ['slug' => 'utlagg-belopp', 'group_label' => 'Utlägg', 'sort_order' => 200,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Finns det en beloppsgräns?',
             'content' => '<p>Det finns ingen hård beloppsgräns i systemet, men stora inköp (typiskt över 500 kr) bör vara förankrade med styrelsen i förväg. Utlägg som godkänns betalas ut till ditt registrerade bankkonto.</p>'],

            ['slug' => 'utlagg-status-folj-upp', 'group_label' => 'Utlägg', 'sort_order' => 201,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Följa upp status på utlägg',
             'content' => '<p>Under <a href="/utlagg">Mina utlägg</a> visas status för varje utlägg du skickat in:</p><ul><li><strong>Skickat</strong> — väntar på granskning</li><li><strong>Godkänt</strong> — utbetalning initieras</li><li><strong>Avvisat</strong> — se kommentaren för anledning</li></ul><p>Handläggningstid är normalt 5–10 arbetsdagar.</p>'],

            // ── Org-roller & möten (add 7, sort 203-209) ─────────────────────
            ['slug' => 'org-roll-vad-ar', 'group_label' => 'Org-roller & möten', 'sort_order' => 203,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Vad är en organisationsroll?',
             'content' => '<p>En organisationsroll är ett formellt uppdrag inom föreningen — t.ex. ordförande, kassör, revisor eller sektionsordförande. Roller är tidsbegränsade till en period (t.ex. ett verksamhetsår) och tilldelas av styrelsen.</p>'],

            ['slug' => 'org-roll-period', 'group_label' => 'Org-roller & möten', 'sort_order' => 204,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Valperioder och mandattid',
             'content' => '<p>Organisationsroller gäller under en definierad period (t.ex. kalenderår eller verksamhetsår). Du kan ha haft flera roller under olika perioder. Under <a href="/mina-uppdrag">Mina uppdrag</a> ser du nuvarande och historiska roller.</p>'],

            ['slug' => 'org-roll-behorigheter', 'group_label' => 'Org-roller & möten', 'sort_order' => 205,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Behörigheter kopplade till roller',
             'content' => '<p>Vissa roller ger automatiskt behörighet till administrativa funktioner. T.ex. ger kassörsrollen tillgång till bokföringsmodulen och revisorsrollen tillgång till räkenskapsvy. Behörigheterna uppdateras dagligen av systemet.</p>'],

            ['slug' => 'moten-dagordning', 'group_label' => 'Org-roller & möten', 'sort_order' => 206,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Dagordning och protokoll',
             'content' => '<p>Inför möten publiceras dagordningspunkter i systemet. Protokollet skrivs och publiceras efter mötet. Du hittar alla mötesdokument under <a href="/moten">Möten</a>. Bilagor till protokollet kan bifogas direkt i systemet.</p>'],

            ['slug' => 'omrostning-hur-roster', 'group_label' => 'Org-roller & möten', 'sort_order' => 207,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Rösta i en omröstning',
             'content' => '<p>Öppna omröstningar visas på din startsida och under <a href="/moten">Möten</a>. Klicka på omröstningen, välj ditt alternativ och bekräfta. Du kan bara rösta en gång per omröstning. Resultatet visas när omröstningen stängs.</p>'],

            ['slug' => 'omrostning-typer', 'group_label' => 'Org-roller & möten', 'sort_order' => 208,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Typer av omröstningar',
             'content' => '<p>Systemet stöder öppna omröstningar (alla röster syns för admin) och anonyma omröstningar (bara aggregerade resultat sparas). Vilken typ som används framgår av omröstningsformuläret.</p>'],

            ['slug' => 'org-roll-historik', 'group_label' => 'Org-roller & möten', 'sort_order' => 209,
             'show_public' => false, 'show_auth' => true, 'required_permission' => null,
             'title' => 'Historik över mina uppdrag',
             'content' => '<p>Under <a href="/mina-uppdrag">Mina uppdrag</a> ser du alla roller du haft — nuvarande och avslutade — med period och titel. Historiken används bl.a. vid revision och för att styrka deltagande i föreningsarbete.</p>'],

            // ── Admin — Medlemmar (add 12, sort 332-343) ─────────────────────
            ['slug' => 'admin-member-export', 'group_label' => 'Administratör — Medlemmar', 'sort_order' => 332,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'members.view',
             'title' => 'Exportera medlemsregister',
             'content' => '<p>Under <a href="/admin/medlemmar">Admin → Medlemmar</a> finns en exportknapp som genererar en CSV med namn, e-post, telefon, sektioner och betalningsstatus. Filen lämpar sig för e-postlistor och rapport till bidragsmyndigheter.</p>'],

            ['slug' => 'admin-familj-administrera', 'group_label' => 'Administratör — Medlemmar', 'sort_order' => 333,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'members.manage',
             'title' => 'Administrera familjegrupper',
             'content' => '<p>Familjegrupper hanteras under <a href="/admin/familjer">Admin → Familjer</a>. Du kan skapa ny grupp, lägga till och ta bort medlemmar, byta familjeadministratör och se alla aktiva grupper.</p><p>Tänk på: ett barn som läggs till i en grupp kräver antingen eget konto eller kopplas till familjens konto (has_own_account=false).</p>'],

            ['slug' => 'admin-rfid-tilldela', 'group_label' => 'Administratör — Medlemmar', 'sort_order' => 334,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'members.manage',
             'title' => 'Tilldela RFID-kort',
             'content' => '<p>Öppna medlemmens profilsida under Admin → Medlemmar och rulla till <em>RFID-kort</em>. Klicka <strong>Lägg till kort</strong> och ange kortets UID (tryckt på kortet eller avläst med kortläsare). Kortet aktiveras omedelbart och synkas mot Paxton-systemet vid nästa synkning.</p>'],

            ['slug' => 'admin-rfid-sparra', 'group_label' => 'Administratör — Medlemmar', 'sort_order' => 335,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'members.manage',
             'title' => 'Spärra eller ta bort RFID-kort',
             'content' => '<p>På medlemmens profilsida under <em>RFID-kort</em>: klicka på kortet och välj <strong>Inaktivera</strong> för att tillfälligt spärra eller <strong>Ta bort</strong> för att radera. Ändringen synkas mot Paxton vid nästa synkningskörning (vanligtvis var 15:e minut).</p>'],

            ['slug' => 'admin-avgift-manuell', 'group_label' => 'Administratör — Medlemmar', 'sort_order' => 336,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'fees.manage',
             'title' => 'Registrera manuell betalning',
             'content' => '<p>Om en medlem betalat kontant eller via bankgiro som inte matchats automatiskt: gå till Admin → Medlemmar → välj medlem → fliken <em>Avgifter</em> och klicka <strong>Registrera betalning manuellt</strong>. Ange belopp, datum och referens. Systemet skapar en verifikation i bokföringen.</p>'],

            ['slug' => 'admin-avgift-rabatt-tilldela', 'group_label' => 'Administratör — Medlemmar', 'sort_order' => 337,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'fees.manage',
             'title' => 'Ge rabatt till en specifik medlem',
             'content' => '<p>Skapa en rabattkod under <a href="/admin/rabatter">Admin → Rabatter</a> med <em>max_uses=1</em> och dela koden direkt med den aktuella medlemmen. Alternativt: skapa en rabatt med villkor som passar (t.ex. "förstamedlemskap") och kontrollera att den appliceras automatiskt.</p>'],

            ['slug' => 'admin-sektion-flytta-medlem', 'group_label' => 'Administratör — Medlemmar', 'sort_order' => 338,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'sections.manage',
             'title' => 'Flytta eller ta bort en sektion för en medlem',
             'content' => '<p>Under Admin → Medlemmar → välj medlem → fliken <em>Sektioner</em> kan du lägga till, godkänna ansökningar eller avsluta ett sektionsmedlemskap. Att avsluta sätter <em>left_at</em> till dagens datum — historiken bevaras.</p>'],

            ['slug' => 'admin-sektion-roller-hantera', 'group_label' => 'Administratör — Medlemmar', 'sort_order' => 339,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'sections.manage',
             'title' => 'Hantera sektionsroller',
             'content' => '<p>Sektionsroller (t.ex. sektionsordförande) tilldelas under Admin → Sektioner → välj sektion → <em>Roller</em>. Roller är tidsbegränsade med start- och slutdatum. En person kan ha olika roller i olika sektioner.</p>'],

            ['slug' => 'admin-orgroll-ny-period', 'group_label' => 'Administratör — Medlemmar', 'sort_order' => 340,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'org_roles.manage',
             'title' => 'Skapa ny rollperiod',
             'content' => '<p>Under <a href="/admin/org-roller">Admin → Org-roller</a>: välj roll och klicka <strong>Ny period</strong>. Ange vem som har rollen, startdatum och förväntat slutdatum. Perioden börjar som <em>under uppbyggnad</em> tills den aktiveras manuellt.</p>'],

            ['slug' => 'admin-orgroll-avsluta', 'group_label' => 'Administratör — Medlemmar', 'sort_order' => 341,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'org_roles.manage',
             'title' => 'Avsluta en rollperiod',
             'content' => '<p>Öppna rollperioden under Admin → Org-roller och klicka <strong>Avsluta period</strong>. Ange slutdatum (active_end). Personen behåller eventuella behörigheter under en grace period på 6 månader (konfigurerbar). Systemet synkar behörigheter automatiskt varje natt.</p>'],

            ['slug' => 'admin-avgift-fornya-batch', 'group_label' => 'Administratör — Medlemmar', 'sort_order' => 342,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'fees.manage',
             'title' => 'Massfornyelseprocess',
             'content' => '<p>Körningen <code>php artisan fees:renew</code> genererar förnyelsefakturor för alla aktiva medlemmar vars avgift löper ut. Körningen schemaläggs vanligtvis i januari. Du kan köra den manuellt från Admin → System → Artisan-kommandon om du vill kontrollera utfallet först med flaggan <code>--dry-run</code>.</p>'],

            ['slug' => 'admin-member-sok-filter', 'group_label' => 'Administratör — Medlemmar', 'sort_order' => 343,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'members.view',
             'title' => 'Söka och filtrera i medlemsregistret',
             'content' => '<p>I Admin → Medlemmar kan du söka på namn, e-post eller medlemsnummer. Filter finns för status (aktiv/inaktiv/väntande), sektion och betalningsstatus. Kombinera filter för att t.ex. hitta alla obetalda aktiva i en specifik sektion.</p>'],

            // ── Admin — Ekonomi (add 15, sort 451-465) ───────────────────────
            ['slug' => 'admin-ekonomi-oversikt', 'group_label' => 'Administratör — Ekonomi', 'sort_order' => 451,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.view',
             'title' => 'Ekonomimodulen — översikt',
             'content' => '<p>Ekonomimodulen hanterar: inkommande betalningar (Stripe, Swish, bankgiro), kundfakturor, leverantörsfakturor, manuella verifikationer, bankavstämning och SIE4-export. Alla belopp lagras i öre (svenska ören).</p><p>Navigera via <a href="/admin/bokforing">Admin → Bokföring</a>.</p>'],

            ['slug' => 'admin-leverantorfaktura-skapa', 'group_label' => 'Administratör — Ekonomi', 'sort_order' => 452,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
             'title' => 'Skapa leverantörsfaktura',
             'content' => '<p>Under Admin → Bokföring → Leverantörsfakturor: klicka <strong>Ny faktura</strong>. Ange leverantör, fakturadatum, förfallodatum, belopp och kontering. Ladda upp faktura-PDF som bilaga. Spara som utkast eller skicka direkt för godkännande.</p>'],

            ['slug' => 'admin-leverantorfaktura-betala', 'group_label' => 'Administratör — Ekonomi', 'sort_order' => 453,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
             'title' => 'Betala en leverantörsfaktura',
             'content' => '<p>Godkänd faktura får status <em>Godkänd</em>. Registrera betalning manuellt under fakturans detaljer efter att utbetalning skett via bankgiro/bankens portal. Ange betalningsdatum och referens — systemet skapar en matchande verifikation.</p>'],

            ['slug' => 'admin-kundfaktura-skapa', 'group_label' => 'Administratör — Ekonomi', 'sort_order' => 454,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
             'title' => 'Skapa kundfaktura',
             'content' => '<p>Under Admin → Bokföring → Kundfakturor: klicka <strong>Ny faktura</strong>. Välj kund (befintlig eller ange ny), lägg till fakturaraderna med belopp och beskrivning, ange förfallodatum. Systemet genererar ett unikt OCR-nummer för bankgiro-betalning.</p>'],

            ['slug' => 'admin-kundfaktura-skicka', 'group_label' => 'Administratör — Ekonomi', 'sort_order' => 455,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
             'title' => 'Skicka kundfaktura',
             'content' => '<p>Under kundfakturans detaljsida: klicka <strong>Skicka</strong>. Fakturan mejlas som PDF till kundens e-postadress. Systemet loggar att fakturan skickats och uppdaterar status. Du kan skicka om om mottagaren inte hittar originalet.</p>'],

            ['slug' => 'admin-kundfaktura-paminnelse', 'group_label' => 'Administratör — Ekonomi', 'sort_order' => 456,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
             'title' => 'Skicka fakturapåminnelse',
             'content' => '<p>Om en faktura är obetald efter förfallodatum: öppna fakturan och klicka <strong>Skicka påminnelse</strong>. En påminnelse mejlas till kunden med ny betalningsinformation. Notera att systemet inte skickar påminnelser automatiskt — det sker manuellt.</p>'],

            ['slug' => 'admin-bankavstamning-process', 'group_label' => 'Administratör — Ekonomi', 'sort_order' => 457,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
             'title' => 'Bankavstämning steg för steg',
             'content' => '<ol><li>Ladda ner kontoutdrag i ISO 20022-format från din bank.</li><li>Importera filen via Admin → Bokföring → Bankimport.</li><li>Systemet matchar automatiskt bankposter mot öppna kundfakturor och väntande ordrar via OCR-nummer.</li><li>Omatchade poster listas för manuell granskning — koppla dem till rätt verifikation eller skapa ny.</li><li>Markera avstämningen som klar när alla poster är hanterade.</li></ol>'],

            ['slug' => 'admin-ocr-matchning', 'group_label' => 'Administratör — Ekonomi', 'sort_order' => 458,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
             'title' => 'OCR-matchning vid bankimport',
             'content' => '<p>Systemet söker OCR-numret i inbetalningens meddelandefält och matchar det mot öppna kundfakturor. Hittas en exakt träff kvitteras betalningen automatiskt och en verifikation skapas. Träffar kräver exakt OCR — om kunden angett fel OCR hamnar posten i listan för manuell hantering.</p>'],

            ['slug' => 'admin-kontoplan-ny-konto', 'group_label' => 'Administratör — Ekonomi', 'sort_order' => 459,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
             'title' => 'Lägga till ett nytt konto i kontoplanen',
             'content' => '<p>Under Admin → Bokföring → Kontoplan: klicka <strong>Nytt konto</strong>. Ange kontonummer (BAS-standard rekommenderas), namn, kontotyp (tillgång/skuld/intäkt/kostnad) och eventuell momskod. Kontot är direkt tillgängligt för kontering i verifikationer.</p>'],

            ['slug' => 'admin-utlagg-godkanna', 'group_label' => 'Administratör — Ekonomi', 'sort_order' => 460,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
             'title' => 'Granska och godkänna utlägg',
             'content' => '<p>Under <a href="/admin/utlagg">Admin → Utlägg</a> listas alla inkomna utlägg. Klicka på ett utlägg för att se detaljer och bifogat kvitto. Välj <strong>Godkänn</strong> (med kontering) eller <strong>Avvisa</strong> (med kommentar till sökanden). Godkända utlägg genererar en verifikation.</p>'],

            ['slug' => 'admin-utlagg-bokfora', 'group_label' => 'Administratör — Ekonomi', 'sort_order' => 461,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
             'title' => 'Bokföra ett godkänt utlägg',
             'content' => '<p>Godkänn utlägget med rätt debitkonto (kostnadskonto) och kreditkonto (leverantörsskuld/utbetalt). Systemet skapar verifikationen automatiskt. Utbetalningen registreras separat när du faktiskt betalt ut till den anställde/volontären.</p>'],

            ['slug' => 'admin-faktura-matchning', 'group_label' => 'Administratör — Ekonomi', 'sort_order' => 462,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.manage',
             'title' => 'Matcha faktura mot betalning',
             'content' => '<p>Om en bankpost importerats utan att matcha automatiskt: öppna bankposten under omatchade poster, klicka <strong>Koppla till faktura</strong> och sök fram rätt faktura. Bekräfta kopplingen — systemet kvitterar fakturan och skapar verifikationen.</p>'],

            ['slug' => 'admin-arsredovisning', 'group_label' => 'Administratör — Ekonomi', 'sort_order' => 463,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.view',
             'title' => 'Stänga räkenskapsåret',
             'content' => '<p>Räkenskapsåret stängs under Admin → Bokföring → Räkenskapsår. Stegen är: säkerställ att alla verifikationer är bokförda, exportera SIE4-filen för revision, skapa nytt räkenskapsår och överför ingående balans. Kontakta revisor om du är osäker på processen.</p>'],

            ['slug' => 'admin-momsredovisning', 'group_label' => 'Administratör — Ekonomi', 'sort_order' => 464,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.view',
             'title' => 'Momsredovisning',
             'content' => '<p>Ideella föreningar är ofta inte momspliktiga, men om föreningen bedriver momspliktig verksamhet (t.ex. kommersiell uthyrning) exporteras momsrapporten via SIE4-filen. Kontakta din revisor eller Skatteverket för vägledning om föreningens momsstatus.</p>'],

            ['slug' => 'admin-perioder-bokforing', 'group_label' => 'Administratör — Ekonomi', 'sort_order' => 465,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'accounting.view',
             'title' => 'Bokföringsperioder',
             'content' => '<p>Varje verifikation kopplas till ett räkenskapsår och en period (månad). Verifikationer i stängda perioder kan inte ändras — skapa en korrigeringsverifikation om du hittar ett fel. Under Admin → Bokföring → Räkenskapsår ser du vilka perioder som är öppna eller stängda.</p>'],

            // ── Admin — Evenemang (add 10, sort 514-523) ─────────────────────
            ['slug' => 'admin-event-nytt', 'group_label' => 'Administratör — Evenemang', 'sort_order' => 514,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'events.manage',
             'title' => 'Skapa nytt evenemang steg för steg',
             'content' => '<ol><li>Gå till <a href="/admin/evenemang">Admin → Evenemang</a> och klicka <strong>Nytt evenemang</strong>.</li><li>Fyll i titel, beskrivning, datum/tid och plats.</li><li>Ange kapacitet och om evenemanget är öppet för allmänheten eller bara medlemmar.</li><li>Lägg till prisnivåer och eventuella tillägg.</li><li>Publicera när allt är klart — evenemanget blir synligt och anmälan öppnar.</li></ol>'],

            ['slug' => 'admin-event-publicera', 'group_label' => 'Administratör — Evenemang', 'sort_order' => 515,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'events.manage',
             'title' => 'Publicera och avpublicera evenemang',
             'content' => '<p>Opublicerade evenemang är bara synliga för admin. Klicka <strong>Publicera</strong> när du är redo att öppna anmälan. Du kan pausa anmälan (avpublicera) utan att avboka befintliga anmälningar — deltagarna ser fortfarande evenemanget i sin lista.</p>'],

            ['slug' => 'admin-event-kopia', 'group_label' => 'Administratör — Evenemang', 'sort_order' => 516,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'events.manage',
             'title' => 'Kopiera ett evenemang',
             'content' => '<p>Planerar du ett återkommande event? Öppna det ursprungliga evenemanget och klicka <strong>Kopiera</strong>. Alla inställningar (prisnivåer, tillägg, beskrivning) kopieras — ändra datum och publicera. Befintliga anmälningar kopieras inte.</p>'],

            ['slug' => 'admin-event-kapacitet', 'group_label' => 'Administratör — Evenemang', 'sort_order' => 517,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'events.manage',
             'title' => 'Hantera kapacitet',
             'content' => '<p>Kapaciteten sätts per evenemang. Är kapaciteten nådd stängs ny anmälan och en väntelista aktiveras (om den är konfigurerad). Du kan höja kapaciteten när som helst — systemet kontaktar automatiskt nästa person på väntelistan.</p>'],

            ['slug' => 'admin-event-forhandskrav-lagg', 'group_label' => 'Administratör — Evenemang', 'sort_order' => 518,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'events.manage',
             'title' => 'Lägga till förhandskrav',
             'content' => '<p>Under evenemangsinställningarna → <em>Förhandskrav</em>: välj vilket krav som ska gälla — t.ex. "aktivt medlemskap" eller "deltog i event X". Kraven kontrolleras automatiskt vid anmälan. Du kan manuellt kringgå krav för enskilda deltagare.</p>'],

            ['slug' => 'admin-event-statistik', 'group_label' => 'Administratör — Evenemang', 'sort_order' => 519,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'events.manage',
             'title' => 'Eventstatistik',
             'content' => '<p>Under evenemangssidan visas live-statistik: antal anmälda, betalda, obetalda och incheckade. Du hittar även historik under <a href="/admin/statistik">Admin → Statistik</a> med aggregerade siffror per evenemangskategori och tidsperiod.</p>'],

            ['slug' => 'admin-event-deltagarlista', 'group_label' => 'Administratör — Evenemang', 'sort_order' => 520,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'events.manage',
             'title' => 'Deltagarlista',
             'content' => '<p>Under evenemangssidan → fliken <em>Deltagare</em> ser du alla anmälda med status, vald prisnivå och tillägg. Härifrån kan du checka in manuellt, avboka enskilda deltagare eller exportera listan som CSV.</p>'],

            ['slug' => 'admin-event-betalningsstatus', 'group_label' => 'Administratör — Evenemang', 'sort_order' => 521,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'events.manage',
             'title' => 'Betalningsstatus per deltagare',
             'content' => '<p>I deltagarlistan visas varje deltagares betalningsstatus: <em>Betald</em>, <em>Väntar på betalning</em> eller <em>Gratis</em>. Klicka på en deltagare för att se detaljer och manuellt registrera betalning om det behövs.</p>'],

            ['slug' => 'admin-event-skicka-mejl', 'group_label' => 'Administratör — Evenemang', 'sort_order' => 522,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'events.manage',
             'title' => 'Maila evenemangets deltagare',
             'content' => '<p>Gå till Admin → Utskick och skapa ett nytt utskick med mottagargrupp <em>Deltagare i evenemang</em> och välj aktuellt evenemang. Skriv meddelandet och schemalägg eller skicka direkt. Avprenumererade deltagare exkluderas automatiskt.</p>'],

            ['slug' => 'admin-event-rfid-checkin', 'group_label' => 'Administratör — Evenemang', 'sort_order' => 523,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'events.manage',
             'title' => 'RFID-incheckning vid evenemang',
             'content' => '<p>Kiosken vid entrén kan konfigureras för ett specifikt evenemang. Deltagare håller sitt RFID-kort mot läsaren — systemet matchar mot anmälningslistan och checkar in automatiskt. Vid obetald anmälan ges ett varningsljud och checkIn nekas.</p><p>Konfigureras under Admin → Skanningsstation.</p>'],

            // ── Admin — Kommunikation (add 10, sort 622-631) ─────────────────
            ['slug' => 'admin-utskick-skapa-steg', 'group_label' => 'Administratör — Kommunikation', 'sort_order' => 622,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'mailings.manage',
             'title' => 'Skapa utskick steg för steg',
             'content' => '<ol><li>Gå till <a href="/admin/utskick">Admin → Utskick</a> och klicka <strong>Nytt utskick</strong>.</li><li>Välj ämne och skriv innehållet (stöder markdown och mallvariabler).</li><li>Välj mottagargrupp (alla aktiva, sektion, eventdeltagare m.fl.).</li><li>Skicka testmail till dig själv.</li><li>Schemalägg eller skicka direkt.</li></ol><p>Utskick bearbetas av cron-jobbet <code>mailings:process</code> var 5:e minut.</p>'],

            ['slug' => 'admin-utskick-test-mejl', 'group_label' => 'Administratör — Kommunikation', 'sort_order' => 623,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'mailings.manage',
             'title' => 'Skicka testmail',
             'content' => '<p>Innan du skickar ett utskick till alla mottagare: klicka <strong>Skicka test</strong> under utskickssidan. Testmailet skickas till din egen e-post med alla mallvariabler utvärderade mot ditt konto. Kontrollera layout, länkar och innehåll innan du publicerar.</p>'],

            ['slug' => 'admin-utskick-schemalagga', 'group_label' => 'Administratör — Kommunikation', 'sort_order' => 624,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'mailings.manage',
             'title' => 'Schemalägga ett utskick',
             'content' => '<p>Välj <em>Schemalägg</em> istället för <em>Skicka nu</em> och ange datum och tid. Utskicket aktiveras automatiskt vid den tidpunkten och bearbetas av cron-jobbet. Tänk på att cron körs var 5:e minut — exakt tidpunkt kan variera med upp till 5 minuter.</p>'],

            ['slug' => 'admin-utskick-statistik', 'group_label' => 'Administratör — Kommunikation', 'sort_order' => 625,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'mailings.manage',
             'title' => 'Utskicksstatistik',
             'content' => '<p>Under ett skickat utskick visas: antal mottagare, levererade, öppnade och avprenumererade. Klickspårning på länkar är tillgänglig om det är aktiverat i inställningarna. Använd statistiken för att optimera framtida utskick.</p>'],

            ['slug' => 'admin-mailmall-variabler', 'group_label' => 'Administratör — Kommunikation', 'sort_order' => 626,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'mailings.manage',
             'title' => 'Variabler i mailmallar',
             'content' => '<p>Mallvariabler infogas med dubbla klammerparenteser, t.ex. <code>{{ member_name }}</code>. Tillgängliga variabler per malltyp visas i mallredigeraren. Vanliga variabler:</p><ul><li><code>{{ member_name }}</code> — mottagarens förnamn</li><li><code>{{ org_name }}</code> — föreningens namn</li><li><code>{{ event_title }}</code> — evenemangets titel (event-mallar)</li><li><code>{{ action_url }}</code> — länk till relevant sida</li></ul>'],

            ['slug' => 'admin-enkaat-skapa', 'group_label' => 'Administratör — Kommunikation', 'sort_order' => 627,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'surveys.manage',
             'title' => 'Skapa enkät',
             'content' => '<p>Under <a href="/admin/enkater">Admin → Enkäter</a>: klicka <strong>Ny enkät</strong>, ge den en titel och lägg till frågor. Varje fråga har en typ (fritext, val, skala etc.), en valfri kryssruta för obligatorisk och eventuella svarsalternativ.</p>'],

            ['slug' => 'admin-enkaat-publicera', 'group_label' => 'Administratör — Kommunikation', 'sort_order' => 628,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'surveys.manage',
             'title' => 'Publicera och dela enkät',
             'content' => '<p>Publicera enkäten när frågorna är klara. Du kan dela länken direkt eller skicka ut en inbjudan via utskicksfunktionen (välj mottagargrupp och välj enkäten). Enkäter kan vara anonyma eller kopplade till inloggat konto.</p>'],

            ['slug' => 'admin-enkaat-sammanstallning', 'group_label' => 'Administratör — Kommunikation', 'sort_order' => 629,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'surveys.manage',
             'title' => 'Se enkätresultat',
             'content' => '<p>Under enkätens sida → fliken <em>Svar</em> visas aggregerade resultat per fråga. För valfrågor visas stapeldiagram. Fritext-svar listas individuellt. Exportera alla svar som CSV under fliken <em>Exportera</em>.</p>'],

            ['slug' => 'admin-formular-nytt', 'group_label' => 'Administratör — Kommunikation', 'sort_order' => 630,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'forms.manage',
             'title' => 'Skapa nytt formulär',
             'content' => '<p>Under <a href="/admin/formular">Admin → Formulär</a>: klicka <strong>Nytt formulär</strong>. Formulärbyggaren stöder 13 fälttyper inkl. produktval (lägger i varukorg), sektionsval, evenemangslänkning och uthyrnings-intresselista. Formulär länkas från CMS-sidor eller skickas direkt.</p>'],

            ['slug' => 'admin-kontaktmeddelanden-hantera', 'group_label' => 'Administratör — Kommunikation', 'sort_order' => 631,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'mailings.manage',
             'title' => 'Hantera kontaktmeddelanden',
             'content' => '<p>Kontaktformuläret på sajten skickar meddelanden till <a href="/admin/kontaktmeddelanden">Admin → Kontaktmeddelanden</a>. Markera som hanterat när du svarat. Du kan svara direkt via e-post till avsändaren — svaret loggas inte i systemet.</p>'],

            // ── Admin — System (add 8, sort 792-799) ─────────────────────────
            ['slug' => 'admin-cms-sida-ny', 'group_label' => 'Administratör — System', 'sort_order' => 792,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'pages.manage',
             'title' => 'Skapa ny CMS-sida',
             'content' => '<p>Under Admin → Sidor: klicka <strong>Ny sida</strong>. Ange titel, slug (URL-del), innehåll i Trix-editorn och om sidan kräver inloggning. Spara som utkast och förhandsgranska innan du publicerar. Publicerade sidor syns direkt på webben.</p>'],

            ['slug' => 'admin-cms-navigation-hantera', 'group_label' => 'Administratör — System', 'sort_order' => 793,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'pages.manage',
             'title' => 'Hantera navigationsträdet',
             'content' => '<p>Under Admin → Navigation kan du lägga till, redigera och sortera navigationslänkar. Navigationen är träd-baserad — dra och släpp för att ordna. Ändringar syns direkt i headern. Du kan länka till interna sidor eller externa URL:er.</p>'],

            ['slug' => 'admin-roller-granska', 'group_label' => 'Administratör — System', 'sort_order' => 794,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'roles.manage',
             'title' => 'Granska och justera roller',
             'content' => '<p>Under <a href="/admin/roller">Admin → Roller</a> ser du alla Spatie-roller med sina tilldelade behörigheter. Du kan lägga till behörigheter till en roll, men ta inte bort systembehörigheter utan att förstå konsekvenserna — det kan låsa ute administratörer.</p>'],

            ['slug' => 'admin-workspace-lagg-till', 'group_label' => 'Administratör — System', 'sort_order' => 795,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'workspace.manage',
             'title' => 'Skapa Google Workspace-konto för medlem',
             'content' => '<p>Under Admin → Google Workspace → välj medlem → klicka <strong>Skapa konto</strong>. Ange önskat användarnamn (förnamn.efternamn@armagedon.se). Systemet skapar kontot via Google Admin API och skickar välkomstmail med temporärt lösenord. Lösenordet måste bytas vid första inloggning.</p>'],

            ['slug' => 'admin-workspace-aterstall-losenord', 'group_label' => 'Administratör — System', 'sort_order' => 796,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'workspace.manage',
             'title' => 'Återställa Workspace-lösenord',
             'content' => '<p>Under Admin → Google Workspace → välj konto → klicka <strong>Återställ lösenord</strong>. Ett nytt temporärt lösenord genereras och mejlas till medlemmens alternativa e-postadress (registrerad i systemet, ej Workspace-adressen). Lösenordet måste bytas vid nästa inloggning.</p>'],

            ['slug' => 'admin-systeminstallningar-kategorier', 'group_label' => 'Administratör — System', 'sort_order' => 797,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'system.settings',
             'title' => 'Systeminstallningar — kategorier',
             'content' => '<p>Systeminställningarna är grupperade i kategorier:</p><ul><li><strong>family</strong> — max antal vuxna/barn per familj, maxålder för barn</li><li><strong>fees</strong> — avgiftsbelopp, grace period för bankgiro</li><li><strong>booking</strong> — bokningsgränser, godkännandeflöde</li><li><strong>rental</strong> — max antal aktiva hyror</li><li><strong>workspace</strong> — Google Workspace-domän och inställningar</li></ul>'],

            ['slug' => 'admin-behorigheter-granska', 'group_label' => 'Administratör — System', 'sort_order' => 798,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'roles.manage',
             'title' => 'Granska vilka behörigheter en användare har',
             'content' => '<p>På en användares profilsida under Admin → Medlemmar → fliken <em>Behörigheter</em> listas alla aktiva Spatie-behörigheter (direkt tilldelade och via roller). Använd detta för att felsöka om en person inte får tillgång till en funktion.</p>'],

            ['slug' => 'admin-cms-sida-publicera', 'group_label' => 'Administratör — System', 'sort_order' => 799,
             'show_public' => false, 'show_auth' => false, 'required_permission' => 'pages.manage',
             'title' => 'Publicera och avpublicera sidor',
             'content' => '<p>En CMS-sida kan ha status <em>Utkast</em> eller <em>Publicerad</em>. Publicering gör sidan synlig på den publika URL:en. Avpublicering tar bort den från webbplatsen men behåller innehållet i systemet — du kan publicera om när som helst.</p><p>Skyddade slug:ar (hem, om-oss, kontakt) kan inte tas bort men kan redigeras.</p>'],

        ];

        foreach ($sections as $data) {
            if (! DB::table('help_sections')->where('slug', $data['slug'])->exists()) {
                DB::table('help_sections')->insert(array_merge($data, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }

    public function down(): void
    {
        $slugs = [
            'om-foreningen','anvander-data','spel-och-aktiviteter','konto-utan-aktivt-medlemskap',
            'rapportera-fel','webblesare-stod','anvanda-mobil','cookies',
            'byta-epost','familjegrupp-oversikt','barn-konto','kiosk-inloggning',
            'notiser-epost','session-utloggning','mina-sektioner','inloggning-problem-google',
            'evenemang-hitta','evenemang-gratis','evenemang-bekraftelse-epost','evenemang-biljett-visa',
            'evenemang-checkin-process','evenemang-kvitto','evenemang-pris-skillnad',
            'evenemang-tillfallen','evenemang-paminnelse','evenemang-forhandskrav-missat',
            'shop-variantval','shop-betalning-bankgiro','shop-orderhistorik',
            'shop-produkt-ej-tillganglig','shop-rabatt-typer','shop-kvitto','shop-betalning-steg',
            'uthyrning-hur-fungerar','uthyrning-pris','uthyrning-forlangning','uthyrning-aterlamnande',
            'uthyrning-max-antal','uthyrning-intresselista-hur-fungerar','uthyrning-prioritet','uthyrning-kvitto',
            'lokalbokning-hur-fungerar','lokalbokning-tider','lokalbokning-avboka','lokalbokning-utrustning',
            'lokalbokning-priser','lokalbokning-gaster','lokalbokning-ical-anvand','lokalbokning-overlap',
            'enkaat-vad-ar','enkaat-obligatorisk','enkaat-fragortyper','formular-produkt-varukorg',
            'formular-sektionsval','formular-spara-utkast','enkaat-dela','formular-skickat',
            'utlagg-vad-kvalificerar','utlagg-kvitto-krav','utlagg-moms','utlagg-flera-kvitton',
            'utlagg-belopp','utlagg-status-folj-upp',
            'org-roll-vad-ar','org-roll-period','org-roll-behorigheter','moten-dagordning',
            'omrostning-hur-roster','omrostning-typer','org-roll-historik',
            'admin-member-export','admin-familj-administrera','admin-rfid-tilldela','admin-rfid-sparra',
            'admin-avgift-manuell','admin-avgift-rabatt-tilldela','admin-sektion-flytta-medlem',
            'admin-sektion-roller-hantera','admin-orgroll-ny-period','admin-orgroll-avsluta',
            'admin-avgift-fornya-batch','admin-member-sok-filter',
            'admin-ekonomi-oversikt','admin-leverantorfaktura-skapa','admin-leverantorfaktura-betala',
            'admin-kundfaktura-skapa','admin-kundfaktura-skicka','admin-kundfaktura-paminnelse',
            'admin-bankavstamning-process','admin-ocr-matchning','admin-kontoplan-ny-konto',
            'admin-utlagg-godkanna','admin-utlagg-bokfora','admin-faktura-matchning',
            'admin-arsredovisning','admin-momsredovisning','admin-perioder-bokforing',
            'admin-event-nytt','admin-event-publicera','admin-event-kopia','admin-event-kapacitet',
            'admin-event-forhandskrav-lagg','admin-event-statistik','admin-event-deltagarlista',
            'admin-event-betalningsstatus','admin-event-skicka-mejl','admin-event-rfid-checkin',
            'admin-utskick-skapa-steg','admin-utskick-test-mejl','admin-utskick-schemalagga',
            'admin-utskick-statistik','admin-mailmall-variabler','admin-enkaat-skapa',
            'admin-enkaat-publicera','admin-enkaat-sammanstallning','admin-formular-nytt',
            'admin-kontaktmeddelanden-hantera',
            'admin-cms-sida-ny','admin-cms-navigation-hantera','admin-roller-granska',
            'admin-workspace-lagg-till','admin-workspace-aterstall-losenord',
            'admin-systeminstallningar-kategorier','admin-behorigheter-granska','admin-cms-sida-publicera',
        ];

        DB::table('help_sections')->whereIn('slug', $slugs)->delete();
    }
};
