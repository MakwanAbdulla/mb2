# Architecture

## Tech stack

| Layer | Choice | Version |
|---|---|---|
| Backend framework | Laravel | 12 |
| PHP | PHP | 8.4+ |
| Frontend components | Livewire + Flux UI Free | Livewire 4, Flux 2 |
| Single-file components | Livewire Volt | 1 |
| CSS framework | Tailwind CSS | 4 |
| Auth | Laravel Fortify + Google SSO (Socialite) | Fortify 1, Sanctum 4 |
| Permissions | Spatie laravel-permission | latest |
| Testing | Pest | 4 |
| Code formatting | Laravel Pint | latest |
| Build tool | Vite | latest |

## Related projects on same server

- **BSK** — Borås Spelkonvent's event platform (Laravel 12, same stack). Membrum2 integrates with it via API (see `reference/integrations.md`). Several patterns here (help texts, mail fix, auth) originate from BSK, but **its code is private and not available to everyone on the team** — everything you need is documented in this repo.
- **bokning** — room booking system (Laravel + Livewire), likewise not shared with the team.

## Hosting constraints (shared web hosting)

These constraints affect all architectural decisions:

- **No persistent processes** — no queue workers running permanently. Use `QUEUE_CONNECTION=sync` or schedule-based processing via cron.
- **No WebSockets** — no real-time features (Laravel Reverb not available). Discord integration is request/response REST calls (bot token) on a cron schedule, plus outbound webhooks for notifications — no persistent gateway connection.
- **Cron available** — Laravel Scheduler works with one cron entry: `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`
- **MySQL available** — production uses MySQL 8+. SQLite for local dev is fine.
- **No Redis** — use file or database driver for cache and sessions.
- **Storage limits** — keep uploaded files lean; store large assets externally if needed.
- **Shared PHP config** — cannot change php.ini globally. Rely on `.htaccess` for PHP settings where needed.

## Key architectural decisions

### Permissions: Spatie laravel-permission
Three-layer permission model:
1. **Spatie permissions** (fine-grained gates, e.g. `members.manage`, `events.manage-all`)
2. **Organizational roles** with temporal periods (Styrelse, Revision, Valberedning, BSK)
3. **Section roles** within the section hierarchy

Spatie was chosen because the role/permission matrix is too complex for simple middleware guards.

### Frontend: Livewire + Flux UI (not SPA)
Rationale:
- Shared hosting rules out Node.js-based frontends (Next.js, Nuxt, SvelteKit)
- Livewire works as pure PHP, no separate process needed
- Flux UI provides a complete component library
- Alpine.js (bundled with Livewire) handles interactive edge cases
- Same stack as BSK

### Queues: sync driver
Shared hosting has no daemon support. All queued jobs run synchronously.
For heavier background work (bank file import, bulk email), use scheduled commands.

### Real-time: none initially
No WebSockets. Discord role sync is a scheduled REST poll/push (`discord:sync-roles`, daily cron), not a live connection; Discord also gets outbound webhooks for notifications. Everything else is email/database notifications.

### Payments
Four methods:
- **Stripe** — card payments, webhooks for async confirmation
- **Swish** — Swedish mobile payments (callback URL required)
- **Bankgiro** — bank file import (BGC format), OCR matching against invoices
- **Manual** — cash, waived fees, admin-entered

### Authentication
- **Fortify** — email/password, password reset, 2FA
- **Laravel Socialite** — Google SSO (replaced WorkOS, which was too expensive)
- Not all members get Google accounts (family children, honorary members without email)

### Email transport
SMTP med en custom Reply-To-fix. Symfony Mailer hanterar Reply-To på ett sätt som
orsakar problem på delade hostar — lösningen sätter Reply-To manuellt via råa
headers istället för att låta Symfony serialisera dem. Implementationen är en
`MessageSending`-listener i `app/Providers/AppServiceProvider.php` som lägger till
`Reply-To` (från `config('mail.reply_to')`) direkt i headern om den saknas.

### Dark/light mode
Users who are logged in can toggle dark/light mode. Preference stored in the `users` table (`ui_theme: light / dark / system`). Applied via Tailwind's `dark:` variant and a class on `<html>`.

### Locale and formatting
- `APP_LOCALE=sv`, `APP_TIMEZONE=Europe/Stockholm`
- Swedish date format: `d/m Y` (day first), time: `H:i`
- Currency: SEK with Swedish decimal separator (`,`) and thousands separator (` `)
- Calendar week: Monday = first day. **Only Sunday is visually highlighted as a weekend day** in UI calendars (Swedish convention). Other public holidays are computed/stored but not visually special by default.
- Swedish public holidays: `swedishHolidays(int $year)` in `app/Livewire/Booking/Calendar.php` (duplicated in `app/Livewire/Events/EventCalendar.php` — extract to a shared helper if a third place needs it).

## Deliberately out of scope

Things we have decided not to build, so they are not proposed again. GitHub Issues can't hold "won't do" in a findable way, so the list lives here. If circumstances change, remove the line and open an issue.

- **Bookkeeping** (compared with bokio-rea, Accounted and Debet & Kredit, 2026-10-21): payroll and employer declarations (AGI), Peppol e-invoicing, tax account, NE/INK2/SRU export, tax allocation reserve (periodiseringsfond), corporate tax, multi-company support, multiple invoice design templates, CSV import from multiple banks.

## CogWorks integration
CogWorks (Mina Aktiviteter) is used by BSK for SSO login and activity reporting.

In membrum2:
- **Initial member import** — done, one-time (`php artisan import:cogworks`)
- **Ongoing activity reporting** (Actorsmartbook / Borås Stad bidrag) — **blocked**, not CogWorks' fault: Actorsmartbook/Vitec has no public API, only a B2B partner integration requiring a direct agreement. See `docs/reference/integrations.md`.

The CogWorks API credentials are kept outside the repo — ask Örjan if you need them.

## Google Workspace integration
- SSO via Laravel Socialite (Google provider)
- Account provisioning via Google Admin SDK (creating accounts when members join)
- Drive permissions via Google Drive API
- Not all members get Google accounts — controlled per member

## External reporting: Actorsmartbook (Borås Stad)
Used for grant applications. Export format TBD (pending credentials from association).
All activities/events store participant age group data to enable this export.
