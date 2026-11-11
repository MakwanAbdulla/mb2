# Praktisk testning

Membrum har drygt 1 100 automatiska tester (`php artisan test`). De kontrollerar
att koden gör det den ska, men inte att det **fungerar för den som använder det**:
att flödet är begripligt, att texterna stämmer, att mobilvyn går att använda, att
mejlen ser rätt ut och att olika delar fungerar ihop i verkligheten.

Därför testas varje modul också för hand. Varje modul har en issue med etiketten
[`test`](https://github.com/Membrum2/membrum2/issues?q=is%3Aopen+label%3Atest) och en
checklista över funktionerna.

## Så här går det till

1. **Ta en testissue.** Tilldela dig själv (se [issues.md](issues.md)). Flera personer
   kan dela på en stor modul: skriv i en kommentar vilka avsnitt du tar.
2. **Testa i testmiljön**, aldrig i produktion (se *Var och hur* nedan).
3. **Bocka av** en punkt när den fungerar. Hittar du ett fel:
   - skapa en **buggissue** (mallen *Bugg*) och länka den i checklistan:
     `- [ ] Skapa evenemang med prisnivåer — fel: #123`
   - fortsätt med nästa punkt. Stoppa inte hela testet för ett fel.
4. **Skriv en avslutande kommentar** när du är klar: vad du testade, med vilka roller
   och på vilka enheter, och vad som inte gick att testa och varför.
5. **Issuen stängs** när alla punkter är avbockade eller har en länkad buggissue.

Förbättringsidéer som inte är fel ("det vore bra om …") blir egna issues med mallen
*Förslag*. Blanda inte ihop dem med buggar.

## Vad som räknas som fel

Allt som en vanlig användare skulle störa sig på, inte bara kraschar:

- felmeddelanden, 500-fel, sidor som inte laddar
- fel resultat: belopp, datum, behörigheter, mottagare
- texter som är otydliga, felstavade, på engelska eller som säger fel sak
- sådant som inte fungerar i **mobilen** eller i **mörkt läge**
- mejl som ser trasiga ut eller har fel innehåll
- flöden där man inte förstår vad nästa steg är

## Var och hur

**Testmiljön** är en separat installation med egen databas. Fråga Örjan om adress och
testkonton.

**Gör aldrig följande:**
- **Riktiga betalningar.** Swish körs via emulatorn ("Aktivera Swish-emulator" under
  Inställningar → betalning, `/admin/installningar`) och Stripe i testläge (kortnummer `4242 4242 4242 4242`, valfritt
  framtida datum och CVC).
- **Mejl till riktiga medlemmar.** Kontrollera att testmiljöns databas inte innehåller
  riktiga e-postadresser innan du testar utskick, påminnelser eller notiser. Skickade mejl
  syns i **Mail-logg** (`/admin/mail-logg`). Lokalt hamnar de i `storage/logs/laravel.log`
  eller i Mailpit (se [dev-setup.md](dev-setup.md)).
- **Riktiga integrationer.** Discord, Google Workspace, Zettle, SumUp och Paxton ska vara
  kopplade till testkonton i testmiljön, eller vara avstängda. Fråga om du är osäker.

**Schemalagda jobb** (påminnelser, utskick, synkar) körs från cron. För att testa dem
direkt kör man kommandot för hand, t.ex. `php artisan mailings:process`. Den som saknar
åtkomst till servern ber någon som har det. Kommandot står i varje testissue.

## Testa som rätt person

Det mesta beter sig olika beroende på vem som är inloggad. Testa varje funktion som de
roller som anges i issuen. Vanliga roller:

| Roll | Så skapar man den i testmiljön |
|---|---|
| **Gäst** | Utloggad, eller ett privat webbläsarfönster |
| **Medlem** | Konto med aktivt medlemskap |
| **Ej medlem** | Konto utan medlemskap, eller med utgånget medlemskap |
| **Sektionsledare** | Medlem med en sektionsroll i en sektion |
| **Styrelse / funktionär** | Medlem i org-rollen `styrelsen` (ger Spatie-rollen `styrelse`) |
| **Revisor** | Medlem i org-rollen `revisorer` |
| **Admin** | Konto med full behörighet |

Ha gärna två webbläsare (eller ett vanligt och ett privat fönster) inloggade som olika
roller samtidigt.

## Gäller varje modul

Utöver checklistan i issuen ska varje modul kontrolleras på det här sättet:

- [ ] **Mobil** (smal skärm, gärna en riktig telefon): går allt att nå och använda?
- [ ] **Mörkt och ljust läge** (temaväljaren i sidhuvudet): syns all text, finns det inga vita rutor?
- [ ] **Texter** på svenska, begripliga och utan stavfel
- [ ] **Datum** som `d/m Y`, tid som `H:i`, belopp som `1 250,00 kr`
- [ ] **Tomma lägen**: hur ser sidan ut när det inte finns något (inga evenemang, inga ordrar)?
- [ ] **Felaktig inmatning**: tomma fält, för långa texter, negativa belopp. Ges ett begripligt felmeddelande?
- [ ] **Behörighet**: når en roll som *inte* ska ha åtkomst sidan via direktlänk? (Den ska få 403 eller 404.)
- [ ] **Hjälp**: finns en hjälpsida för modulen (`/hjalp`), och stämmer den med hur det fungerar?
