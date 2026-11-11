# Data Model

All table names are in English, snake_case, plural. All column names in English, snake_case.

## Core: Users & Members

### `users`
Standard Laravel auth table, extended with:
- `ui_theme` enum(`light`, `dark`, `system`) default `system` — dark/light mode preference
- `cogworks_id` nullable string — for CogWorks import/sync
- `locale` default `sv`

### `members`
The membership record. A member may or may not have a user account (e.g. a small child can be a member without their own login).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK nullable | null = no account yet |
| `first_name` | string | |
| `last_name` | string | |
| `email` | string nullable | contact email (may differ from login email) |
| `phone` | string nullable | |
| `address`, `postal_code`, `city` | string nullable | |
| `date_of_birth` | date nullable | used for family age rules |
| `membership_type` | enum | `single`, `family`, `student`, `honorary` |
| `family_group_id` | FK nullable | |
| `status` | enum | `pending`, `active`, `inactive`, `expired`, `deceased` |
| `cogworks_id` | string nullable | for import and sync |
| `notes` | text nullable | internal admin notes |
| `timestamps` | | |
| `deleted_at` | timestamp nullable | soft delete |

### `membership_periods`
One row per member per year they hold active membership.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `member_id` | FK | |
| `year` | smallint | e.g. 2025 |
| `membership_type` | enum | snapshot at time of payment |
| `status` | enum | `pending_payment`, `active`, `expired`, `cancelled` |
| `activated_at` | timestamp nullable | when payment confirmed |
| `expires_at` | timestamp | typically Dec 31 of year |
| `invoice_id` | FK nullable | the invoice that activated this period |
| `timestamps` | | |

### `membership_fees`
Configurable fee levels per type and year.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `year` | smallint | |
| `membership_type` | enum | |
| `amount` | integer | in öre (SEK cents) |
| `timestamps` | | |

---

## Family Groups

### `family_groups`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `administrator_member_id` | FK | the member who manages the family |
| `timestamps` | | |

### `family_members` (pivot)
Pivot model `App\Models\Membership\FamilyMember` — used by `FamilyGroup::membersWithPivot()` and `pendingInvitations()` so `invited_at` is cast to Carbon.

| Column | Type | Notes |
|---|---|---|
| `family_group_id` | FK | |
| `member_id` | FK | |
| `role` | enum | `adult`, `child` |
| `has_own_account` | boolean | family admin decides if this person gets their own login |
| `status` | enum | `active`, `pending` (invited, not yet accepted) |
| `invitation_token` | string nullable unique | |
| `invited_at` | timestamp nullable | |

### `system_settings`
Key-value store for configurable system-wide parameters.

| Column | Type | Notes |
|---|---|---|
| `key` | string PK | e.g. `family.max_adults` |
| `value` | string | |
| `type` | enum | `string`, `integer`, `boolean`, `json` |
| `description` | text | human-readable explanation |

Default family settings:
- `family.max_adults` = `2`
- `family.max_children` = `5`
- `family.max_child_age` = `18`
- `membership.grace_days_bankgiro` = `30` — how many days pending status is allowed before cancellation
- `membership.auto_activate_on_payment` = `true`

---

## Roles and Permissions

Uses **Spatie laravel-permission** for the base permission/role layer.

### Organizational roles (board, auditors, nomination committee, BSK, etc.)

### `organizational_roles`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | e.g. `Styrelsen`, `Revisorer`, `Valberedning`, `BSK` |
| `slug` | string unique | |
| `description` | text nullable | |
| `cross_period_access` | boolean | true = members see data across all periods (Styrelse); false = only own period (Revisorer) |
| `timestamps` | | |

### `organizational_role_periods`
One period = one term of service. Calendar-year roles use `year`; custom groups use `custom_start`/`custom_end`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `organizational_role_id` | FK | |
| `name` | string | e.g. `Styrelsen 2025`, `BSK 2026–2028` |
| `period_type` | enum | `calendar_year`, `custom` |
| `year` | smallint nullable | for calendar_year type |
| `custom_start` | date nullable | for custom type |
| `custom_end` | date nullable | for custom type |
| `forming_start` | date nullable | when group can start preparing (before formal start) |
| `active_start` | date | formal start of term |
| `active_end` | date | formal end of term |
| `reporting_end` | date nullable | last date with reporting access after stepping down |
| `status` | enum | `forming`, `active`, `reporting`, `closed` — auto-computed but can be overridden |
| `timestamps` | | |

### `organizational_role_members`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `organizational_role_period_id` | FK | |
| `member_id` | FK | |
| `title` | string | e.g. `Ordförande`, `Ledamot`, `Suppleant` |
| `transition_role` | enum | `incoming`, `current`, `outgoing` |
| `active_from` | date nullable | for mid-term changes |
| `active_until` | date nullable | |
| `timestamps` | | |

---

## Sections (game categories)

### `sections`
Unlimited-depth tree using adjacency list (parent_id).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `parent_id` | FK nullable | self-referential |
| `name` | string | e.g. `Brädspel`, `Eurogames`, `Figurspel`, `Warhammer 40k` |
| `slug` | string unique | |
| `description` | text nullable | |
| `is_active` | boolean | |
| `sort_order` | integer | |
| `timestamps` | | |

### `section_memberships`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `section_id` | FK | |
| `member_id` | FK | |
| `joined_at` | date | |
| `left_at` | date nullable | |
| `timestamps` | | |

### `section_roles`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `section_id` | FK | |
| `member_id` | FK | |
| `title` | string | e.g. `Ordförande`, `Sekreterare` |
| `valid_from` | date | |
| `valid_until` | date nullable | |
| `timestamps` | | |

---

## Bookkeeping / Accounting (Phase 5)

Real double-entry bookkeeping, not a simple invoice/payment pair — this
superseded an earlier, simpler design. All models live under
`app/Models/Accounting/`; full column detail is in
`database/migrations/2026_04_12_152605_create_accounting_tables.php` and the
smaller accounting migrations alongside it. Amounts are always öre (integer).

| Table(s) | Purpose |
|---|---|
| `fiscal_years`, `period_locks` | Räkenskapsår + periodlåsning |
| `accounts`, `cost_centers` | Kontoplan (BAS) + kostnadsställen |
| `journal_entries`, `journal_lines`, `journal_series`, `journal_templates` | Dubbel bokföring — varje verifikation har flera rader som måste balansera. `journal_lines.superseded_at`/`superseded_by` — rättelser är alltid nya rader, aldrig hård radering |
| `invoices`, `invoice_lines`, `invoice_sequences` | Leverantörs- **och** kundfakturor (`type`: payable/receivable) i samma tabell |
| `vendors`, `customers` | Motparter för leverantörs- resp. kundfakturor |
| `bank_accounts`, `bank_transactions`, `bank_statement_imports` | Kontoutdrag, import och avstämning |
| `posting_rules` | Regler för att auto-kontera inkommande banktransaktioner/fakturor |
| `payment_terms`, `payment_providers`, `payment_cards`, `corporate_cards` | Betalningsvillkor och betalkortshantering |
| `swish_imports`/`swish_transactions`, `zettle_transactions`/`zettle_payouts`, `sumup_transactions`/`sumup_payouts` | Per-leverantör transaktionsimport för kortbetalningar |
| `budgets`, `budget_lines` | Budget per räkenskapsår och konto |
| `approval_rules`, `approval_logs` | Dualitetskontroll vid fakturagodkännande (se `accounting.approve`-behörigheten) |
| `opening_balances` | Ingående balanser vid systembyte/nytt räkenskapsår |
| `expense_claims` | Utläggsredovisning (separat från leverantörsfakturor) |
| `accounting_documents` | Bilagor (kvitton, fakturor) — streamas via `DocumentController`, aldrig publik URL |
| `accounting_inbox_emails`, `accounting_inbox_senders`, `accounting_inbox_items` | IMAP-inkorg för vidarebefordrade leverantörsfakturor |

No generic `payments` table exists — a payment is just a journal entry that
debits a bank/cash account and credits the invoice's account.

---

## Events

### `events`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `section_id` | FK nullable | owning section, null = association-wide or organizer-only |
| `is_association_wide` | boolean | true = annual meeting, etc. |
| `title` | string | |
| `description` | text | |
| `status` | enum | `draft`, `published`, `cancelled`, `completed` |
| `max_participants` | integer nullable | |
| `requires_membership` | boolean | must be active member to register |
| `timestamps` | | |
| `deleted_at` | timestamp nullable | soft delete |

### `event_organizers` (pivot: members responsible for an event)
| Column | Type | Notes |
|---|---|---|
| `event_id` | FK | |
| `member_id` | FK | |
| `role` | enum | `lead`, `co_organizer`, `contact` |

### `event_occurrences`
Time slots/sessions within an event.
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `event_id` | FK | |
| `starts_at` | datetime | |
| `ends_at` | datetime | |
| `location` | string nullable | room name or address |
| `max_participants` | integer nullable | overrides event-level if set |
| `timestamps` | | |

### `event_fees`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `event_id` | FK | |
| `name` | string | e.g. `Deltagaravgift`, `Turneringsavgift` |
| `amount` | integer | in öre; 0 = free |
| `member_only_price` | integer nullable | different price for members |
| `timestamps` | | |

### `event_addons`
Extra items purchasable with registration (mug, t-shirt, etc.).
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `event_id` | FK | |
| `name` | string | |
| `description` | text nullable | |
| `is_required` | boolean | must pick at least one variant |
| `max_per_registration` | integer | default 1 |
| `timestamps` | | |

### `event_addon_variants`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `event_addon_id` | FK | |
| `name` | string | e.g. `S`, `M`, `L`, `Blå` |
| `price` | integer | in öre |
| `stock` | integer nullable | null = unlimited |
| `timestamps` | | |

### `event_discounts`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `event_id` | FK | |
| `type` | enum | `early_bird`, `member`, `promo_code`, `group` |
| `value_type` | enum | `amount`, `percent` |
| `value` | integer | amount in öre or percent*100 |
| `promo_code` | string nullable | |
| `max_uses` | integer nullable | |
| `uses_count` | integer | |
| `valid_from` | datetime nullable | |
| `valid_until` | datetime nullable | |
| `requires_membership` | boolean | |
| `timestamps` | | |

### `event_prerequisites`
A registration to event B requires a registration to event A.
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `event_id` | FK | the event requiring a prerequisite |
| `required_event_id` | FK | the event that must be registered to |
| `description` | text nullable | explanation shown to user |

### `registrations`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `event_occurrence_id` | FK | |
| `member_id` | FK | |
| `status` | enum | `pending_payment`, `confirmed`, `waitlist`, `cancelled` |
| `invoice_id` | FK nullable | |
| `registered_at` | timestamp | |
| `cancelled_at` | timestamp nullable | |
| `timestamps` | | |

### `registration_addon_lines`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `registration_id` | FK | |
| `event_addon_variant_id` | FK | |
| `quantity` | integer | |
| `unit_price` | integer | snapshot at registration time |

### `external_organizers`
Third parties who charge fees through the association's system.
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | |
| `contact_email` | string | |
| `org_number` | string nullable | |
| `timestamps` | | |

### `external_fee_agreements`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `event_id` | FK | |
| `external_organizer_id` | FK | |
| `fee_type` | enum | `per_participant`, `fixed` |
| `fee_amount` | integer | in öre |
| `payout_method` | enum | `bankgiro`, `swish`, `invoice` |
| `payout_status` | enum | `pending`, `paid` |
| `payout_reference` | string nullable | |
| `timestamps` | | |

---

## Webshop

### `products`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | |
| `description` | text nullable | |
| `type` | enum | `physical`, `digital`, `membership_addon` |
| `is_event_addon` | boolean | can be used as an event addon |
| `product_category_id` | FK nullable | |
| `is_active` | boolean | |
| `timestamps` | | |

### `product_variants`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `product_id` | FK | |
| `name` | string | e.g. `Stor mugg`, `T-shirt M` |
| `sku` | string unique | |
| `price` | integer | in öre |
| `stock_quantity` | integer nullable | null = unlimited |
| `weight_grams` | integer nullable | for shipping |
| `timestamps` | | |

### `product_categories`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | |
| `slug` | string unique | |
| `parent_id` | FK nullable | self-referential |

### `orders`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `member_id` | FK nullable | null = guest order |
| `status` | enum | `pending`, `paid`, `processing`, `shipped`, `delivered`, `cancelled` |
| `invoice_id` | FK nullable | |
| `shipping_address` | json nullable | snapshot of address at order time |
| `fulfillment_status` | enum | `pending`, `processing`, `shipped`, `delivered` |
| `timestamps` | | |

### `order_lines`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `order_id` | FK | |
| `product_variant_id` | FK | |
| `quantity` | integer | |
| `unit_price` | integer | snapshot at order time |

---

## Documents

Never built as a standalone module (no `document_folders`/`documents`/
`document_accesses` tables exist) — superseded by the Google Workspace Drive
tables below, which provide folder-equivalent sharing without a custom
implementation. The only "document" table that exists is
`accounting_documents` (see Accounting above) — receipts/invoices, unrelated
to this.

---

## Google Workspace

Full detail: `database/migrations/2026_05_28_000003_create_workspace_accounts_table.php`
and siblings; `app/Models/WorkspaceAccount.php` etc.

### `workspace_accounts`
Polymorphic — an account can belong to a `Member` (`accountable_type`/`accountable_id`, string-compared not a strict morph map), not just one fixed owner type.
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `accountable_type` / `accountable_id` | morph | usually `'member'` |
| `email` | string | the provisioned Workspace address |
| `display_name`, `description` | string/text nullable | |
| `pending_deletion_at` | timestamp nullable | grace period before `workspace:purge-pending-deletions` actually deletes it |
| `timestamps` | | |

### `workspace_shared_accounts`
Shared/role-based mailboxes (e.g. kansli@, styrelse@) rather than one-per-member — these are what the admin "kansliinkorg" reads from.

### `workspace_delegates`
Gmail delegate access on a shared mailbox, synced from the real Gmail API state via `workspace:sync-delegates` (also supports automatic role-based delegation — see `GoogleWorkspaceService::syncRoleDelegates()`).

### `workspace_shared_drives`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `owner_type` / `owner_id` | morph | `Section` or `OrganizationalRole` |
| `name` | string | |
| `google_drive_id` | string | |
| `timestamps` | | |

### `workspace_drive_members`
Membership/access level on a shared drive, synced via `workspace:sync-drives`.

---

## Bookings & Venue Applications

Room/resource booking (not rentals of physical items — see Rentals below).
Models under `app/Models/Booking/`.

| Table | Purpose |
|---|---|
| `bookings` | A single booking of a `booking_resources` row by a member or section. `status` enum: `pending`, `confirmed`, `cancelled`, `rejected`. |
| `booking_resources` | Bookable rooms/resources, grouped by `booking_categories` |
| `booking_categories` | Grouping/approval-rule config for resources |
| `recurring_bookings` | Template that generates individual `bookings` rows on a schedule (`bookings:generate-recurring`) |
| `venue_applications` | Longer-form applications for external venue use, separate approval flow from plain bookings |
| `booking_activities` | Activity-type tags attached to a booking (what it's being used for) |

---

## Rentals

Physical item rentals (storage lockers etc.) — unrelated to room bookings above. Models under `app/Models/Rental/`.

| Table | Purpose |
|---|---|
| `rentals` | A member's assignment of a `rental_items` row |
| `rental_items` | Individual rentable items (e.g. a specific locker), grouped by `rental_item_types` |
| `rental_item_types` | Category of rentable item, with pricing via `rental_type_prices` |
| `rental_waitlists` | Queue for when no item of a requested type is free |

---

## Meetings (board meetings, AGM, etc.)

Models under `app/Models/Meeting/`.

| Table | Purpose |
|---|---|
| `meetings` | `status` enum: `draft`, `notice_sent`, `ongoing`, `pending_approval`, `approved`, `published`, `cancelled` |
| `meeting_templates`, `meeting_template_items`, `meeting_template_role_mappings` | Reusable agenda templates per meeting type, with role-based auto-assignment |
| `meeting_agenda_items` | Agenda points for a specific meeting (created from a template, then edited) |
| `meeting_minutes_items` | The actual protocol text per agenda item, written after the meeting |
| `meeting_attendees` | Attendance record |
| `meeting_documents` | Attachments (e.g. motions, appendices) |
| `meeting_tasks`, `meeting_task_assignees` | Action items assigned at a meeting, tracked to completion (admin UI: "Uppgifter") |

### `submissions`
Ärenden & motioner submitted to a meeting (member-facing: "Mina ärenden"). `type`: `issue`/`motion`. `status`: `submitted`, `under_review`, `on_agenda`, `resolved`, `rejected`. Links to `meeting_agenda_items` once placed on an agenda.

### `polls`, `poll_options`, `poll_ballots`
Generic polymorphic voting (used by meetings and submissions/motions alike, via `voteable_type`/`voteable_id`). `type`: `yes_no_abstain`/`yes_no`/`free`. `status`: `pending`/`open`/`closed`/`manual`.

---

## Surveys

| Table | Purpose |
|---|---|
| `surveys` | Question set + open/close window |
| `survey_invitations` | Per-recipient invitation + response tracking, sent via the `survey_invitation` mail template |

Shares its question/answer tables with the Form Builder below (a survey is
built from the same question primitives).

---

## Mailings (newsletters/utskick)

| Table | Purpose |
|---|---|
| `mailings` | One send — subject, body, target segment |
| `mailing_templates` | Reusable layouts for mailings (distinct from the transactional `mail_templates` system — see `docs/reference/mail-templates.md`) |
| `mailing_recipients` | Per-recipient send/open/click tracking |
| `mailing_unsubscribes`, `mailing_category_optouts` | Global and per-category unsubscribe state |

Sending happens via `artisan mailings:process` (cron, every 5 min) — no
queue daemon on shared hosting.

---

## Form Builder

Standalone, not tied to events — forms under `app/Models/Form/`.

| Table | Purpose |
|---|---|
| `forms` | A form definition, optionally linked to an event |
| `form_questions`, `form_question_options` | Questions and their options, 15 types (`App\Enums\QuestionType`) — 10 plain input types plus 5 "action" types that do something on submit: `product` (adds to cart), `event_selection`, `section_selection`, `membership`, `rental_waitlist` |
| `form_submissions`, `form_answers` | A fill-in and its per-question answers |

---

## CMS (Pages & Navigation)

| Table | Purpose |
|---|---|
| `pages` | Draft/published content split (`content` vs `content_draft`, `has_draft` flag) — see `App\Models\Content\Page`. Body supports `[tag]` shortcode widgets (`App\Services\WidgetService`) and Trix rich text |
| `navigation_items` | Tree of nav entries (`location`: topnav/footer), can point at a `route_name`, a raw `url`, or be a parent-only grouping label |

---

## Activity Reporting (Actorsmartbook / Borås Stad)

### `activity_reports`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `source_type` | string | `EventOccurrence`, `SectionMeeting`, `Other` |
| `source_id` | bigint nullable | polymorphic |
| `title` | string | |
| `activity_date` | date | |
| `duration_hours` | decimal(4,1) | |
| `location` | string nullable | |
| `status` | enum | `draft`, `submitted`, `accepted`, `rejected` |
| `rejection_reason` | text nullable | |
| `timestamps` | | |

### `activity_report_participant_counts`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `activity_report_id` | FK | |
| `age_group` | enum | `under_7`, `7_12`, `13_17`, `18_24`, `25_64`, `65_plus` |
| `gender` | enum nullable | `male`, `female`, `other` — if Actorsmartbook requires it |
| `count` | integer | |

### `actorsmartbook_submissions`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `activity_report_id` | FK | |
| `submitted_at` | timestamp | |
| `submitted_by` | FK | member_id |
| `external_reference` | string nullable | reference from Actorsmartbook |
| `status` | enum | `submitted`, `accepted`, `rejected` |
| `timestamps` | | |

---

## Wiki

Internal knowledge: routines, instructions, handovers. Access is decided in one place, `App\Support\WikiAccess`, which `WikiSpacePolicy`, `WikiPagePolicy` and the `readableBy($user)` scopes all use, so pages, search and the API always agree.

- **Read:** by the space's `read_access`, no permission needed — `public` anyone (also guests), `members` active association members, `officials` anyone with an active section role or active org-role term, `group` the owning group's active members. A space of a `private`/`secret` group is never readable outside the group. `wiki.view` reads every space (like `signing.view`).
- **Write (pages):** the owning group's active members (a group with `membership_requires_active` suspends lapsed members, as `Section::canAccess()`); association-wide spaces `wiki.edit`; `wiki.manage` everywhere. Creating and changing spaces: `wiki.manage`.
- Group membership is answered from `section_memberships` until #168 replaces sections with groups; org roles linked to a section are already synced into its memberships.

### `wiki_spaces`
One space per group, created with the group (`SectionObserver`). Groups from before the wiki got theirs from the migration `create_wiki_spaces_for_existing_groups`; `php artisan wiki:create-group-spaces` fills in any group that still lacks one. An empty space is deleted with its group; a group whose space has pages can't be deleted (restrict FK — deactivate it instead). Any number of association-wide spaces (`group_id` NULL), created on `/admin/wiki`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `group_id` | FK nullable unique | → `sections` today, `groups` after #168 (same ids). Restrict on delete. NULL = association-wide |
| `name` | string | |
| `slug` | string unique | |
| `description` | text nullable | |
| `read_access` | string | `WikiReadAccess`: `public` (no login), `members` (default), `officials`, `group` |
| `archived_at` | timestamp nullable | archived: hidden from lists and read-only (only `wiki.manage` writes), pages stay readable |
| `timestamps` | | |

### `wiki_pages`
Tree per space via `parent_id`. Soft deletes; a deleted page keeps its slug so it can be restored.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `wiki_space_id` | FK | cascade on delete |
| `parent_id` | FK nullable | self-referential, null on delete |
| `title` | string | |
| `slug` | string | unique per space (including soft-deleted pages) |
| `content` | longtext nullable | Trix HTML, sanitized on save (`WikiHtml`): only Trix's own `attachment…` classes kept. External images (`https://…`) are allowed, so that server can see who opens the page |
| `created_by` / `updated_by` | FK nullable | users, null on delete |
| `sort_order` | unsigned int | |
| `timestamps`, `deleted_at` | | |

### `wiki_page_revisions`
One row per save that creates a page or changes its title or content, written in the same transaction as the page (`WikiPage::save()`), so the history is never missing a version. Moving a page in the tree is not a revision. Never updated. Read access is the page's.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `wiki_page_id` | FK | cascade on delete (a soft-deleted page keeps its history) |
| `title` | string | title after the save |
| `content` | longtext nullable | sanitized HTML after the save |
| `user_id` | FK nullable | who saved, null on delete |
| `summary` | string nullable | "Beskriv ändringen", optional |
| `created_at` | timestamp | |

---

## GDPR

### `consent_records`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `member_id` | FK | |
| `consent_type` | string | e.g. `marketing_email`, `activity_reporting`, `photo_publication` |
| `granted` | boolean | |
| `granted_at` | timestamp | |
| `ip_address` | string nullable | |
| `timestamps` | | |

### `data_export_requests`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `member_id` | FK | |
| `requested_at` | timestamp | |
| `completed_at` | timestamp nullable | |
| `download_url` | string nullable | signed temporary URL |
| `status` | enum | `pending`, `processing`, `ready`, `expired` |

### `data_deletion_requests`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `member_id` | FK | |
| `requested_at` | timestamp | |
| `scheduled_for` | date | after any legally required retention period |
| `completed_at` | timestamp nullable | |
| `status` | enum | `pending`, `scheduled`, `completed`, `blocked` |
| `blocked_reason` | text nullable | e.g. "open invoice" |

---

---

## Discounts (Phase 2)

### `discounts`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | Admin label |
| `type` | enum | `percentage`, `fixed` |
| `value` | unsignedInteger | 0–100 for %, öre for fixed |
| `applies_to` | enum | `membership`, `event`, `shop`, `all` |
| `condition` | string nullable | `first_membership` — extensible |
| `min_subtotal` | unsignedInteger nullable | Öre — minimum order to apply |
| `max_discount` | unsignedInteger nullable | Öre — absolute discount cap |
| `max_pct_of_total` | tinyInteger nullable | 0–100 — cap as % of total (prevents over-discounting) |
| `valid_from` | date nullable | |
| `valid_until` | date nullable | |
| `max_uses` | unsignedInteger nullable | Total redemptions allowed |
| `max_uses_per_user` | unsignedInteger nullable | |
| `is_active` | boolean | |

### `discount_codes`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `discount_id` | FK | |
| `code` | string unique | e.g. `PRIZE-AB3X7` or `SOMMAR50` |
| `is_single_use` | boolean | true = prize/personal, false = shared campaign |
| `redeemed_by_member_id` | FK nullable | Set on redemption of single-use |
| `redeemed_at` | timestamp nullable | |

### `discount_uses`
Audit log of every applied discount.
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `discount_id` | FK | |
| `discount_code_id` | FK nullable | |
| `member_id` | FK nullable | |
| `discountable_type` / `discountable_id` | morph | MembershipPeriod, Order, EventRegistration |
| `original_amount` | unsignedInteger | Öre before discount |
| `discount_amount` | unsignedInteger | Öre actually discounted |

## E-signing (DocSign)

BankID e-signing via Inleed DocSign — see issue #134 for the API and flows. Models in `App\Models\Signing`.

### `signing_signers`
A signer registered with DocSign ("party"), reused across documents. Our `id` is sent as DocSign's `external_id`, so a retried create never duplicates.
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | Sent to DocSign as `external_id`, prefixed per installation (`externalId()`, e.g. `membrum.local:12`) since environments share one DocSign account |
| `user_id` | FK nullable | Any user, member or not (member via `user->member`). Null = external person, matched on name **plus** e-mail or phone (family members without own account can share an e-mail) |
| `name`, `company` | string | |
| `email` | string nullable | Lower-cased. DocSign requires e-mail **or** phone — enforced on save |
| `phone_number` | string(20) nullable | Normalized to `+46…` on save |
| `sign_method` | enum `SignMethod` | Same person with two methods = two rows |
| `provider_party_id` | string unique nullable | DocSign's party id, set once registered |
| `anonymized_at` | timestamp nullable | Set by `signing:prune-external`; name/contact replaced, row kept because requests reference it |

### `signing_requests`
| Column | Type | Notes |
|---|---|---|
| `signable_type` / `signable_id` | morph nullable | What it's for (meeting, document …); null for stand-alone requests |
| `name`, `comment` | string, text | `comment` is shown to signers |
| `reference` | string(6) nullable, indexed | First six hex of `sha1("{id}\|{created_at}")`. Sent as `name [#3f9a1c]`, since DocSign documents have no reference field; used to find a document after a timeout. Not unique — collisions are harmless |
| `status` | enum `SigningStatus` | |
| `provider` / `provider_document_id` | string | `docsign` + their id; unique together |
| `send_reminders`, `send_receipt` | boolean | |
| `is_demo` | boolean | Free test document, no real signing — on in every non-production environment |
| `callback_token_hash` | string(64) nullable | SHA-256 of the per-request callback token; plain token never stored |
| `signed_disk`, `signed_path` | string nullable | Signed PDF, downloaded and kept locally |
| `signed_sha256` | char(64) nullable | Hash recorded at download. DocSign's signed PDF has **no digital signature** (verified 2026-10-23), so this is our evidence the file hasn't changed since |
| `last_error` | text nullable | DocSign `message` when `success: false` |
| `created_by` | FK users nullable | |
| `sent_at`, `completed_at`, `cancelled_at`, `last_synced_at` | timestamp nullable | |

### `signing_request_files`
Attachments, sent to DocSign as base64 (never a public URL). `signing_request_id`, `name` (shown to signers), `disk`, `path`, `sort_order`.

### `signing_request_signers`
| Column | Type | Notes |
|---|---|---|
| `signing_request_id`, `signing_signer_id` | FK | Unique together |
| `role_label` | string nullable | e.g. "Justerare" |
| `sort_order` | smallint | |
| `signed_at`, `signed_by_name` | nullable | `signed_by_name` = name per BankID |
| `sign_url` | text nullable, **encrypted**, hidden | Personal signing link — whoever has it can sign |

DocSign's `signed_by_id` (personnummer) is **never stored**.

### `signing_request_events`
Append-only log: `signing_request_id`, `signing_request_signer_id` (nullable), `type` (enum `SigningEventType`), `payload` (JSON), `created_at`. Payloads are scrubbed of `signed_by_id`, `sign_url`, `api_key` and `base64_content` at any depth before saving.

---

## Enums summary

```
MemberStatus:        pending, active, inactive, expired, deceased
MembershipType:      single, family, student, honorary
FamilyMemberRole:    adult, child
PeriodType:          calendar_year, custom
RolePeriodStatus:    forming, active, reporting, closed
TransitionRole:      incoming, current, outgoing
AccountingInvoiceType:   payable, receivable
AccountingInvoiceStatus: draft, pending_approval, approved, posted, paid, cancelled
BookingStatus:       pending, confirmed, cancelled, rejected
MeetingStatus:       draft, notice_sent, ongoing, pending_approval, approved, published, cancelled
SubmissionStatus:    submitted, under_review, on_agenda, resolved, rejected
PollStatus:          pending, open, closed, manual
EventStatus:         draft, published, cancelled, completed
RegistrationStatus:  pending_payment, confirmed, waitlist, cancelled
OrganizerRole:       lead, co_organizer, contact
DiscountType:        early_bird, member, promo_code, group
ValueType:           amount, percent
FeeType:             per_participant, fixed
ProductType:         physical, digital, membership_addon
OrderStatus:         pending, paid, processing, shipped, delivered, cancelled
FulfillmentStatus:   pending, processing, shipped, delivered
ActivityStatus:      draft, submitted, accepted, rejected
AgeGroup:            under_7, 7_12, 13_17, 18_24, 25_64, 65_plus
ConsentType:         marketing_email, activity_reporting, photo_publication (extensible)
DataRequestStatus:   pending, processing, ready, expired, scheduled, completed, blocked
UiTheme:             light, dark, system
SigningStatus:       draft, pending, paused, completed, cancelled, failed
SignMethod:          bankid, email
SigningEventType:    created, sent, callback_received, signer_signed, synced, completed, paused, resumed, cancelled, error
```
