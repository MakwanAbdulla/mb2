# PDF-generering med dompdf — kända fallgropar

Membrum använder dompdf (`barryvdh/laravel-dompdf`) för PDF-generering,
bland annat i `InvoicePdfService`, `ReportController` och `TvController`.
Den här filen samlar ett antal verifierade, icke-uppenbara avvikelser i hur
dompdf renderar CSS jämfört med en vanlig webbläsare — upptäckta under
arbete med PDF-utskrifter i ett annat projekt (Borås Spelkonvent), men
beteendena är dompdf:s egna och gäller lika mycket här. Allt är verifierat
empiriskt (rendera → titta på den faktiska PDF-bilden), inte bara läst i
dokumentationen.

## 1. @font-face: `format()`-hinten döljer tysta fel

```css
@font-face {
    font-family: 'Brandon';
    src: url('{{ $fontUrl }}'); /* INGEN format()-hint */
    font-weight: bold;
}
```

`vendor/dompdf/dompdf/src/Css/Stylesheet.php::_parse_font_face()` kräver
bokstavligen `$format === "truetype"` för att alls registrera en
`@font-face`-källa — oavsett vad filen faktiskt är. Skriver man
`format('opentype')` för en riktig `.otf`-fil avvisas den helt **utan
felmeddelande** — texten faller bara tyst tillbaka till
`sans-serif`/`Helvetica`. Lösning: utelämna `format()` helt, då faller den
tillbaka på "truetype" som default och `.otf`-filen laddas ändå korrekt.

## 2. `storage/fonts/` måste existera och vara skrivbar — annars 500 i produktion

dompdf cachar beräknade fontmetriker (`.ufm`/`.ufm.json`/`.ttf`) i
`storage_path('fonts')` (satt i `font_dir`/`font_cache` i
`barryvdh/laravel-dompdf`:s paketkonfiguration). dompdf **skapar inte
katalogen själv** — den gör `fopen($path, 'w+')` rakt av, vilket kastar ett
`ErrorException` (→ 500) om katalogen saknas.

Innehållet är regenererbar cache och ska inte versionshanteras, men själva
mappen måste finnas — annars finns den bara lokalt hos den som råkade
rendera en PDF med `@font-face` först, och en fräsch server/deploy saknar
den helt tills någon manuellt kör `mkdir storage/fonts`. Det här orsakade
en verklig produktionsincident i systerprojektet.

**I Membrum är det löst:** `storage/fonts/` finns i git med en egen
`.gitignore` (`*` + `!.gitignore`), samma mönster som övriga
`storage/`-kataloger. Mappen följer alltså med vid deploy, men cachefilerna
gör det inte. Ta inte bort den filen. Membrums PDF-mallar använder idag
inga egna `@font-face`-typsnitt (bara dompdf:s inbyggda), så cachen används
först när någon lägger till ett — men då ska det fungera direkt.

Felet i loggen ser ut så här:

```
fopen(.../storage/fonts/xxx.ufm): Failed to open stream: No such file or directory
```

## 3. Vertikal centrering: bara "ghost element"-tekniken fungerar

Varken `display: table`/`table-cell` + `vertical-align: middle`, eller en
riktig `<table><td valign="middle">`, centrerar innehåll vertikalt på ett
pålitligt sätt i dompdf — verifierat i flera olika konfigurationer
(implicit höjd, explicit höjd, `position: absolute` eller normalt flöde,
nästlat eller inte). Det enda som fungerar, även nästlat i en annan
tabells `<td>`, är den klassiska CSS2.1-tekniken:

```css
.banner {
    height: 40mm;
    text-align: center;
    font-size: 0; /* annars räknas whitespace mellan ::before och
                     innehållet som ett synligt tecken */
}
.banner::before {
    content: '';
    display: inline-block;
    height: 100%;
    width: 0;
    vertical-align: middle;
}
.banner-text {
    display: inline-block;
    vertical-align: middle;
    font-size: 38pt; /* återställs här eftersom föräldern har font-size: 0 */
}
```

## 4. `height` + `padding`/`border` på SAMMA element respekterar inte border-box

`box-sizing: border-box` hedras inte konsekvent av dompdf när `height` och
`padding` (eller `border`) ligger på samma element — paddingen läggs
**utanpå** den satta höjden istället för att rymmas inom den. Verifierat
konkret: en deklarerad höjd på 140mm renderade som ~157mm (+18mm = exakt
paddingen som skulle ha rymts inom höjden).

Lösningen är strukturell, inte en annan CSS-regel: dela upp höjdbärande
element och paddingbärande element i syskon-element, aldrig samma element.

## 5. Nästlad `<table>` i en `<td>` ignorerar sin egen `valign`

En `<table>` nästlad inuti en annan `<table>`:s `<td>` struntar i sin egen
`valign="middle"`. Mer överraskande och farligare: att lägga till en TREDJE,
annorlunda dimensionerad `<td>`-kolumn (t.ex. en mellankolumn som
skiljelinje) i en rad som tidigare hade likformiga celler fick dompdf att
**tappa greppet om hela radens höjd** så fort någon av de andra cellernas
textinnehåll radbröt till flera rader — innehåll bytte plats, svämmade över
i fel kolumn, helt oförutsägbart.

Lösning: rör aldrig tabellens kolumnstruktur för en rent kosmetisk
skiljelinje. Rita den istället som en separat `position: absolute`-overlay
ovanpå en i övrigt obearbetad, redan bevisat stabil tabell:

```css
.sida-omslag { position: relative; overflow: hidden; }
.mittlinje {
    position: absolute;
    top: 0; left: 100mm; width: 10mm; height: 296mm;
    background: #fff;
}
```

`overflow: hidden` på omslaget är nödvändigt — annars målar overlayen
(alltid full höjd) vitt på en tom sida efter sista, ofullständiga innehåll.

## 6. dompdf:s automatiska sidbrytning är INTE pålitlig — lita aldrig på den

Skrivet om helt efter att den ursprungliga "fixen" nedan visade sig vara
fel mot en ANNAN datamängd. Historien är värd att förstå, samma felslut
är lätt att göra igen.

**Försök 1: `@page { margin-bottom }`.** Antagandet var att en stor
`margin-bottom` skulle reservera utrymme och tvinga fram en brytning innan
innehåll når det. **Fel.** Testat med värden från 6cm till 20cm på samma
reproducerbara fall — noll skillnad i var sidan bröts, verifierat med
exakta pixelkoordinater (`pdftotext -bbox`). `@page margin` påverkar bara
innehållets visuella startposition, inte dompdf:s beräkning av vad som får
plats vid tabellpaginering.

**Försök 2: ett tomt `<tfoot>`.** `<thead>`/`<tfoot>` är riktiga delar av
en tabells boxmodell, och `<thead>` upprepas pålitligt på varje fysisk
sida (det fungerar). Ett tomt `<tfoot>` (ingen synlig bild, bara en satt
`height`) borde då rimligen reservera motsvarande utrymme och få
`page-break-inside: avoid` att lösa ut på rätt rad. Det **verkade**
fungera på en första testad repro — men med en ANNAN, verklig datamängd
(samma mall, kortare rader men fler rubriker tätt inpå varandra) fortsatte
dompdf att fylla sidan nästan ända till papperskanten och lägga innehåll
bakom footern, **oavsett tfotens satta höjd** (testat 1mm–70mm). Den
första "fixen" löste alltså bara den specifika repron den byggdes mot —
dompdf:s interna "får det här plats"-beräkning för tabellpaginering är
helt enkelt inte pålitlig, oavsett vilken CSS man försöker styra den med.

### Vad som faktiskt fungerar: bestäm sidbrytningen själv, i PHP

Sluta försöka övertala dompdf att räkna rätt. Räkna själv, i PHP, och ge
dompdf bara OVILLKORLIGA brytningar att hedra (`page-break-after: always`
mellan redan färdiga, garanterat-passande sidor) — en betydligt
pålitligare dompdf-funktion än ett villkorligt "får det här plats"-beslut.

1. Bygg en platt sekvens av poster (rubriker + rader, i ordning).
2. Uppskatta en höjd i mm för varje post — medvetet i ÖVERKANT (hellre en
   sida för lite fylld än innehåll bakom footern).
3. Ackumulera höjd per "sida"; så fort nästa post skulle spränga budgeten
   (sidhöjd minus marginaler minus header minus footerzon minus buffert),
   starta en ny sida istället. En rubrik ska aldrig stå ensam sist på en
   sida — slå ihop dess höjd med nästa rads höjd i beräkningen.
4. Rendera varje färdig "sida" som en EGEN `<table>` med egen `<thead>`
   och `page-break-after: always` mellan dem.

`position: fixed`-footern behövs fortfarande, men av en mindre kritisk
anledning nu: den målas ovanpå varje fysisk sida oavsett tabellstruktur,
vilket räcker eftersom varje `<table>` redan ÄR konstruerad (av PHP-koden
ovan) för att sluta ovanför footerns zon — den förhindrar inte längre
överlapp på egen hand, den upprepar bara den visuella bilden.

## 7. `page-break-inside: avoid` — ett svagt skyddsnät, inte en lösning

- **Fungerar INTE** på en lös `<div>` eller en fristående `<table>` i
  normalt dokumentflöde — innehållet svämmar bara över ändå, "avoid" löser
  aldrig ut.
- **Fungerar ibland, inte pålitligt**, på en `<tr>` inuti en `<table>`
  dompdf delar upp automatiskt — tillräckligt bra för en första repro för
  att ge falsk trygghet, men bevisat otillräckligt mot verklig data (se
  punkt 6). Använd den ändå som ett billigt extra skyddsnät, men bygg
  aldrig en lösning som förlitar sig på att den ensam förhindrar överlapp.
- `page-break-after: avoid` på en rubrikrad är likaså bara ett skyddsnät —
  den riktiga garantin är att PHP redan räknar rubrik+första-rad som en
  enhet vid sidindelningen (se punkt 6).

**Praktisk regel:** om innehåll med variabel höjd behöver kunna svämma
över till fler sidor UTAN att överlappa ett fast element (logga, footer),
räkna ut sidindelningen i PHP utifrån en överskattad höjd per post. Lita
aldrig på att `@page margin`, `position: fixed`/`absolute`, eller
`page-break-inside: avoid` ensamma garanterar detta.

## 8. Centrering av en logga/bild

`text-align: center` på en fullbredds-förälder fungerar. `margin: 0 auto`
direkt på en absolut positionerad bild gör det **inte** — centrerar inte
alls i dompdf.

## 9. Verifieringsmetod som faktiskt fungerar

Kod-granskning av CSS räcker inte — dompdf:s faktiska renderingsbeteende
avviker gång på gång från rimliga CSS-förväntningar (se alla punkter ovan).
Det som fungerat varje gång:

1. Bygg upp verklig (eller verklighetstrogen) testdata.
2. Rendera PDF:en direkt, spara till en scratch-fil.
3. Läs in PDF-sidan som bild och **titta på den**, sida för sida.
4. Vid buggar som bara syns i specifika fall: mät exakta koordinater med
   `pdftotext -bbox` för att räkna ut vad dompdf faktiskt gjorde, istället
   för att gissa.

Att bara räkna sidantal (`pdfinfo ... | grep Pages`) är INTE tillräckligt
för att verifiera en pagineringsfix — flera av buggarna ovan gav samma
sidantal före och efter en lyckad fix (eller en misslyckad), bara med
olika (fel) innehåll per sida.

## Källa

Upptäckt och verifierat under arbete med flersidiga schemautskrifter i
Borås Spelkonvent-projektet (BSK). BSK:s kod är privat och inte
tillgänglig för alla i Membrum-gänget, men allt som behövs står i den här
filen — kodexemplen ovan är de lösningar som faktiskt fungerade.
