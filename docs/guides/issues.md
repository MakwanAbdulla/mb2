# Jobba med GitHub Issues

Backloggen — allt som ska göras — ligger som issues i
[Membrum2/membrum2](https://github.com/Membrum2/membrum2/issues).
Den här guiden beskriver det enklaste sättet att jobba med dem.

## Vad bor var

| Var | Innehåll |
|---|---|
| **GitHub Issues** | Allt som *ska göras*: buggar, nya funktioner, idéer, refaktoreringar |
| **`docs/reference/modules.md`** | Det som *finns*: alla moduler med status |
| **`docs/architecture.md#deliberately-out-of-scope`** | Det vi *medvetet inte bygger* |
| **`docs/reference/`, `docs/architecture.md`** | Hur det som finns fungerar |

Skriv inte todo-listor i `docs/`. Hittar du något som borde göras — skapa en issue.
När en issue blir klar och tillför en ny modul eller funktion uppdateras modulens rad
i `docs/reference/modules.md` i samma PR.

## Skapa en issue

1. **New issue** → välj mall: **Bugg** eller **Förslag / ny funktion**.
2. **Titel**: kort och konkret, på svenska. Beskriv *vad*, inte *hur*.
   - Bra: `Kreditfaktura för kundfakturor`, `Kontaktformuläret ger 500 i testmiljön`
   - Mindre bra: `Fixa fakturor`, `Bugg`
3. **Etiketter**: minst en `modul:` och, för förslag, en `prio:` (se nedan).
   Osäker? Låt bli — den som går igenom backloggen sätter dem.
4. **En sak per issue.** Är det stort — gör en samlingsissue (se *Större arbeten*).

Issues skrivs på svenska, som resten av dokumentationen. Commits, kod och
branchnamn är fortsatt på engelska (se `CLAUDE.md`).

## Etiketter

| Etikett | Betyder |
|---|---|
| `bug` | Något fungerar inte |
| `enhancement` | Ny funktion eller förbättring |
| `refaktorering` | Kodstädning utan ändrat beteende (t.ex. bryta ut komponenter) |
| `documentation` | Bara dokumentation |
| `test` | Praktisk test av befintlig funktion, se [manual-testing.md](manual-testing.md) |
| `question` | Behöver svar/beslut innan någon kan börja |
| `good first issue` | Avgränsad och lagom för den som är ny i kodbasen |
| `modul:bokföring`, `modul:möten`, `modul:medlemmar`, `modul:grupper`, `modul:evenemang`, `modul:shop`, `modul:bokning`, `modul:uthyrning`, `modul:integrationer`, `modul:ui`, `modul:webb`, `modul:kontakter`, `modul:wiki`, `modul:årshjul` | Vilken del av systemet (`modul:årshjul` = återkommande aktiviteter per grupp, påminnelser och historik, se epic #247; `modul:bokning` = lokalbokning, lokalansökningar, rum och kalenderflöden; `modul:uthyrning` = medlemmars årshyror av föremål (`Rental`), inte lokaler; `modul:grupper` = grupper och undergrupper, t.ex. sektioner, styrelse och revisorer, med medlemskap och mandatperioder, se epic #167; `modul:webb` = publika webbplatsen: CMS-sidor, navigering, nyheter, sidtexter, mediebibliotek, se [cms.md](../reference/cms.md); `modul:ui` = layouter, sidopanel, tema och gemensamma komponenter, se [ui.md](../reference/ui.md); `modul:kontakter` = externa kontakter; `modul:wiki` = intern wiki) |
| `prio:1` / `prio:2` / `prio:3` | Nästa på tur / snart / någon gång |
| `blockerad` | Väntar på något utanför vår kontroll — skriv vad i issuen |
| `villkorat` | Görs bara om ett villkor uppfylls (t.ex. momsregistrering) |
| `epic` | Samlingsissue för ett större arbete |
| `duplicate`, `wontfix` | Stängs — med en kommentar om varför |

## Ta en issue

1. **Tilldela dig själv** (Assignees → dig). Då ser andra att den är tagen.
   Ändrar du dig — ta bort dig igen och skriv gärna en rad om hur långt du kom.
2. **Skapa branch** direkt från issuen:
   ```bash
   gh issue develop 42 --checkout
   ```
   Det skapar en branch som `42-kreditfaktura-for-kundfakturor` och kopplar den
   till issuen. (Eller i webben: *Development → Create a branch*.)
3. **Frågor och beslut** skrivs som kommentarer i issuen, inte bara i chatten —
   så hittar nästa person dem.

## Pull request

- Skriv `Closes #42` i PR-beskrivningen (mallen har raden färdig). Issuen stängs
  automatiskt när PR:en mergas.
- Flera issues: `Closes #42, closes #43`.
- Delvis klart? Skriv `Del av #42` — då stängs den inte.

## Större arbeten (epics)

Ett stort arbete, t.ex. *Guidat årsbokslut*, blir en issue med etiketten `epic` och
en checklista med delarna som egna issues:

```markdown
- [ ] #51 Kontrollista före stängning av räkenskapsår
- [ ] #52 Bokslutsverifikation och öppna stängt år igen
- [ ] #53 Periodiseringar
```

GitHub visar förloppet (2 av 3) och bockar av när delissuen stängs. Alternativt
kan delarna läggas som **sub-issues** (knappen *Create sub-issue* i issuen) —
samma effekt, snyggare visning.

## Prioritering

- `prio:1` = det vi jobbar med nu. Håll den listan kort (en handfull).
- Gå igenom backloggen med jämna mellanrum (t.ex. på styrelse-/utvecklarmöte):
  sätt prio på nya issues, stäng det som inte längre är aktuellt.
- Vill ni ha en tavla: **Projects → New project → Board** med kolumnerna
  *Att göra / Pågår / Klart*, och lägg till issues med etiketten `prio:1`.
  Frivilligt — etiketter och filter räcker långt.

## Med Claude Code

Claude Code kan läsa och arbeta mot issues via `gh`:

- "Implementera #42" — läser issuen, skapar branch, implementerar och hänvisar
  till issuen i commit/PR.
- "Skapa en issue för …" — Claude skriver ett utkast och visar det innan det skapas.
- "Vilka `prio:1`-issues finns i bokföringen?"

Ju mer sammanhang i issuen (bakgrund, filer, *klart när*), desto bättre resultat.

## Snabbkommandon (`gh`)

```bash
gh issue list                                  # öppna issues
gh issue list --label "modul:bokföring"        # filtrera på etikett
gh issue list --assignee @me                   # mina
gh issue view 42                               # läs en issue
gh issue create                                # ny (frågar om mall)
gh issue develop 42 --checkout                 # branch kopplad till issuen
gh issue comment 42 --body "…"                 # kommentera
gh issue close 42 --reason "not planned" -c "Ersatt av #55"
```

## Söka

I webbens sökfält, t.ex.:

- `is:open label:prio:1` — nästa på tur
- `is:open no:assignee label:"good first issue"` — något att börja med
- `is:open label:blockerad` — vad vi väntar på
