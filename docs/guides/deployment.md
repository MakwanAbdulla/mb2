# Deployment Guide

Deployment guide for Membrum2 on shared web hosting.

## System requirements

- PHP 8.4+
- Composer
- Node.js 18+ and npm (for building assets — can be done locally and uploaded)
- MySQL 8.0+ or MariaDB 10.3+
- Apache (shared hosting standard)
- Cron job support
- **HTTPS required** — passkeys (WebAuthn) will not work over plain HTTP

### Required PHP extensions

```bash
php -m | grep -E 'openssl|pdo|mbstring|tokenizer|xml|ctype|json|bcmath|curl|zip|gd|intl'
```

`intl` is required for Swedish locale formatting. `openssl` and `json` are required for WebAuthn passkeys.

## Installation

### 1. Upload files

Upload all files to the hosting account, typically to a subdirectory or the public_html folder. The Laravel `public/` directory should be the web root.

If using Apache and the host does not allow document root changes, use a `.htaccess` redirect in the account root to point to `public/`:

```apache
RewriteEngine On
RewriteRule ^(.*)$ public/$1 [L]
```

### 2. Install dependencies

```bash
composer install --optimize-autoloader --no-dev
npm install
npm run build
```

On shared hosting where npm is unavailable: build assets locally and upload the `public/build/` directory.

If npm fails with a permission error on the esbuild binary:

```bash
find node_modules/@esbuild/linux-x64/bin/ -type f | xargs chmod +x
```

### 3. Configure environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` — see the full variable reference below.

### 4. Run migrations and post-deploy steps

```bash
php artisan migrate --force
php artisan app:post-deploy
```

> Most system data (mail templates, help texts, pages, nav) is seeded via migrations using `updateOrInsert`. **Permissions and roles are the exception**: they are defined in `PermissionSeeder` and created by `app:post-deploy` (via `permissions:add-missing`). Without them, `roles`/`permissions` stay empty and no user can ever be granted admin access. See [Post-deploy commands](#post-deploy-commands).
>
> After the first run, manually assign yourself the `admin` role once (no automatic "first user becomes admin" exists):
>
> ```bash
> php artisan tinker --execute="\App\Models\User::where('email', 'you@example.com')->first()->assignRole('admin');"
> ```

### 5. Fix file permissions

```bash
chmod +x fix-permissions.sh
./fix-permissions.sh
```

### 6. Link storage

```bash
php artisan storage:link
```

### 7. Cache optimisation

```bash
php artisan config:cache
php artisan view:cache
php artisan event:cache
```

Never `route:cache` — see [Updates](#updates).

---

## Environment variables

```ini
# ── Application ───────────────────────────────────────────────────────────────
APP_NAME="Armagedon"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.se
APP_LOCALE=sv
APP_TIMEZONE=Europe/Stockholm

# ── Database ──────────────────────────────────────────────────────────────────
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=membrum_production
DB_USERNAME=db_user
DB_PASSWORD=secure_password

# ── Queue / Cache / Session ───────────────────────────────────────────────────
QUEUE_CONNECTION=sync   # sync = no worker needed (shared hosting)
CACHE_STORE=file
SESSION_DRIVER=file

# ── Mail ──────────────────────────────────────────────────────────────────────
# Option 1: SMTP
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls

# Option 2: Gmail API via service account (used in production)
# MAIL_MAILER=gmail-api
# GOOGLE_WORKSPACE_CREDENTIALS=/path/to/service-account.json

MAIL_FROM_ADDRESS=noreply@yourdomain.se
MAIL_FROM_NAME="${APP_NAME}"
MAIL_REPLY_TO_ADDRESS=info@yourdomain.se
MAIL_REPLY_TO_NAME="${APP_NAME}"
MAIL_CONTACT_ADDRESS=info@yourdomain.se
MAIL_ADMIN_EMAIL=admin@yourdomain.se

# ── Google OAuth (SSO login) ──────────────────────────────────────────────────
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
# Callback URL to register in Google Cloud Console:
# https://yourdomain.se/auth/google/callback

# ── Google Workspace (admin provisioning — optional) ─────────────────────────
GOOGLE_WORKSPACE_ENABLED=false
GOOGLE_WORKSPACE_DOMAIN=
GOOGLE_WORKSPACE_ADMIN_EMAIL=
# Either a file path or the raw JSON content of a service account key (with DWD):
GOOGLE_WORKSPACE_CREDENTIALS=

# ── Discord (OAuth + bot) ─────────────────────────────────────────────────────
DISCORD_CLIENT_ID=
DISCORD_CLIENT_SECRET=
DISCORD_BOT_TOKEN=
DISCORD_GUILD_ID=
DISCORD_PUBLIC_KEY=
# Callback URL to register in Discord Developer Portal:
# https://yourdomain.se/auth/discord/callback
# https://yourdomain.se/discord/linked-role/callback

# ── Passkeys (WebAuthn) ───────────────────────────────────────────────────────
# Optional — set explicitly if you ever rotate APP_KEY (passkeys become invalid otherwise)
# PASSKEYS_USER_HANDLE_SECRET=

# ── CogWorks (activity reporting — optional) ──────────────────────────────────
COGWORKS_API_TOKEN=
COGWORKS_ORG_CODE=

# ── Stripe ────────────────────────────────────────────────────────────────────
# Not set via .env — configure publishable/secret/webhook keys under
# /admin/installningar (stored as payment.stripe_* in system_settings).

# ── Swish ─────────────────────────────────────────────────────────────────────
# Not set via .env — upload the merchant .p12 certificate and set the
# passphrase under /admin/installningar (stored as payment.swish_* in
# system_settings, file stored under storage/app/private/swish/).

# ── Zettle (PayPal POS) ───────────────────────────────────────────────────────
ZETTLE_CLIENT_ID=
ZETTLE_ASSERTION=

# ── SumUp ─────────────────────────────────────────────────────────────────────
SUMUP_API_KEY=
SUMUP_PUBLIC_KEY=

# ── Accounting inbox (IMAP — forward supplier invoices here) ──────────────────
ACCOUNTING_INBOX_HOST=
ACCOUNTING_INBOX_PORT=993
ACCOUNTING_INBOX_ENCRYPTION=ssl
ACCOUNTING_INBOX_USERNAME=
ACCOUNTING_INBOX_PASSWORD=
ACCOUNTING_INBOX_FOLDER=INBOX
ACCOUNTING_INBOX_DELETE_AFTER_IMPORT=false

# ── Kiosk ─────────────────────────────────────────────────────────────────────
KIOSK_TIMEOUT_MINUTES=5
```

### Google OAuth (SSO login)

`App\Http\Controllers\Auth\SocialAuthController` handles two separate flows:

- **Login** (`/auth/google`) — logs in an existing user matched by email,
  `google_id`, `google_personal_email`, or a linked Workspace account email.
  **If no match is found, it auto-creates a new `User` account** — this
  endpoint is effectively open self-registration, not restricted to existing
  members. There is no email-domain allowlist (no `hd` parameter) — any Google
  account can authenticate. A brand-new account has no `Member` profile until
  the person separately applies for membership.
- **Link** (`/auth/google/link`, requires being logged in already) — attaches
  a _second_ Google email (`google_personal_email`) to the current account,
  so someone can log in with either their primary or a personal Gmail.

The redirect URI isn't its own env var — `config/services.php` builds it as
`APP_URL . '/auth/google/callback'`. If `APP_URL` doesn't exactly match what's
registered in Google Cloud Console (scheme, trailing slash, www/non-www), the
callback fails with a generic Google error, not a Laravel one.

### Discord

Besides the callback URLs above, when you invite the bot to the server:

- It needs the **"Manage Roles"** permission (role sync does a `PATCH` on guild members' roles via `App\Services\DiscordService`).
- Discord's API refuses to let a bot manage roles that rank **above its own role** in the server's role hierarchy. After inviting the bot, drag its role up in **Server Settings → Roles** so it sits above every role `discord:sync-roles` needs to assign — otherwise role sync fails with a silent 403 and nothing gets logged beyond that.
- Each environment (local dev, staging, production) should use its **own** Discord application and bot — never share a client secret or bot token across environments. The client ID is not secret and can be reused, but a shared secret/token means you can't revoke one environment's access without breaking the others.
- `DISCORD_GUILD_ID` in `.env` is only a fallback — `DiscordService::guildId()` checks the admin-configurable setting at **`/admin/installningar?tab=discord`** first. Set the real value there after deploying; the `.env` value is just a bootstrap default, don't rely on it being the actual config in production. `CLIENT_ID`/`CLIENT_SECRET`/`BOT_TOKEN` have no admin-UI equivalent and stay in `.env`.
- Slash commands: set `DISCORD_PUBLIC_KEY`, deploy, then set **Interactions Endpoint URL** in the Developer Portal to `https://yourdomain.se/discord/interactions` (Discord sends a signed test request when you save, so the code and key must already be live). The commands themselves are registered by `app:post-deploy` on every deploy, so changes to them go out automatically.
- Linked Roles metadata is likewise registered by `app:post-deploy` (`discord:register-metadata`).

### Google Workspace

`GOOGLE_CLIENT_ID`/`SECRET` (SSO login) and `GOOGLE_WORKSPACE_*` (admin
provisioning) are **two unrelated Google credentials** — don't confuse them:

- `GOOGLE_CLIENT_ID`/`SECRET` is a normal OAuth 2.0 **Web application** client,
  for the Socialite login flow. Users consent individually; no special Google
  Workspace access needed to create it.
- `GOOGLE_WORKSPACE_CREDENTIALS` is a **service account** JSON key with
  **domain-wide delegation (DWD)**, which lets it impersonate
  `GOOGLE_WORKSPACE_ADMIN_EMAIL` (a real super-admin in the Workspace domain)
  without that user consenting per-request. This is what actually provisions
  accounts, manages shared drives, and reads/sends mail for shared mailboxes.

To set up the service account:

1. Google Cloud Console → create a service account → generate a JSON key → set `GOOGLE_WORKSPACE_CREDENTIALS` to its path (or paste the raw JSON).
2. Enable **Admin SDK API**, **Google Drive API**, **Gmail API** (and **Google Meet API** if Meet-space creation is used) in Cloud Console.
3. Copy the service account's numeric **Client ID** (in the JSON key, not the OAuth client ID from the SSO app above).
4. In the Workspace Admin Console: **Security → API controls → Domain-wide delegation → Add new**. Paste the service account's Client ID and this exact scope list:
    ```
    https://www.googleapis.com/auth/admin.directory.user
    https://www.googleapis.com/auth/admin.directory.domain.readonly
    https://www.googleapis.com/auth/admin.reports.usage.readonly
    https://www.googleapis.com/auth/drive
    https://www.googleapis.com/auth/gmail.modify
    https://www.googleapis.com/auth/gmail.send
    https://www.googleapis.com/auth/gmail.settings.basic
    https://www.googleapis.com/auth/gmail.settings.sharing
    https://www.googleapis.com/auth/meetings.space.created
    ```
5. Set `GOOGLE_WORKSPACE_ADMIN_EMAIL` to a real, active super-admin account in the domain — the service account impersonates this user for every API call, so if that account is suspended or demoted, everything breaks silently.
6. Set `GOOGLE_WORKSPACE_ENABLED=true` and `GOOGLE_WORKSPACE_DOMAIN` to the Workspace domain.

This is real org infrastructure, not something to set up casually in a local
dev environment — leave `GOOGLE_WORKSPACE_ENABLED=false` unless you're
specifically working on this integration and have real admin access to the
Workspace domain to test against.

---

## Cron (Laravel Scheduler)

Add one cron entry on the hosting account:

```cron
* * * * * cd /path/to/membrum2 && php artisan schedule:run >> /dev/null 2>&1
```

### Scheduled jobs

| Schedule        | Command                             | Description                                           |
| --------------- | ----------------------------------- | ----------------------------------------------------- |
| Daily 02:00     | `members:deactivate-expired`        | Deactivate memberships past grace period              |
| Daily 09:00     | `members:send-payment-reminders`    | Email members with unpaid invoices                    |
| Daily 10:00     | `members:send-renewal-reminders`    | Email members expiring in 30/14/7 days                |
| Daily 03:00     | `org-roles:sync-permissions`        | Sync `styrelsen`/`revisorer` to Spatie roles          |
| Daily 03:15     | `workspace:purge-pending-deletions` | Remove approved account deletions                     |
| Daily 03:30     | `workspace:sync-delegates`          | Sync Google Workspace delegates                       |
| Daily 03:45     | `workspace:sync-drives`             | Sync shared Google Drives                             |
| Daily 04:00     | `zettle:sync`                       | Import Zettle sales transactions                      |
| Daily 04:15     | `discord:sync-roles`                | Sync Discord server roles                             |
| Daily 07:00     | `discord:booking-summary`           | Post today's bookings and free rooms to Discord       |
| Daily 00:05     | `discord:booking-overview`          | Refresh the 7-day booking overview message in Discord |
| Daily 04:30     | `sumup:sync`                        | Import SumUp card transactions                        |
| Daily 06:00     | `bookings:send-reminders`           | Send booking confirmation reminders                   |
| Monthly (1 Nov) | `accounting:create-fiscal-year`     | Create next fiscal year                               |
| Every hour      | `events:send-reminders`             | Send event reminders                                  |
| Every 5 min     | `mailings:process`                  | Process queued mailings                               |
| Every 5 min     | `mail:import-imap`                  | Import accounting inbox emails                        |
| Every 15 min    | `surveys:process`                   | Process survey invitations                            |
| Every 15 min    | `accounting:fetch-inbox`            | Fetch and import accounting inbox                     |
| Weekly (Mon 01) | `bookings:generate-recurring`       | Generate recurring booking slots                      |
| Daily 02:00     | `db:backup`                         | Database backup                                       |

**Fresh install:** `accounting:create-fiscal-year` only creates _next_
year's and only runs once a year (1 Nov) — no seeder creates one either,
so bookkeeping has nothing to attach entries to until a fiscal year
exists. On a brand-new staging/production database, run it once manually
with the current year: `php artisan accounting:create-fiscal-year 2026`.

---

## Queue (sync driver)

`QUEUE_CONNECTION=sync` — all jobs run inline in the HTTP request. No queue worker or daemon needed, which suits shared hosting.

For heavier operations (bulk email, bank file import), these are triggered by admin action and run synchronously with a progress indicator.

---

## Apache configuration

The `public/.htaccess` (included with Laravel) handles routing. Ensure `mod_rewrite` is enabled.

If the host does not allow setting the document root to `public/`, add to `.htaccess` in the account root:

```apache
RewriteEngine On
RewriteRule ^(.*)$ public/$1 [L]
```

---

## File permissions

Run after any deployment:

```bash
./fix-permissions.sh
```

Standard rules:

- Directories: `755`
- Files: `644`
- `storage/` and `bootstrap/cache/`: `775` with owner `orjan:www-data`
- `artisan` and shell scripts: `755`
- `vendor/bin/*`: `755`

---

## Updates

Normally `./deploy-ssh.sh production` (or `./deploy.sh --production` over FTP) does all of this. By hand:

```bash
# Enter maintenance mode
php artisan down

# Pull latest code (or upload files)
git pull origin main

# Update dependencies
composer install --optimize-autoloader --no-dev

# Build assets (or upload pre-built)
npm install && npm run build

# Run migrations
php artisan migrate --force

# Fix permissions
./fix-permissions.sh

# Clear and rebuild cache
php artisan optimize:clear
php artisan config:cache
php artisan view:cache
php artisan event:cache
# Never `route:cache` — a cached CompiledRouteCollection collides with
# Livewire's InitialRender::registerRouteBeforeExistingRoutes() and breaks
# every Livewire POST request with a 405. `route:clear` only, if anything.

# Permissions, icons, Discord — after config:cache so new .env values are seen
php artisan app:post-deploy

# Exit maintenance mode
php artisan up
```

---

## Post-deploy commands

Anything that has to happen on the server after new code goes out falls into one of three kinds. Never add one-off commands to the deploy scripts.

| Kind                                                                      | Where it goes                                                                               | Example                                                          |
| ------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| Runs on **every** deploy and is safe to repeat                            | A step in `app/Console/Commands/PostDeploy.php`                                             | Register Discord slash commands, sync icons, add new permissions |
| Runs **once** per environment (data fix, conversion, backfill)            | A migration. The `migrations` table makes sure it runs exactly once everywhere, locally too | `relabel_wiki_view_permission`, backfilling wiki revisions       |
| Can't be automated (setting in an external portal, checking data by hand) | An **Efter deploy** section in the PR description                                           | Setting Discord's Interactions Endpoint URL                      |

**`php artisan app:post-deploy`** runs, in order:

1. `permissions:add-missing` — creates permissions and system roles from `PermissionSeeder` that are missing. A **new** permission is given to the roles that list it in `PermissionSeeder::ROLES`. It **never removes** anything, so role changes made at `/admin/roller` or via the API survive. Changing what an _existing_ role has for an _existing_ permission is therefore not deployed automatically: do it in admin, or write a migration if every install needs it.
2. `icons:sync` — Bootstrap Icons, skipped if the latest version is already installed.
3. `discord:register-commands` — skipped unless the bot, client ID, guild ID and `DISCORD_PUBLIC_KEY` are set.
4. `discord:register-metadata` — Linked Roles, skipped unless client ID and bot token are set.

Every step runs even if an earlier one fails, and the command exits non-zero at the end if any step failed. The deploy scripts then print a warning but still take the site out of maintenance mode. Run `php artisan app:post-deploy` again once the problem is fixed.

Adding a step: it must be idempotent, it must skip itself (with a reason) when its integration isn't configured, and it needs a test in `tests/Feature/Commands/PostDeployTest.php`.

> **Don't run `db:seed --class=PermissionSeeder` in production.** Its `run()` uses `syncPermissions()` to make every system role match the seeder exactly, which wipes changes made in admin. It's for local development and tests.

---

## Security checklist

- [ ] `APP_DEBUG=false`
- [ ] `APP_ENV=production`
- [ ] Strong `APP_KEY` generated (keep stable — rotating it invalidates passkeys unless `PASSKEYS_USER_HANDLE_SECRET` is set)
- [ ] `APP_URL` uses `https://` — required for passkeys (WebAuthn)
- [ ] SSL certificate installed
- [ ] Database credentials unique to this app
- [ ] `storage/` writable, not publicly accessible
- [ ] Swish certificates stored outside web root
- [ ] Stripe webhook endpoint registered and secret set
- [ ] Google OAuth callback URL registered in Google Cloud Console
- [ ] Discord OAuth callback URL registered in Discord Developer Portal
- [ ] Discord guild ID set at `/admin/installningar?tab=discord` (not just left as the `.env` fallback)
- [ ] Discord Interactions Endpoint URL set (slash commands are registered by `app:post-deploy`)
- [ ] Cron configured

---

## Backups

The `db:backup` artisan command runs daily at 02:00 via the scheduler. For manual backups:

```bash
# Database
mysqldump -u db_user -p membrum_production > backup-$(date +%Y%m%d).sql

# Uploaded files
tar -czf storage-backup-$(date +%Y%m%d).tar.gz storage/app/public
```

---

## Troubleshooting

```bash
# View Laravel logs
tail -f storage/logs/laravel.log

# Clear all cache
php artisan optimize:clear

# Relink storage
php artisan storage:link

# List scheduled jobs with next run time
php artisan schedule:list

# Verify passkey routes are registered
php artisan route:list --name=passkey

# Check migration status
php artisan migrate:status
```

### Passkeys not working

1. Confirm `APP_URL` uses `https://` — WebAuthn is blocked over plain HTTP by all browsers
2. The relying party ID in `config/passkeys.php` is derived from `APP_URL`. If the domain changes, existing passkeys become invalid
3. If you rotated `APP_KEY`, set `PASSKEYS_USER_HANDLE_SECRET` to the old key value so existing passkeys still verify

### Magic link emails not sent

1. Check mail configuration (`MAIL_MAILER`, host, credentials)
2. Verify the `magic_link` mail template is active: `SELECT * FROM mail_templates WHERE key = 'magic_link';`
3. Check `storage/logs/laravel.log` for mailer errors
