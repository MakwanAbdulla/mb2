# Conventions

## Language

| Context | Language | Examples |
|---|---|---|
| PHP code (models, controllers, migrations, jobs, tests) | English | `Member`, `membership_type`, `activate()` |
| Route names | English | `members.index`, `events.show` |
| Database (table names, column names, enum values) | English | `membership_periods`, `pending_payment` |
| Git commits | English | `Add member registration flow` |
| Documentation files | English | this file |
| User-facing UI (labels, buttons, error messages, headings) | Swedish | `Registrera dig`, `Ogiltig e-postadress` |
| Email subject lines and bodies | Swedish | |
| Admin UI | Swedish | |

## Code style

Before writing a new file, check sibling files in this repo for structure.

- PHP 8.4+, strict types
- Laravel 12 streamlined structure (no `Kernel.php`)
- Constructor property promotion
- Explicit return types on all methods
- PHPDoc blocks where array shapes are complex
- Enums for all fixed value sets (TitleCase keys, e.g. `Active`, `PendingPayment`)
- Form Request classes for all validation (never inline)
- Eloquent relationships with return type hints
- Eager loading to prevent N+1
- `Model::query()` not `DB::`
- Named routes + `route()` helper, not hardcoded URLs
- Queued jobs via `ShouldQueue` (sync driver on production, fine for shared hosting)
- Run `vendor/bin/pint --dirty` before finalising changes — but note Pint always reformats a file's *entire* contents, not just your diff. If a file you touched already has pre-existing style debt unrelated to your change (several files in this repo do — `routes/api.php`, `PermissionSeeder.php`, `AuditLog.php` are known examples), running Pint on it produces a huge, noisy diff full of unrelated reformatting. When that happens, revert and re-apply just your intended lines by hand rather than shipping the full reformat in an unrelated change.

## Volt components

- Single-file Livewire components in `resources/views/livewire/`
- PHP logic + Blade in one file (inline Volt style)
- Check existing Volt components before writing new ones
- Always set `layout()` and `with()` correctly
- Use class-based style (`new class extends Component { … }`) — every Volt component in the repo does

### `wire:model` vs `wire:model.live`

`wire:model` (no modifier) is deferred in Livewire 4 — the browser input
updates instantly, but the component's PHP property (and the `$wire` JS
mirror) only syncs on the *next* network round-trip, not on every
keystroke/click. This has caused several real bugs in this project where
a checkbox/radio/select needed to immediately reveal, enable, or react to
something else:

- a PHP-rendered `@if($prop)` / `@disabled(! $prop)` elsewhere in the same
  template (the toggle "works" but the dependent field/button stays stuck
  until something else happens to trigger a request)
- an Alpine expression reading `$wire.prop` in `x-show`/`:disabled`/`:class`
  or a `$watch('$wire.prop', ...)` (same staleness, plus `$watch` simply
  never fires between requests)

**Rule:** if a `wire:model`-bound input's value is read by anything other
than a form submission — a sibling `@if`/`@disabled` in the same template,
an Alpine `$wire.prop` read, or a `$watch('$wire.prop', ...)` — it needs
`wire:model.live` (add `.debounce.500ms` for text/textarea fields so you
don't hit the server on every keystroke). If the value is *only* ever read
by client-side JS that never calls a Livewire action (e.g. a value passed
straight into a `fetch()` call), don't involve `wire:model`/`$wire` at all —
use a plain local Alpine variable (`x-model`) instead; it's simpler and
has zero latency.

Known past incidents of this exact bug (fixed, but a reminder of the
shape to watch for): `admin/membership-fees/index.blade.php` (checkbox not
unlocking fee inputs), `dashboard/passkeys.blade.php` (add-passkey button
never enabling — fixed by switching to a local Alpine variable since the
value is only ever used by client-side `fetch()` calls),
`admin/pages/{create,edit}.blade.php` (checkbox not revealing the nav-title
field), `admin/settings/payment.blade.php` (Swish-type radio not revealing
the certificate upload section), `admin/events/show.blade.php` (section
picker not enabling the invite button), `admin/mail-templates/index.blade.php`
(draft autosave silently never firing while typing).

## Flux UI

- Use `<flux:*>` components whenever available
- Check for existing components before writing custom ones
- Activate `fluxui-development` context when working on Flux components

## Testing

- Pest 4 for all tests (`php artisan make:test --pest {Name}`)
- Feature tests for most things, unit tests for isolated logic
- `RefreshDatabase` is **not automatic** — `tests/Pest.php` only binds `Tests\TestCase`, it doesn't apply the trait. Every test file touching the DB needs its own `uses(RefreshDatabase::class);` or you get confusing cross-test failures (or, worse, a caught exception inside the code under test silently swallows the real "no such table" error).
- Many models have no factory (`PageFactory`/`NavigationItemFactory` etc. don't exist despite `HasFactory` being on the model) — check `database/factories/` before assuming one exists; fall back to `Model::create([...])` with the minimum required fields, same as most existing tests already do.
- Use factories and states where they exist — check factory for existing states before manually setting up models
- Run: `php artisan test --compact`
- Every change must have a test

## Tailwind CSS

- Tailwind v4 conventions
- Check existing patterns before adding new ones
- Use `dark:` variant for dark mode styling
- `search-docs` before writing unfamiliar Tailwind

---

## Localisation & formatting

### Locale
- `APP_LOCALE=sv`
- `APP_TIMEZONE=Europe/Stockholm`
- Use Laravel's `__()` / `trans()` for all user-facing strings

### Dates
- Display format: `d/m Y` (e.g. `14/3 2025`)
- Time: `H:i` (24-hour, e.g. `09:30`)
- Long format: `d MMMM yyyy` (e.g. `14 mars 2025`)
- Use Carbon with locale `sv` for Swedish month/day names

### Currency
- Always store amounts in **öre** (integer, SEK cents) in the database
- Display: `1 250,00 kr` (space as thousands separator, comma as decimal, `kr` suffix)
- Helper: `format_money(int $ore): string`

### Calendar / week view
- Week starts on **Monday**
- **Only Sunday is visually red/highlighted** as a rest day — this is Swedish convention
- Other public holidays are computed (`swedishHolidays()` in `app/Livewire/Booking/Calendar.php`) and can be visually indicated separately (e.g. a small marker), but are not red-coloured by default
- Swedish public holidays: New Year, Epiphany, Good Friday, Easter, Easter Monday, Ascension, Whit Sunday, National Day (6 June), Midsummer Eve, Midsummer Day, All Saints, Christmas Eve, Christmas Day, Boxing Day, New Year's Eve

---

## Dark / light mode

- User preference stored in `users.ui_theme` (enum: `light`, `dark`, `system`)
- Default: `system` (follows OS preference)
- Toggle available in the user menu for logged-in users
- Implementation: set class `dark` on `<html>` element based on preference
- Use Tailwind's `dark:` prefix throughout
- Guests always see system preference

---

## File permissions

See [deployment.md](deployment.md) for the `fix-permissions.sh` script.

Standard rules:
- Directories: `755`
- Files: `644`
- `storage/` and `bootstrap/cache/`: `775`, owner `orjan:www-data`
- `database/database.sqlite` (local dev): `664`
- `artisan`, `fix-permissions.sh`: `755`
- `vendor/bin/*`: `755`

---

## Security

- Never use `env()` outside config files — always `config('key')`
- Validate all user input at system boundary (Form Requests)
- Use Gates and Policies for authorization
- Signed URLs for temporary download links
- No sensitive data in logs
- CSRF protection on all forms (Livewire handles this)
- **Livewire file uploads have their own 12MB default cap** (`temporary_file_upload.rules` in `config/livewire.php`), completely independent of PHP's `upload_max_filesize`/`post_max_size`. Raising the PHP ini limits alone does nothing for a Livewire upload — you also need an explicit `max:` rule in that config (already raised to 45MB here). If a feature needs bigger uploads than that, raise both, not just one.

---

## Audit log

Every feature that changes data records it in the audit log, shown at `/admin/handelser` (permission `audit.view`). The board and the auditors use it to see who did what, and when.

### What to log

- **Always:** create, update and delete, plus status changes such as approve, reject, cancel, publish, send and mark as paid. This applies to the web UI, the API and console commands alike: if the same action exists in several places, every path logs.
- **Always:** anything that changes access: roles, permissions, API clients, group and org-role membership, wiki space access.
- **Always:** anything with money or personal data: payments, bookkeeping, member data, GDPR requests and exports.
- **Not:** ordinary reads and page views, or automatic background work that changes nothing of interest. Sensitive reads, such as a full member export, are the exception: log them.

### How

```php
use App\Models\AuditLog;

AuditLog::record(
    action: 'booking_category_updated',      // snake_case: {thing}_{verb in past tense}
    area: 'booking_categories',              // a key in AuditLog::AREA_LABELS
    subject: $category->name,                // short, readable: what was changed
    auditable: $category,                    // the model, so the log links to it
    changes: ['name' => ['from' => $old, 'to' => $category->name]],
);
```

- **`area`** must be a key in `AuditLog::AREA_LABELS`, and **`action`** a key in `AuditLog::ACTION_LABELS`, both with a Swedish label. Add them when you add a module. Without them the log shows the raw key, and filtering by area misses rows.
- **Reuse existing areas.** Use the plural form that is already there (`events`, not `event`) — two names for the same area split the log.
- **`changes`** uses `['field' => ['from' => …, 'to' => …]]` for updates. Build it from `getDirty()` and `getOriginal()` **before** `save()`; afterwards the original values are gone. Use a flat array for other context, such as a reason or a note.
- **`user_id` and the IP address** are filled in automatically from the logged-in user and the request. In console commands with no user, pass `userId:` explicitly when there is someone responsible, or leave it `null` for system actions.
- **Log after the change has succeeded**, inside the same transaction when there is one, so a rolled-back change leaves no log row.

### What never goes in the log

- passwords, tokens, API keys, secrets, webhook URLs or payment credentials, not even masked
- full card or bank account numbers
- date of birth or other sensitive personal data in `changes`

Name and email in `subject` are fine. They are what makes the log readable, and the GDPR export (`GdprExportService`) already includes a user's log rows.

---

## Seeder vs migration för systemdata

**Seeders körs aldrig i produktion.** De används enbart för lokal utveckling och
testmiljöer. `DatabaseSeeder` anropas bara via `php artisan db:seed` manuellt.

**Systemdata som admin kan redigera** (sidor, navigation, hjälptexter, mallar m.m.)
hanteras enligt detta mönster:

1. **`seeder_key`-kolumn** (string, unique, nullable) på tabellen — stabil identifierare
   oavsett vad admin ändrat på posten i övrigt.

2. **Soft deletes** på tabellen — systemrader raderas aldrig fysiskt. Admin kan
   "ta bort" en post men den syns fortfarande för seedern.

3. **Nya systemrader skapas via migration**, inte seeder:
   ```php
   DB::table('pages')->updateOrInsert(
       ['seeder_key' => 'om-oss'],
       ['title' => 'Om oss', 'slug' => 'om-oss', ...]
   );
   ```
   `updateOrInsert` på `seeder_key` gör migrationen idempotent — safe att köra om.

4. **Seeders är idempotenta** via `seeder_key`-kontroll:
   ```php
   $existing = NavigationItem::withTrashed()->where('seeder_key', $key)->first();
   if ($existing) {
       return $existing->trashed() ? null : $existing; // hoppa över, finns redan
   }
   return NavigationItem::create([..., 'seeder_key' => $key]);
   ```

5. **Uppdatera inte innehåll i migration** om posten redan finns — admin-ändringar
   ska bevaras. Lägg bara till rader som saknas (`updateOrInsert` med `seeder_key`
   som matchningsnyckel, men kolla `whereNull('seeder_key')` för backfills).

**Tabeller som följer detta mönster:** `pages`, `navigation_items`
**Tabeller med ekvivalent stabil nyckel:** `help_texts` (key), `mail_templates` (key),
`system_settings` (key) — dessa behöver ingen separat `seeder_key` eftersom `key`
redan är unik och stabil.

---

## Documentation maintenance

- Keep `docs/architecture.md` updated as architectural decisions change
- `docs/reference/data-model.md` updated when migrations are added
- `docs/reference/modules.md` — the module's row in the overview table updated when a module ships or its scope changes (it is the only list of what exists)
- `docs/architecture.md#deliberately-out-of-scope` updated when we decide not to build something
- `docs/reference/api.md` + `public/api/openapi.yaml` updated when API endpoints change
- A human — or Claude, in a new session — should be able to read the docs to understand the system without reading code
