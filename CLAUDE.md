# Membrum2 — Claude Code instructions

Membership management system for Armagedon (gaming association, Borås). ~300 members.

## Related projects
- `../bsk` — Borås Spelkonvent, same stack. If you have it checked out, look there for patterns first. Not everyone on the team has access, so issues and docs must describe a pattern on their own, never just point to BSK.
- `../bokning` — room booking system

## Tech stack
- Laravel 12, PHP 8.4+ · Livewire 4 + Flux UI Free 2 + Volt · Tailwind CSS v4
- Spatie laravel-permission · Laravel Fortify + Google SSO (Socialite)
- Pest 3 · Laravel Pint · MySQL (prod) / SQLite (local)
- QUEUE_CONNECTION=sync (shared hosting, no daemon workers, no WebSockets)

## Language rules
| Context | Language |
|---|---|
| PHP, DB, routes, tests, commits | English |
| All user-facing UI, emails, admin labels | Swedish |

## Key rules — always apply

**Amounts**: integers in **öre** (SEK cents) in DB and API. Display: `1 250,00 kr`.

**Dark mode**: `users.ui_theme` (light/dark/system). Always add `dark:` variants.

**Bokföring**: say "leverantörsfakturor" not AP, "kundfakturor" not AR.

**Dates**: `d/m Y`, 24h clock `H:i`. Carbon locale `sv`. Week starts Monday, only Sunday is red.

**No N+1 queries**: eager-load every relation used in a list or loop (`with()`, `withCount()`, `withSum()`). An avoidable N+1 blocks merge. When it matters, add a test that compares query counts for 1 and several rows (`DB::enableQueryLog()`).

**Tests**: every feature and bug fix comes with Pest tests, and the whole suite must be green before a PR.

**Pint**: run `vendor/bin/pint --dirty`. If a file you touched has old style debt, don't ship the full reformat — see `docs/conventions.md`.

**Seeder vs migration**: system data (pages, nav, help texts, mail templates) goes in migrations via `updateOrInsert` — not seeders. Seeders are dev-only.

**Never `php artisan route:cache`** — a cached route collection breaks every Livewire POST with a 405.

**Deploy**: never add commands to the deploy scripts. Repeatable steps → `app/Console/Commands/PostDeploy.php` (`app:post-deploy`, runs every deploy). One-time data changes → a migration. Manual steps → "Efter deploy" in the PR. Then `./fix-permissions.sh`. See `docs/guides/deployment.md#post-deploy-commands`.

**Git**: short one-line commit messages in English with the issue reference; explanations go in the PR. `Closes #N` in the PR description. See `docs/guides/issues.md`.

## When adding ANY new module — mandatory checklist

1. `database/seeders/PermissionSeeder.php` — add permissions to `PERMISSIONS` + assign to roles in `ROLES` (deploys pick them up via `permissions:add-missing`)
2. `resources/views/livewire/admin/roles/index.blade.php` — add to `GROUPS` constant with Swedish label
3. Livewire component `mount()` — `abort_unless(Auth::user()->hasPermissionTo('x'), 403)`
4. `app/Models/User.php::isStaff()` — add permission if it grants admin nav access
5. `resources/views/components/layouts/public.blade.php` — add nav entry wrapped in `@can('x')`
6. **API controller** in `app/Http/Controllers/Api/V1/` + route in `routes/api.php` + Resource in `app/Http/Resources/` + update `public/api/openapi.yaml`
7. **If the module adds new enum values**: update `MetaController` + the `/meta` section in `openapi.yaml`
8. **Audit log** — every create/update/delete and status change calls `AuditLog::record()` (web, API and commands alike), with the area in `AuditLog::AREA_LABELS` and the action in `ACTION_LABELS`. See `docs/conventions.md#audit-log`

See `docs/reference/api.md` for full API strategy. Every web feature gets an API endpoint in the same session.

## Layouts
- `components.layouts.public` — full layout (sidebar + nav + footer)
- `components.layouts.auth` — auth pages
- `components.layouts.scanner` — minimal kiosk (logo + slot only)
- `components.layouts.onboarding` — onboarding flow

## UI building blocks
- **Mail**: all transactional mail via `MailTemplateService` + `mail_templates` table — see `docs/reference/mail-templates.md`
- **Icons**: `<x-bootstrap-icon name="..." class="size-5" />`. The SVGs are gitignored and fetched by `php artisan icons:sync`; a missing icon renders nothing, without an error.
- **Flux UI Free** does NOT support `flux:option`, `flux:tabs`, `flux:tab`, or `size="lg"` on buttons (only xs/sm/base).

## Status and backlog
What's built: the module table in `docs/reference/modules.md` — update the module's row in the same PR. What we deliberately don't build: `docs/architecture.md#deliberately-out-of-scope`. Everything still to do lives in GitHub Issues, not in `docs/` — `gh issue list`, see `docs/guides/issues.md`.

## Documentation
| File | Contents |
|---|---|
| `docs/architecture.md` | Tech stack, hosting constraints, architectural decisions, deliberately out of scope |
| `docs/conventions.md` | Coding style, localisation, security, audit log |
| `docs/glossary.md` | Domain terms (Swedish association vocabulary ↔ code names) |
| `docs/reference/data-model.md` | All DB tables, columns, enums |
| `docs/reference/modules.md` | **All modules with status** (what's built), descriptions, org-role → Spatie role sync |
| `docs/reference/api.md` | API strategy, endpoints, coverage |
| `docs/reference/integrations.md` | BSK, Paxton agent, CogWorks, Discord, external systems |
| `docs/reference/mail-templates.md` | Mail template system, all template keys, Reply-To workaround |
| `docs/reference/cms.md` | CMS (pages, widgets, navigation, help) — how it works, how to add a widget, `modul:webb` issues |
| `docs/reference/ui.md` | UI (layouts, sidebar, theme, CSS classes, shared components) — how it fits together, known issues, `modul:ui` issues |
| `docs/reference/payment-gateway.md` | Stripe, Swish, Bankgiro, manual payments |
| `docs/design/brand.md` | Design system — colors, components, CSS classes |
| `docs/design/ui-patterns.md` | UI patterns — wrapping pill nav, `$embedded` prop, collection pages |
| `docs/guides/dev-setup.md` | Local development setup, git hooks, troubleshooting (e.g. esbuild permission error) |
| `docs/guides/deployment.md` | Deployment, post-deploy commands |
| `docs/guides/issues.md` | Backlog in GitHub Issues — labels, branches, `Closes #N` |
| `docs/guides/manual-testing.md` | Hands-on testing per module (`test` label issues) |
| `docs/guides/dompdf-pdf-generation.md` | dompdf pitfalls — read before building or changing any PDF template |
