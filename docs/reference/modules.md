# Modules

## Module overview

The one place that says what exists. Everything still to do is in [GitHub Issues](https://github.com/Membrum2/membrum2/issues) (see [issues.md](../guides/issues.md)); deliberate non-goals are in [architecture.md](../architecture.md#deliberately-out-of-scope).

**Keep this table current**: when a PR adds a module or changes what one covers, update its row in the same PR.

Status: ✓ built · ◐ partly built (open issues) · – not built

| Module | Description | Phase | Status | Notes |
|---|---|---|---|---|
| Core Auth | Login, registration, Google SSO, passkeys, 2FA, kiosk login | 1 | ✓ | SSO account linking |
| Members | Member register, family groups, GDPR export and erasure | 1 | ✓ | |
| Roles & Permissions | Spatie roles, organizational roles (period-based), sections tree | 1 | ✓ | Org roles synced to Spatie roles, see below |
| CogWorks Import | One-time migration from existing system | 1 | ✓ | `php artisan import:cogworks` |
| CMS | Pages, navigation, widgets, help texts | 1 ext | ✓ | See [cms.md](cms.md) |
| Help & documentation | Help hub `/hjalp` with groups, accordion and search | – | ✓ | |
| Scanning station | Check-in kiosk | 1 ext | ✓ | Admin + kiosk layout |
| Membership Fees | Annual fees, invoicing trigger | 2 | ✓ | |
| Payments | Stripe, Swish, Bankgiro/ISO 20022, manual | 2 | ✓ | See [payment-gateway.md](payment-gateway.md) |
| Invoicing | Kundfakturor | 2 | ✓ | Credit notes: PR #256 |
| Discounts | Discounts and discount codes | 2 | ✓ | |
| Events | Event catalog, registrations, occurrences, tickets, check-in | 3 | ✓ | |
| Event Payments | Fees, addons, discounts, prerequisites | 3 | ✓ | |
| External Organizers | Third-party fees through the association | 3 | ✓ | |
| Member Communications | Newsletters, segmented mailings | 4 | ✓ | Sending runs from cron: `mailings:process` (no queue daemon) |
| Registration Forms | Standalone form builder, 15 field types incl. product/membership/rental actions | 4 | ✓ | `/admin/formulär`, API `/api/v1/admin/forms` |
| Surveys | Surveys with responses | 4 | ✓ | |
| Webshop | Products, variants, orders | 4 | ✓ | |
| Bookkeeping | Chart of accounts, journal, vouchers, leverantörsfakturor, SIE export | 5 | ✓ | |
| Expense claims | Members submit, board approves | – | ✓ | |
| Room bookings | Bookings, recurring bookings, venue applications, TV view, iCal | – | ✓ | |
| Rentals | Members' yearly rentals of items, waiting list | – | ✓ | |
| RFID / Paxton | Tags, Paxton access-control sync | – | ✓ | Admin UI + API, see [integrations.md](integrations.md) |
| Kiosk cash register | Self-service sweets kiosk (ESP32, separate repo) identifying members by the `/mitt-kort` QR code | – | ◐ | Membrum side: `POST /api/v1/verify-member-qr`. Credits not built. See [integrations.md](integrations.md#kioskkassan-esp32-fristående-projekt) |
| Meetings | Meetings, agenda, minutes, tasks, motions (submissions), polls | – | ✓ | |
| E-signing (DocSign) | BankID signing, `/admin/signeringar`, callbacks, sync, API | – | ◐ | Base built (#134). Minutes, annual accounts and agreements remain (#26–#29) |
| Wiki | Spaces, pages, access rules, revisions | – | ◐ | In progress, `modul:wiki` issues |
| Gamification | XP, badges, tasks, kudos, leaderboard | – | ✓ | |
| Tournaments | Phases, rounds, matches, standings | – | ✓ | |
| Mail templates | All transactional mail through `MailTemplateService` | – | ✓ | See [mail-templates.md](mail-templates.md) |
| IMAP import | Import mail into the office inbox | – | ✓ | `mail:import-imap` every 5 min, `/installningar/importera-mail` |
| API clients | client_id/secret, service accounts, scopes, rate limits | – | ✓ | `/admin/api-klienter`. Gaps: epic #271, see [api.md](api.md) |
| Activity Reporting | Actorsmartbook / Borås Stad | 6 | – | |
| Discord | OAuth linking, role sync, Linked Roles, webhooks, booking summary and overview, slash commands | 6 | ✓ | `discord:sync-roles`, `discord:booking-summary`, `discord:booking-overview`; commands registered by `app:post-deploy` |
| Statistics | Dashboards: events, members, revenue | 6 | ✓ | |
| Documents | Never built standalone — superseded by Google Workspace Drive sharing | 7 | – | |
| Google Workspace | Account provisioning, Drive as file storage, password reset | 7 | ✓ | |
| Shipping | Carrier integration, shipping costs, labels | 8 | – | |

---

## Phase 1 — Foundation

### Core Auth
- Email/password via Laravel Fortify (registration, login, password reset, 2FA)
- Google SSO via Laravel Socialite (WorkOS was considered but dropped due to cost — see `integrations.md`)
- Not all members get Google accounts — SSO is optional
- Dark/light/system theme toggle stored per user (`users.ui_theme`)

### Members
- Full member register with search and filters
- Self-registration flow: fill form → pay membership fee → auto-activate
- Admin back-office registration (bypass payment if needed)
- Family group management: admin can add/remove family members, toggle `has_own_account`
- Age-based automatic reclassification (child → adult on birthday)
- Member profile: personal details, contact info, section memberships, role history
- GDPR: consent management, data export, deletion request

### Roles & Permissions
- Spatie laravel-permission base layer (permissions and role→permission assignments)
- Organizational roles: Styrelse, Valberedning, Revisorer, BSK, and custom
  - Period management: forming → active → reporting → closed
  - Calendar-year periods and custom date-range periods
  - cross_period_access flag (Revisorer = false, Styrelse = true)
  - Mid-term member changes (join/leave a period)
  - Synced to Spatie roles by `php artisan org-roles:sync-permissions` (daily 03:00, `--dry-run` available): `styrelsen` → role `styrelse`, `revisorer` → role `revisor`. Members of a forming, active or reporting period get the role. After a period closes they **keep the whole role** for 6 months after `reporting_end` (falling back to `active_end`), so ex-board members and auditors can still answer questions about the previous year (`SyncOrgRolePermissions::GRACE_MONTHS`).
- Section tree (unlimited depth, adjacency list)
  - Section membership
  - Section roles (ordförande, sekreterare, etc.) with validity dates

### CogWorks Import
- Artisan command: `php artisan import:cogworks`
- Maps CogWorks member data to `members` table
- Handles existing users who log in via CogWorks SSO in BSK
- Stores `cogworks_id` on member for future sync
- Run once during initial deployment; idempotent (safe to re-run)

---

## Phase 2 — Economy

### Membership Fees
- Fee levels per membership type per year (configurable)
- Automatic invoice generation when member registers or renews
- Renewal reminders via email (scheduled command)
- Configurable grace period for bankgiro payments
- Family invoicing: one invoice for the family, not per member

### Payments
- **Stripe**: card payments with webhook confirmation
- **Swish**: Swedish mobile payments with callback
- **Bankgiro**: upload bank export file (BGC/SEB/Nordea/Handelsbanken), OCR-matching to invoices
  - Matched automatically by OCR number
  - Unmatched lines flagged for manual review
- **Manual**: admin registers a payment (cash, waived, external)
- Invoice PDF generation and email delivery
- Overdue handling: send reminders, eventually deactivate membership

---

## Phase 3 — Events

### Event Catalog
- Originally ported from BSK's event system and extended
- Events can belong to a section, be association-wide, or have individual organizers
- Multiple organizers per event (lead, co-organizer, contact)
- Event occurrences (time slots), each with location and capacity
- Event status flow: draft → published → cancelled / completed

### Event Registrations
- Public registration form (with or without login, configurable)
- Waitlist handling
- Confirmation email on registration

### Event Payments
- Base fee per event (can be 0 = free)
- Optional addons with variants (t-shirt S/M/L, mug)
- Discounts: early-bird, member price, promo code, group discount
- Event prerequisites: registration to event A required to register for event B
- External organizer fee agreements: third-party charges through the association

### Event design decisions
Made when the module was built; why it works the way it does.

| Question | Decision |
|---|---|
| Capacity | Per occurrence (`event_occurrences`), not per event in total |
| Waitlist | Manual — admin decides per participant or for the whole group |
| Cancellation | Refund only if the organizer cancels; otherwise admin decides |
| Transfer | New registration for the recipient (no new order); the original is marked `transferred`. Admin approves. |
| Registration form | `events.registration_form_id` → the form builder (an early JSON-schema field was replaced) |
| Notifications | Free-text fields per event (`registration_instructions`, `reminder_instructions`) injected as variables into the global mail template |
| Punch cards | Postponed until the end of the whole project |
| Check-in | Both QR code (`check_in_token` on the registration) and an admin list |
| Booking link | `events.booking_id` and `event_occurrences.booking_id` |
| Discount codes | Existing `discounts` infrastructure, `applies_to = event` |
| Who creates events | Spatie role `event-organizer` (Eventarrangör) for own events; `events.manage-all` for all |

---

## Phase 4 — Communications, Forms, Shop

### Member Communications
- Newsletter/mailing system
- Segmentation: by section, membership type, active status, etc.
- Templates
- Email sending via configured SMTP

### Registration Forms
- Standalone form builder (`/admin/formulär`), not tied to events — any form can optionally link to one
- 15 field types (`App\Enums\QuestionType`): text, textarea, radio, checkbox, select, scale, datetime, number, email, section, plus four "action" types that do something on submit — product (adds to cart), event_selection, section_selection, membership, rental_waitlist
- Validation rules
- Responses stored and exportable

### Webshop
- Product catalog with variants
- Products can be standalone or reused as event addons
- Cart and checkout → Invoice → Payment (same payment infrastructure)
- Physical product fulfillment tracking

### Webshop design decisions

| Question | Decision |
|---|---|
| Product types | physical \| digital (digital prepared for, not implemented) |
| Variants | At most one dimension per product ("Storlek", "Färg", "Format") |
| Stock | Per product — `track_stock = true` turns stock tracking on |
| Shipping | Not implemented. Weight and dimension fields prepared on products |
| VAT | No VAT handling in the shop — the association is not VAT registered |
| Visibility | Public shop — non-members can see and buy |
| Cart | Polymorphic — can hold `ProductVariant` and/or `EventPriceTier` |
| Event checkout | Kept as a fast flow. Upsell products (`show_in_event_checkout = true`) are shown there |
| Cart conversion | If an upsell product is added during event checkout, it converts to the shared checkout `/kassa` |
| Admin role | Spatie role `shop-manager` (Butiksansvarig) with `shop.manage` |

---

## Phase 5 — Bookkeeping

### Bookkeeping
- Chart of accounts (kontoplan) based on BAS standard
- Double-entry journal (verifikationer) with debit/credit rows
- Automatic journal entries on payment events (membership fee paid, order paid, refund)
- Manual voucher entry for cash, bank fees, etc.
- Fiscal year management (calendar year, starts 2027-01-01)
- SIE4 export (standard Swedish accounting interchange format)
- Basic reports: result report, balance sheet, account ledger

`AccountSeeder` seeds Armagedon's actual active chart of accounts (from
their real Fortnox export) on a fresh install — that's the normal path, no
manual step needed. `php artisan accounting:seed-bas` is separate and
optional: it imports the *full* standard BAS reference chart (hundreds of
codes) as inactive accounts, for when you need an account code that isn't
in the active chart yet. Safe to re-run — skips codes that already exist.
Activate imported accounts manually in the admin UI when you start using
them.

---

## Phase 6 — Reporting, Discord, Statistics

### Activity Reporting (Actorsmartbook)
- Map events/occurrences to activity reports
- Capture participant age-group data
- Export in Actorsmartbook format (format TBD — pending credentials)
- Submission tracking and status

### Discord
- Member can link Discord account to profile
- Automatic role assignment when membership is active
- Role removal on expiry
- Outbound webhooks for event notifications (no WebSocket needed)

### Statistics
- Member growth over time
- Active/inactive/churn per year
- Event participation by section
- Revenue dashboard
- Export to CSV/Excel

---

## Phase 7 — Documents & Google Workspace

### Documents
Never built as a standalone module — superseded by Google Workspace Drive
sharing below (folder tree, access control, and versioning all come from
Drive itself instead of a custom implementation). The only document-related
code that exists is `app/Http/Controllers/DocumentController.php`, which just
streams accounting attachments (receipts/invoices) with a permission check —
unrelated to what was originally planned here.

### Google Workspace
- SSO via Google Socialite
- Account provisioning via Google Admin SDK when member joins (if granted)
- Not all members get accounts — toggled per member
- Drive share management: connect section/role to a Google Drive, set access levels
- Drive permissions synced when members join/leave sections or roles

---

## Phase 8 — Rester

### Shipping
- Carrier integration (PostNord / DHL)
- Shipping cost rules (weight, zone, free shipping threshold)
- Shipping address on orders
- Label generation
- Tracking number stored on fulfillment

---

## Admin areas

| Area | Who can access |
|---|---|
| Member management | `members.manage` permission |
| Economy / invoices | `accounting.manage` permission |
| Event management (all) | `events.manage-all` permission |
| Event management (own) | `events.manage-own` permission |
| Section management | `sections.manage` permission |
| Organizational role admin | `org-roles.manage` permission |
| Google Workspace admin | `workspace.manage` permission |
| System settings | `settings.manage` permission (super admin) |
| Auditor view | OrganizationalRole: Revisorer, current period |
| Nominating committee view | OrganizationalRole: Valberedning, current period |
