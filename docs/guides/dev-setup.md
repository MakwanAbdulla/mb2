# Developer Setup

How to get a local development environment running from scratch.

> **Fastest path:** if you have Docker, skip straight to the
> [Docker section](#docker-no-local-phpnodemysql-needed) below — it needs
> nothing else installed locally. The rest of this page is for a native
> PHP/Node/MySQL setup.

## Contents

- [Prerequisites](#prerequisites)
- [First-time setup](#first-time-setup)
- [VS Code](#vs-code) · [Dev Containers](#dev-containers-optional)
- [Working with Claude Code](#working-with-claude-code)
- [Test accounts](#test-accounts)
- [Day-to-day workflow](#day-to-day-workflow)
- [Optional integrations](#optional-integrations) — [Google](#google-oauth-sso-login),
  [Discord](#discord-oauth--bot), [Passkeys](#passkeys-webauthn-in-local-dev),
  [Mail](#mail), [Stripe](#stripe-payments), [CogWorks](#cogworks)
- [Project structure](#project-structure)
- [Common artisan commands](#common-artisan-commands)
- [Docker](#docker-no-local-phpnodemysql-needed) — no local PHP/Node/MySQL needed,
  incl. [Windows/WSL2 setup from scratch](#windows--wsl2-setup-from-scratch)
- [Related projects](#related-projects)

## Prerequisites

| Tool | Minimum version | Notes |
|---|---|---|
| PHP | 8.4 | With extensions: `openssl`, `pdo`, `mbstring`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `curl`, `zip`, `gd`, `intl`, `fileinfo` |
| Composer | 2.x | |
| Node.js | 22 LTS | Vite 7 requires Node ^20.19 or ^22.12+ — "18+" will fail `npm run dev`/`build` with a cryptic error |
| npm | 9+ | |
| MySQL | 8.0+ | Or MariaDB 10.3+. SQLite works too — see note below |

Check installed extensions:

```bash
php -m | grep -E 'openssl|pdo|mbstring|tokenizer|xml|ctype|json|bcmath|curl|zip|gd|intl|fileinfo'
```

> **SQLite alternative:** The test suite uses SQLite in-memory. For local dev you can also use SQLite by setting `DB_CONNECTION=sqlite` and `DB_DATABASE=/path/to/membrum.sqlite` — run `touch membrum.sqlite` first. MySQL is closer to production and is preferred.

---

## First-time setup

### 1. Clone and install

```bash
git clone <repo> membrum2
cd membrum2
composer install
npm install
git config core.hooksPath .githooks
```

Then fetch the icon set used by `<x-bootstrap-icon>` — 2000+ SVGs, not
committed to the repo (`app/Console/Commands/SyncIcons.php` always pulls
the latest release straight from GitHub instead, so there's nothing to
keep in sync in git):

```bash
php artisan icons:sync
```

Without this, every `<x-bootstrap-icon>` across the app silently renders
nothing — no error, just missing icons everywhere.

The last line of the first block turns on the repo's git hooks (`.githooks/`), which run
`composer install` and/or `npm install && npm run build` automatically after
a `pull`/`merge`/branch switch whenever `composer.lock`/`package-lock.json`
changed — so nobody has to remember to do it by hand after pulling someone
else's dependency change. When views, CSS, JS or `app/` changed they run
`npm run build`, since Tailwind only emits classes it finds in the source
(skipped while `npm run dev` is running). When `PermissionSeeder.php` changed, they also run
`php artisan permissions:add-missing`, which only ever adds permissions and
roles, so new permissions reach your local database without a reseed. After
a `pull`/`merge` they also run `php artisan icons:sync`, which only downloads
when a newer Bootstrap Icons release is out. New
migration files just get a reminder to run `php artisan migrate` (never
auto-run — that touches the database). All of this only runs when `.env` has
`APP_ENV=local`: on staging and production the deploy scripts do it.

If a hook step fails (e.g. no network for `npm install`), your `pull`/`checkout`
has already completed — git doesn't roll it back — you just need to rerun the
failed command by hand. To turn the hooks off, `git config --unset core.hooksPath`.

### 2. Environment

```bash
cp .env.example .env
php artisan key:generate
```

Then open `.env` and set at minimum:

```ini
APP_URL=http://membrum.local    # or http://localhost:8000
APP_DEBUG=true

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=membrum2
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

# Mail: log driver prints to storage/logs/laravel.log (default, no config needed)
MAIL_MAILER=log

# Session: database driver requires the sessions table (created by migrate)
SESSION_DRIVER=database
CACHE_STORE=database
```

Everything else can stay as `.env.example` defaults for now. Optional integrations (Google, Discord, Stripe, Swish, CogWorks) are picked up automatically when their keys are present and silently skipped when absent.

**Testing payments without real credentials:** for Stripe, use Stripe's own
test mode (test keys entered under `/admin/installningar`, see below) plus
`stripe listen --forward-to localhost:8000/stripe/webhook`.

For Swish, two options, both configured under `/admin/installningar` →
Swish Handel (no `.env` involved):

- **Real sandbox** — download Swish's public MSS test certificate bundle
  (no merchant agreement needed) and upload the `.p12` file with passphrase
  `swish`. Set the Swish-nummer to `1234679304` (Swish's fixed MSS test
  payee alias) — the app detects this value and automatically routes
  Payment Request calls to Swish's sandbox API instead of production. This
  exercises the real certificate/API/callback path end to end; MSS
  auto-resolves test payments per Swish's own documented test rules.
- **Emulator** — tick "Aktivera Swish-emulator" instead: skips the real
  Swish API entirely and replaces the "waiting for the Swish app" screen
  with an approve/decline page. No certificate needed at all, fully
  offline/deterministic. Never enable this in production — it is a plain
  setting and not gated on `APP_ENV` (staging runs `APP_ENV=staging`).

### 3. Database

```bash
mysql -u root -p -e "CREATE DATABASE membrum2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "CREATE USER 'membrum2'@'localhost' IDENTIFIED BY 'secret';"
mysql -u root -p -e "GRANT ALL ON membrum2.* TO 'membrum2'@'localhost';"
```

> **Database GUI:** [TablePlus](https://tableplus.com/) (Mac/Windows/Linux, free tier works) or [DBeaver](https://dbeaver.io/) (free, open source) are good options. Connect with `Host: 127.0.0.1`, `Port: 3306`, `User: membrum2`, `Password: secret`, `Database: membrum2`.

### 4. Migrate and seed

```bash
php artisan migrate --seed
```

`--seed` runs `DatabaseSeeder`, which calls all the system-data seeders (permissions, pages, mail templates, fees, etc.) and `DevDataSeeder`, which creates a full set of test accounts (see [Test accounts](#test-accounts) below).

No seeder creates a fiscal year, so the bookkeeping module has nothing to
attach journal entries/invoices to until one exists. The scheduled
`accounting:create-fiscal-year` only creates *next* year's and only runs
once a year (1 Nov) — on a fresh database, create the current year
yourself:

```bash
php artisan accounting:create-fiscal-year 2026
```

To reset completely at any time:

```bash
php artisan migrate:fresh --seed
```

### 5. Build assets

```bash
npm run dev   # starts Vite in watch mode (hot reload)
# or
npm run build # one-shot production build
```

If `npm run build` fails with a permission error on the esbuild binary:

```bash
find node_modules/@esbuild/linux-x64/bin/ -type f | xargs chmod +x
```

### 6. Link storage

```bash
php artisan storage:link
```

### 7. Serve

Using the built-in Laravel dev server:

```bash
php artisan serve
# → http://localhost:8000
```

Or configure a local virtual host (e.g. Nginx/Caddy/Apache) pointing to `public/` and set `APP_URL` accordingly.

---

## VS Code

`.vscode/extensions.json`, `.vscode/settings.json`, and `.editorconfig` are
already committed — open the folder in VS Code and it'll prompt you to install
the recommended extensions (Intelephense, Tailwind CSS IntelliSense, Laravel
Blade syntax/snippets, Laravel Artisan, `.env` syntax, EditorConfig). Nothing
to create by hand. `settings.json` already has the Intelephense diagnostic
suppressions this codebase needs (Eloquent/Livewire magic-method false
positives) and Tailwind class detection configured for Blade files, including
inside `@class([...])` arrays.

> **Format on save:** the project uses Laravel Pint for PHP formatting, not
> VS Code's built-in formatter — run `vendor/bin/pint --dirty` before
> committing instead.

### IDE helper (Intelephense autocompletion)

[barryvdh/laravel-ide-helper](https://github.com/barryvdh/laravel-ide-helper)
generates stub files that tell Intelephense about facades, `Auth::user()`
(returns `App\Models\User`), and model columns/relations — without it you get
false "Undefined method" errors. The files are gitignored, so generate them
once after `composer install`:

```bash
php artisan ide-helper:generate          # facades → _ide_helper.php
php artisan ide-helper:meta              # container bindings → .phpstorm.meta.php
php artisan ide-helper:models --nowrite  # model properties → _ide_helper_models.php (needs a migrated DB)
```

`composer update` reruns the first two automatically. Rerun `ide-helper:models`
after adding migrations or relations.

> Intelephense still flags `auth()->check()` / `auth()->user()` (the global
> helper's return type is too broad). Prefer the `Auth::` facade, which most
> of the codebase already uses.

### Dev Containers (optional)

`.devcontainer/devcontainer.json` is also committed, wired to the same
`docker-compose.yml` used by the [Docker section](#docker-no-local-phpnodemysql-needed)
below plus `.devcontainer/docker-compose.yml` on top. With the **Dev Containers**
extension installed, "Reopen in Container" gives you the full stack (PHP 8.4,
Node 22, MySQL, all extensions) with zero local PHP/Node install, and the
recommended extensions pre-installed inside the container.

Unlike the plain Docker trial, the Dev Container **mounts your checkout** at
`/var/www/html`: edits, git and VS Code's Source Control panel all act on the
real repo. It runs as a non-root `dev` user whose UID matches yours, so nothing
it creates ends up root-owned. The first start installs `vendor/`,
`node_modules/` and builds `public/build` into the checkout (all gitignored) —
give it a few minutes. After changing CSS/JS run `npm run build` in the
container terminal.

**git push from inside the container** uses your host's SSH agent if one is
running (Dev Containers forwards it); otherwise commit in the container and
push from a WSL/host terminal. Your git name/email are copied from the host's
`~/.gitconfig`.

**Coming from the old setup** (container without the mounted repo):
"Reopen in Container" reuses the old container and fails with
`unable to find user dev: no matching entries in passwd file`. Remove the old
containers first — the database volume is kept — then reopen:

```bash
# in a WSL terminal, in the repo
docker compose down
```

Or use "Dev Containers: Rebuild and Reopen in Container" instead.

On Windows, see the bake-build troubleshooting note under the Docker section
first if you hit a build error.

---

## Working with Claude Code

This project is developed with heavy use of [Claude Code](https://claude.com/claude-code). New developers are expected to use it too — a few things to know:

1. **Install & authenticate**: `npm install -g @anthropic-ai/claude-code`, then run `claude` inside the repo and follow the login prompt (Anthropic account or API key).
2. **`CLAUDE.md`** (repo root) is read automatically at the start of every session — it's the entry point, not a doc you need to open manually. It has quick-reference rules (language, öre amounts, dark mode, mandatory checklist for new modules) and a table linking every file under `docs/`.
3. **Read `docs/README.md`** for the full documentation index before starting a large task — it's organised into `guides/`, `reference/`, `design/`. Point Claude at the specific file if you already know it (e.g. "check `docs/reference/data-model.md`") to save it a search.
4. **`.claude/settings.json`** (committed) holds the project's allowlisted read-only commands (`php artisan test`, `route:list`, etc.) so you aren't prompted for permission on routine ones. Add to it, don't work around it, if you find yourself approving the same safe command repeatedly.
5. **Keep docs in sync as you go** — see "Documentation maintenance" in [conventions.md](../conventions.md). This matters more than usual here: docs are the primary way a fresh Claude session (or a new developer) understands the system without reading all the code.
6. **Claude's memory is local and personal**, not part of the repo — each developer's Claude Code builds up its own memory of preferences and project context over time (`~/.claude/projects/...`, not checked in). Don't rely on another developer's Claude session "remembering" something; put anything durable in `docs/` or `CLAUDE.md` instead.
7. **BSK and bokning** are sister projects whose code is private — most developers don't have them checked out. `CLAUDE.md` mentions `../bsk` for whoever does; if the directory is missing, Claude simply can't look there. Everything Membrum2 depends on is documented in this repo.
8. **GitHub access for PR review etc.**: Claude uses the `gh` CLI for anything involving GitHub (reading/reviewing PRs, checking CI, issues). Since the repo is private, this needs an authenticated token — run `gh auth login` (device-flow login via browser, no need to paste a token manually) once per machine. Without it, Claude gets a plain 404 on private-repo API calls, indistinguishable from the repo not existing.
9. **Outside contributors without write access** (e.g. a friend helping out on a specific feature) don't need to be given push/merge rights on the main repo — add them as a collaborator with **Read** access, and `gh auth login` + `gh pr create` (or just `git push`/open a PR in the GitHub UI) will automatically fork the repo to their account, push their branch there, and open the PR from fork → main repo. They can see and update their own PR, but merging still requires someone with Write/Maintain/Admin access, same as PR #4.

---

## Test accounts

After `migrate --seed`, the following accounts are available. All passwords are **`password`**.

| Email | Role / status | Notes |
|---|---|---|
| `admin@example.com` | Admin | Full access to everything |
| `kassaansvarig@example.com` | Treasurer | Accounting, payments |
| `redaktor@example.com` | Website editor | CMS only |
| `kassor@example.com` | Member manager | Member admin |
| `medlem@example.com` | Active member (Maja) | Standard member 2026 |
| `familjeadmin@example.com` | Active family — payer | Family group, payment responsible |
| `familjemedlem@example.com` | Active family — child | Second account in same family |
| `student@example.com` | Active student member | Student fee 2026 |
| `arlig@example.com` | Honorary member | No fee |
| `forfall@example.com` | Expired | No period for current year |
| `inaktiv@example.com` | Manually deactivated | |
| `gdpr@example.com` | Pending GDPR deletion | |
| `rabatt@example.com` | Active member with discount | Single membership 2026 |
| `pengar@example.com` | Pending payment | Invoice created, not paid |

> Your own account (`orjan.almen@gmail.com`) is assigned the `admin` role by `TestUserSeeder` if it already exists in the database (e.g. from a CogWorks import). Otherwise, use `admin@example.com`.

> Without `COGWORKS_*` credentials the seeder prints `Kunde inte hämta användare` and `FamilyAccountSeeder`/`CorporateCardSeeder` print `hoppar över` lines — that's expected. The real-member import is skipped; every account above is still created.

### Can't log in? ("Dessa uppgifter stämmer inte överens med vårt register")

That message means the email/password pair doesn't match a user — usually because the database was never seeded. Check how many users exist:

```bash
php artisan tinker --execute='echo App\Models\User::count();'
# Docker (from the host): docker compose exec app php artisan tinker --execute='echo App\Models\User::count();'
```

If it prints `0`, seed it (prefix with `docker compose exec app` when using Docker):

```bash
php artisan db:seed --force
```

If the count is above `0` but `admin@example.com` still fails, your database was seeded before that account was added to `TestUserSeeder` — run `php artisan db:seed --class=TestUserSeeder --force` (safe to re-run, it uses `firstOrCreate`).

---

## Day-to-day workflow

### Running tests

```bash
php artisan test --compact
# or
./vendor/bin/pest --compact
```

Tests use SQLite in-memory — no separate test database needed. The `phpunit.xml` sets `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` automatically.

### Code formatting

```bash
vendor/bin/pint --dirty   # formats only changed files
vendor/bin/pint           # formats everything
```

Run before committing. Pint follows Laravel's coding style.

### Watching for changes

```bash
npm run dev   # Vite hot-reload for CSS/JS
```

Livewire component changes are picked up automatically without a server restart.

### Clearing cache

```bash
php artisan optimize:clear
```

---

## Optional integrations

These are all silently skipped when their `.env` keys are absent. Set them up when you need to test the specific feature.

### Google OAuth (SSO login)

This is only the login flow. The separate Google Workspace admin-provisioning
integration (`GOOGLE_WORKSPACE_*` — account creation, shared drives, delegated
mailboxes) uses a different credential type (a service account with
domain-wide delegation) and real org admin access — it's not something to set
up for local dev. See [deployment.md](deployment.md#google-workspace) if you
need to work on that integration specifically.

Logging in via Google with any Google account auto-creates a `User` if none
matches — there's no domain restriction. See
[deployment.md](deployment.md#google-oauth-sso-login) for exactly how the
login/account-matching logic works.

1. Create a project in [Google Cloud Console](https://console.cloud.google.com/)
2. Enable the **Google+ API** / **People API**
3. Create OAuth 2.0 credentials (Web application type)
4. Add `http://localhost:8000/auth/google/callback` to Authorized redirect URIs
5. Set in `.env`:

```ini
GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-client-secret
```

### Discord OAuth + bot

Create your own application — don't reuse someone else's `DISCORD_CLIENT_SECRET`
or `DISCORD_BOT_TOKEN`. The client ID is fine to share (it's not secret, and
it's what shows up in every OAuth URL anyway), but the secret and bot token
each grant real access — sharing them means a leak on one person's machine
compromises everyone's, and nobody can revoke just their own copy.

1. Create an application at [Discord Developer Portal](https://discord.com/developers/applications)
2. Add `http://localhost:8000/auth/discord/callback` and `http://localhost:8000/discord/linked-role/callback` to redirect URIs
3. Set in `.env`:

```ini
DISCORD_CLIENT_ID=
DISCORD_CLIENT_SECRET=
DISCORD_BOT_TOKEN=   # only needed for role sync
DISCORD_GUILD_ID=    # fallback default — see note below
DISCORD_PUBLIC_KEY=  # only needed for slash commands
```

`DISCORD_GUILD_ID` here is only a fallback: `DiscordService::guildId()` checks
the admin-configurable `SystemSetting` (`/admin/installningar?tab=discord`)
first and only falls back to this env var if that's empty. In practice, set
the guild ID in the admin UI, not `.env` — the `.env` value exists for local
dev convenience/first boot, not as the real config path. `CLIENT_ID`/
`CLIENT_SECRET`/`BOT_TOKEN` have no admin-UI equivalent; those stay in `.env`
only.

If you're testing role sync, invite your bot to a test server (not the real
one) with the **"Manage Roles"** permission, and drag its role above whichever
roles you want it to be able to assign — Discord won't let a bot manage roles
ranked above its own. See [deployment.md](deployment.md#discord) for the full
rundown of that gotcha.

**Slash commands locally.** Discord has to reach your machine to deliver
commands. Set `DISCORD_PUBLIC_KEY` (Developer Portal → General Information →
Public Key) in `.env`, start a tunnel with
`cloudflared tunnel --url http://localhost:8000`, and set **Interactions
Endpoint URL** to `https://<tunnel>.trycloudflare.com/discord/interactions`.
Discord sends a test request when you save, so the app must be running. Then
run `php artisan discord:register-commands` once. The tunnel URL changes every
time cloudflared starts, so update the endpoint URL each session.

### Passkeys (WebAuthn) in local dev

WebAuthn requires HTTPS or `localhost`. The built-in `php artisan serve` uses `localhost` which works fine.

If you use a custom local domain (e.g. `membrum.local`), you need HTTPS. Use [mkcert](https://github.com/FiloSottile/mkcert) to create a trusted local certificate and serve via Caddy or Nginx:

```bash
mkcert membrum.local
# then configure your local server with the generated cert
```

Set `APP_URL=https://membrum.local` — the passkey relying party ID is derived from this automatically.

### Mail

The default `MAIL_MAILER=log` writes all sent emails to `storage/logs/laravel.log`. This is the recommended setup for local dev — no mail server needed.

To see rendered emails in a browser, use [Mailpit](https://github.com/axllent/mailpit) (a local SMTP catcher with a web UI):

```bash
# macOS
brew install mailpit && mailpit

# or via Docker
docker run -d -p 8025:8025 -p 1025:1025 axllent/mailpit
```

Then set:

```ini
MAIL_MAILER=smtp
MAIL_HOST=localhost
MAIL_PORT=1025
```

Open `http://localhost:8025` to view caught emails.

### Stripe (payments)

Install the [Stripe CLI](https://stripe.com/docs/stripe-cli) to forward webhooks to your local server:

```bash
stripe login
stripe listen --forward-to localhost:8000/stripe/webhook
```

Not set in `.env` — Stripe keys are stored in the database. Open
`/admin/installningar`, the payment tab, and enter the publishable key,
secret key and the webhook secret printed by `stripe listen`
(`payment.stripe_publishable_key` / `payment.stripe_secret_key` /
`payment.stripe_webhook_secret` in `system_settings`).

### CogWorks

CogWorks is an external member system. If you have API credentials, the local dev server can use them directly. Without credentials, login falls back silently to local password authentication.

```ini
COGWORKS_API_TOKEN=
COGWORKS_ORG_CODE=armagedon
```

---

## Project structure

```
app/
  Http/Controllers/       # Thin controllers (auth flows, downloads, webhooks)
  Http/Controllers/Api/V1/ # REST API — one controller per resource
  Http/Resources/         # API response shaping
  Livewire/               # Class-based Livewire components (admin panels)
  Mail/                   # Mailable classes (use MailTemplateService)
  Models/                 # Eloquent models
  Observers/              # Model observers
  Providers/              # AppServiceProvider, FortifyServiceProvider, VoltServiceProvider
  Services/               # Business logic (MagicLinkService, CogWorksService, …)

database/
  migrations/             # One migration per schema change. System data goes here via updateOrInsert.
  seeders/                # Dev-only test data. DatabaseSeeder + DevDataSeeder.

resources/views/
  livewire/               # Volt single-file components (.blade.php with embedded PHP)
    admin/                # Admin-area pages
    dashboard/            # User dashboard pages (settings, passkeys, two-factor, …)
  auth/                   # Auth views (login, register, 2FA challenge, …)
  components/layouts/     # Layouts: public, auth, scanner, onboarding
  emails/                 # Email templates

routes/
  web.php                 # All web routes
  api.php                 # REST API routes (v1)

public/api/openapi.yaml   # OpenAPI spec (keep in sync with api.php)
docs/                     # Developer documentation
```

---

## Common artisan commands

```bash
# Reset database with all seed data
php artisan migrate:fresh --seed

# Run tests
php artisan test --compact

# List all scheduled jobs
php artisan schedule:list

# Check registered routes
php artisan route:list
php artisan route:list --name=passkey   # filter by name prefix

# Sync org-role permissions (normally runs at 03:00)
php artisan org-roles:sync-permissions

# Format code
vendor/bin/pint --dirty

# Clear everything
php artisan optimize:clear
```

---

## Docker (no local PHP/Node/MySQL needed)

For trying the app out without installing PHP, Node, or MySQL locally:

```bash
docker compose up -d --build
```

This builds the image (PHP 8.4 + all required extensions + poppler-utils for
`pdftotext` + Vite-built assets), starts a MySQL 8.0 container, and on first
boot runs migrations + the full seeder set (same test accounts as the local
setup, all password `password` — see the section above). Then open
<http://localhost:8000>.

Every boot applies pending migrations; seeding runs only when the `users`
table is empty, so restarts don't duplicate demo data. `docker compose down -v`
wipes the database, so the next `up` seeds again from scratch. If you can't
log in, see [Can't log in?](#cant-log-in-dessa-uppgifter-stämmer-inte-överens-med-vårt-register).

This plain trial image is built with `COPY . .` — source code is **not**
mounted, so edits made inside it don't reach your checkout, and code changes
on the host need `docker compose up -d --build` to show up. For actual
development use the [Dev Container](#dev-containers-optional), which mounts
the checkout.

`php artisan test` is safe to run in either container: `phpunit.xml` forces
in-memory SQLite even though `docker-compose.yml` sets `DB_CONNECTION=mysql`.

**`npm run dev` does not work in either Docker setup — use `npm run build`.**
Neither `docker-compose.yml` nor the Dev Container's override exposes port
5173 (Vite's dev server), only 8000 for the app itself. The browser has no
way to reach the Vite dev server, so CSS/JS silently fail to load. After
changing `resources/css`/`resources/js`, run `npm run build` inside the
container (Dev Container: it's mounted, so the output lands straight in
your checkout's `public/build/`; plain trial: rebuild the image with
`docker compose up -d --build` instead, since source isn't mounted there).
There's no hot-reload in Docker — this matches how the team already works
locally too.

All optional integrations (Google, Discord, Stripe, Swish, Anthropic, CogWorks,
...) are left unconfigured in `docker-compose.yml` and self-skip, same as
local dev — add real keys to the `app` service's `environment:` block, or
uncomment the `.env` volume mount in `docker-compose.yml` and put them in a
real `.env` file instead. (Core settings like `APP_KEY`/`DB_*` are set directly
in `environment:` and always win over the `.env` file for the same key — the
mount is for the optional-integration keys that aren't already set there.)

**Known limitation:** Docker installed via `snap` (Ubuntu's default) is
sandboxed to your home directory and cannot see projects outside it — you'll
get `no configuration file provided: not found` if the repo lives elsewhere
(e.g. `/var/www/...`). Either move the project under your home directory, or
install Docker from [the official apt repository](https://docs.docker.com/engine/install/ubuntu/)
instead of the snap package, which removes that restriction entirely.

### Windows / WSL2 setup (from scratch)

If you're starting with a bare Windows machine:

1. **Install WSL2.** Open PowerShell as Administrator and run `wsl --install`
   (installs WSL2 + Ubuntu by default) — see
   [Microsoft's WSL install guide](https://learn.microsoft.com/windows/wsl/install)
   if you hit anything unexpected (older Windows builds, virtualization not
   enabled in BIOS, etc.). Reboot when it asks you to, then open the "Ubuntu"
   app from the Start menu once to finish setup (pick a Linux username/password
   — unrelated to your Windows login).
2. **Install Docker Desktop** ([docker.com](https://www.docker.com/products/docker-desktop/))
   and enable WSL2 integration for your distro: Settings → Resources → WSL
   Integration → toggle your distro on.
3. **Clone the repo *inside* the WSL filesystem, not the Windows one.** From
   the Ubuntu terminal (not PowerShell):
   ```bash
   cd ~
   git clone git@github.com:ArmagedonBoras/membrum2.git
   cd membrum2
   ```
   Avoid `/mnt/c/...` paths — cloning onto the Windows drive is both much
   slower for Docker and the direct cause of the bake build errors below.
4. **Set up git/GitHub access from inside WSL** — it's a separate environment
   from any git/SSH setup you have on the Windows side. Simplest path:
   generate a fresh key with `ssh-keygen -t ed25519 -C "you@example.com"` and
   add it to your GitHub account — see
   [GitHub's SSH key guide](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/generating-a-new-ssh-key-and-adding-it-to-the-ssh-agent).
5. **Install VS Code** on Windows as usual, plus the
   [Remote - WSL](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.remote-wsl)
   extension. Open the project with `code .` *from the Ubuntu terminal*, inside
   the `~/membrum2` directory — this connects VS Code to the WSL side rather
   than the Windows side, which is what the "Dev Containers" workflow further
   up this page needs in order to avoid the bake issue below. (The **Dev
   Containers** extension itself still needs installing separately, same as
   on any OS.)

**Windows:** needs Docker Desktop with the WSL2 backend (the current default).
No source code is bind-mounted — everything is copied into the image at build
time — so the usual WSL2/Windows filesystem-translation and permission
headaches with bind mounts don't apply here. Line endings for `entrypoint.sh`
and friends are forced to LF via `.gitattributes`, so a Windows checkout won't
break the container's shebang line.

**Windows — "bake" build errors (e.g. in the VS Code Dev Containers log):**
`docker compose build` defaults to Docker's newer `buildx bake` backend, which
has several open, confirmed bugs specifically when invoked from the *Windows*
side rather than from inside the WSL2 distro — look for a `c:\...` path in the
failing command (e.g. `docker compose ... -f c:\yourname\membrum2\docker-compose.yml
build`); that's the tell. Two fixes:

1. **Root cause (recommended):** keep the repo inside the WSL2 filesystem
   (e.g. clone it under `~` from inside your WSL distro, not on a Windows
   drive), open it with VS Code's **Remote - WSL** extension first, *then*
   use Dev Containers' "Reopen in Container" from that WSL-connected window.
   This also fixes Docker's general file I/O being much slower against the
   Windows filesystem.
2. **Quick workaround without moving anything:** disable bake by setting a
   Windows environment variable `COMPOSE_BAKE=false` (System Properties →
   Environment Variables → New, under your user account), then restart VS
   Code/Docker Desktop so it picks it up. Falls back to the older, more
   battle-tested build path. **Only works on Compose v2** — Compose v5
   (`docker compose version`) ignores `COMPOSE_BAKE` and always uses bake.

**`additional privileges requested: pass "--allow=fs.read=..."`:** bake's
filesystem entitlement check, which blocks the Dockerfile Dev Containers
generates in `/tmp`. The Dev Container avoids it by building its image with
plain `docker build` in `initializeCommand` (see `.devcontainer/`), so this
should no longer appear there. If you see it from another build, set
`BUILDX_BAKE_ENTITLEMENTS_FS=0` in the environment running `docker compose`.

**Mac (Apple Silicon):** the PHP, Node, Composer, and MySQL images all publish
native arm64 builds, so `docker compose up --build` should just work. If you
ever hit an architecture-related pull/build error, add `platform: linux/amd64`
under the `mysql` service in `docker-compose.yml` to fall back to emulation
(works, just slower).

---

## Related projects

Two sister projects run on the same stack. Their code is private and not shared with the whole team — you don't need them to work on Membrum2:

- **BSK** — Borås Spelkonvent's event platform. Many patterns in Membrum2 were originally adapted from it; Membrum2 integrates with it via API (`docs/reference/integrations.md`).
- **bokning** — room booking system (older).

Shared patterns: auth flows, mail transport fix (Reply-To via raw headers), Livewire component structure, Tailwind config.
