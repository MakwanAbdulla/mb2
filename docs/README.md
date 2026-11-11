# Membrum2 — Documentation Index

Membership management system for Armagedon (gaming association, Borås). ~300 members.

## guides/

| File | Contents |
|---|---|
| [guides/dev-setup.md](guides/dev-setup.md) | Local development environment setup |
| [guides/deployment.md](guides/deployment.md) | Deployment guide for shared hosting |
| [guides/issues.md](guides/issues.md) | Working with the backlog in GitHub Issues — labels, branches, PRs |
| [guides/manual-testing.md](guides/manual-testing.md) | Hands-on testing of every module — roles, test environment, what never to do, reporting bugs |
| [guides/dompdf-pdf-generation.md](guides/dompdf-pdf-generation.md) | dompdf pitfalls — fonts, `storage/fonts`, vertical centering, page breaks, how to verify a PDF |

## reference/

| File | Contents |
|---|---|
| [reference/data-model.md](reference/data-model.md) | All DB tables, columns, enums |
| [reference/modules.md](reference/modules.md) | Module descriptions and feature scope |
| [reference/api.md](reference/api.md) | REST API strategy, endpoint list, coverage |
| [reference/mail-templates.md](reference/mail-templates.md) | Mail template system, all template keys |
| [reference/cms.md](reference/cms.md) | CMS: pages, widgets, navigation, help texts — developer onboarding |
| [reference/ui.md](reference/ui.md) | UI: layouts, sidebar, theme, CSS classes, shared components — developer onboarding |
| [reference/integrations.md](reference/integrations.md) | CogWorks, Google Workspace, Discord, Paxton, BSK |
| [reference/payment-gateway.md](reference/payment-gateway.md) | Payment methods — Stripe, Swish, Bankgiro, manual |

## design/

| File | Contents |
|---|---|
| [design/brand.md](design/brand.md) | Design system — colors, CSS classes, component patterns |
| [design/ui-patterns.md](design/ui-patterns.md) | UI patterns — pill nav, `$embedded` prop, collection pages |

## Root

| File | Contents |
|---|---|
| [architecture.md](architecture.md) | Tech stack, hosting constraints, key architectural decisions, what we deliberately don't build |
| [conventions.md](conventions.md) | Coding conventions, localisation rules, security, audit log |

## examples/

Real accounting files from the association — test data for import features (SIE, Bankgiro, Swish, Zettle).
See [examples/README.md](examples/README.md).

## archive/

Only the original bookkeeping plan is left, until `docs/reference/accounting.md` replaces it (#283). Not active reference: parts of it may no longer match the code.

---

## Quick reference

- **Backend language:** English (PHP, models, migrations, routes, tests)
- **Frontend language:** Swedish (all user-facing text, labels, error messages)
- **Stack:** Laravel 12, Livewire 4 + Volt, Flux UI Free, Tailwind CSS v4, Spatie Permission
- **Auth:** Fortify (email/password + 2FA) · Google SSO · Magic links · Passkeys (WebAuthn)
- **Payments:** Stripe, Swish, Bankgiro (BGC file import), manual
- **Hosting:** Shared web hosting — no persistent queue workers, no WebSockets
- **Related projects:** BSK (convention platform, integrated via API) and bokning (room booking) — same stack, private code
