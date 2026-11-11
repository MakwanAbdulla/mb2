# Exempelfiler för importfunktioner

Verkliga exempelfiler från föreningens bokföring. Används som testdata och referens
vid utveckling av importfunktioner i bokföringsmodulen.

## sie/ — SIE4-filer

SIE (Standard Import Export) är det svenska standardformatet för bokföringsdata.
Filerna används för import/export av verifikationer och kontoplan.

| Fil | Typ | Innehåll |
|---|---|---|
| `ARMAGEDON20260519_170729.se` | SIE4 | Fullständig export (kontoplan + saldon + verifikationer) |
| `Arm verifikationer 260325.si` | SIE4 | Verifikationer mars 2026 |
| `Arm verifikationer 260429.si` | SIE4 | Verifikationer april 2026 |
| `Arm verifikationer 260519.si` | SIE4 | Verifikationer maj 2026 |

Relevant kod: `app/Services/Accounting/Sie4Parser.php`

## bankgiro/ — Bankgiro och kontoutdrag

BGC (Bankgirocentralen) skickar återredovisningsfiler efter bearbetning av betalningar.
Formaten används för OCR-matchning av inbetalda medlemsavgifter.

| Filtyp | Beskrivning |
|---|---|
| `Betalningsbevakning_LB_*` | BGC Leverantörsbetalning — återredovisning (LB-fil) |
| `Bg5702-0778_Insättningsuppgifter_*` | BGC insättningsuppgifter (bankgiro 5702-0778) |
| `Transaktioner_*.csv` | Kontoutdrag från Swedbank (CSV-export) |
| `Företagskonto_*_Kontoutdrag.pdf` | Kontoutdrag PDF mars 2026 |
| `edocument bankavgifter.pdf` | E-dokument bankavgifter |

Relevant kod: `app/Services/Accounting/BgMaxParser.php`, `app/Console/Commands/Accounting/`

## swish/ — Swishrapporter

Swish Business exporterar rapporter i xlsx, csv och txt. Används för avstämning
av Swish-betalningar mot bokföringen.

Alla filer innehåller samma datumintervall i olika format — välj csv för enklast parsing.

Relevant kod: `app/Services/SwishService.php`, `app/Http/Controllers/SwishController.php`

## zettle/ — Zettle (PayPal POS)

Zettle (tidigare iZettle, numera PayPal POS) används på konvent för kortbetalningar.
Receipts-rapporten exporteras från Zettle-portalen som xlsx.

Relevant kod: `app/Console/Commands/Accounting/SyncZettle.php`

## fortnox/ — Fortnox-rapporter

Referensdokument exporterade från Fortnox (föreningens tidigare redovisningssystem).
Används som referens vid inledande setup av kontoplanen och leverantörsregistret.

| Fil | Innehåll |
|---|---|
| `Kontoplan.pdf` | Aktiv kontoplan (bas för `AccountSeeder`) |
| `Kontoplan - Inaktiva.pdf` | Inaktiverade konton |
| `Leverantörer.pdf` | Leverantörslista (bas för `VendorSeeder`) |
| `Kostnadsställen.pdf` | Kostnadsställen |
| `Balansrapport.pdf` | Balansrapport (ingående saldon) |
| `Resultatrapport.pdf` | Resultatrapport |

Relevant kod: `database/seeders/AccountSeeder.php`, `database/seeders/VendorSeeder.php`
