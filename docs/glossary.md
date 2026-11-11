# Membrum2 — Glossary

Terms you will come across in the Membrum2 codebase and documentation, in alphabetical order.

- Symbols, spaces and hyphens are ignored when sorting (`@can` is under C, `.env` under E).
- Swedish names in parentheses are the words used in the UI and in Swedish documentation.
- Class or model names are shown in `code` where relevant.

[A](#a) · [B](#b) · [C](#c) · [D](#d) · [E](#e) · [F](#f) · [G](#g) · [H](#h) · [I](#i) · [J](#j) · [K](#k) · [L](#l) · [M](#m) · [N](#n) · [O](#o) · [P](#p) · [Q](#q) · [R](#r) · [S](#s) · [T](#t) · [U](#u) · [V](#v) · [W](#w) · [X](#x) · [Z](#z)

---

## A

| Term | Explanation |
|---|---|
| **`abort_unless(…, 403)`** | Stops the page with "Forbidden" if the condition is not met. Used in `mount()` for permission checks. |
| **Accounting (bokföring)** | Recording the association's financial transactions: income, expenses and payments. The module lives under `/admin/bokforing` and covers the chart of accounts, journal entries, invoices, reconciliation and reports. |
| **Accounting inbox (bokföringsinkorg, `AccountingInbox…`)** | Email inbox where receipts and invoices are sent and imported as accounting documents. |
| **Actorsmartbook** | Borås municipality's system for activity reporting and grants. The integration is blocked pending an agreement with Vitec. |
| **Addon (tillägg, `EventAddon`)** | Optional extra purchased when registering for an event, e.g. a t-shirt in size S, M or L. |
| **Agenda item (dagordningspunkt, `MeetingAgendaItem`)** | An item to be discussed at a meeting. |
| **Alpine.js** | Small JS framework for browser-side logic, using `x-data`, `x-show` and `@click`. Ships with Livewire. |
| **Annual meeting (årsmöte)** | The association's highest decision-making meeting. Handled in the meetings module. |
| **API client (`ApiClient`)** | External application allowed to use the API with a `client_id` and `client_secret`. Access is controlled with scopes. Managed at `/admin/api-klienter`. |
| **Approval (attestering, `ApprovalRule`, `ApprovalLog`)** | Sign-off of e.g. invoices or expense claims before they are paid. Rules control who may approve. |
| **Armagedon** | The gaming association in Borås that the system is built for. |
| **Artisan** | Laravel's command-line tool, e.g. `php artisan migrate`. |
| **Audit log (`AuditLog`)** | Log of who changed what, when, and from which IP address. |
| **Auditor (revisor)** | Reviews the association's finances and the board's work. Mapped to the Spatie role `revisor`. |

## B

| Term | Explanation |
|---|---|
| **Bankgiro** | Swedish system for payments between bank accounts. |
| **Bank reconciliation (avstämning)** | Checking that the accounting matches the bank statement. |
| **BAS chart of accounts (BAS-kontoplan)** | The Swedish standard defining which accounting accounts exist. |
| **BgMax** | The file format Bankgirot uses for incoming payments. Parsed by `BgMaxParser`. |
| **Blade** | Laravel's HTML templating language (`.blade.php`), with `{{ $x }}`, `@if`, `@foreach` and `<x-component>`. |
| **Blade component** | Reusable piece of HTML used as `<x-name>`. Lives in `resources/views/components/`. |
| **Board (styrelse)** | The association's leadership. Mapped to the Spatie role `styrelse`. |
| **Booking (lokalbokning, `Booking`)** | Booking of the association's premises. |
| **Booking resource (`BookingResource`)** | Something that can be booked, e.g. a room. |
| **Bootstrap Icons** | The icon library, 2079 SVG files. Used as `<x-bootstrap-icon name="…" class="size-5" />`. |
| **Brand colors** | The association's colors: yellow (`brand`), orange (`brand-orange`), violet (`complement`) and dark (`brand-dark`). |
| **BSK** | Borås Spelkonvent. The convention's own event platform, a sister project on the same stack that Membrum2 integrates with via API. Its code is private. |
| **`.btn` / `.inp` / `.lbl`** | The project's own CSS classes for buttons, inputs and labels. Defined in `resources/css/app.css`. |
| **Budget (`Budget`, `BudgetLine`)** | Planned income and expenses per account, compared against the actual outcome. |

## C

| Term | Explanation |
|---|---|
| **`@can`** | Blade directive that only shows its content if the user has a given permission. |
| **Carbon** | PHP library for dates and times. The Swedish locale (`sv`) is used. |
| **Chart of accounts (kontoplan)** | See *BAS chart of accounts*. |
| **Check-in (incheckning)** | Registering attendance at an event, often by scanning a QR code. |
| **CogWorks** | The old membership system that data was imported from (`php artisan import:cogworks`). |
| **Component (Livewire)** | An interactive part of a page with its own state and methods. |
| **Composer** | Package manager for PHP, the equivalent of npm. Packages are listed in `composer.json`. |
| **`#[Computed]`** | Livewire attribute on a method that derives data. Accessed as `$this->members` in the view. |
| **Contact message (kontaktmeddelande, `ContactMessage`)** | Message sent through the contact form. |
| **Controller** | Class that receives a request and returns a response. Here mostly used for the API, downloads and webhooks. |
| **Cost center (kostnadsställe, `CostCenter`)** | Part of the organization that income and expenses are booked against, e.g. a section or an event. |
| **Cron / scheduled command** | Command that runs automatically at set times. Defined in `routes/console.php`. |
| **Customer invoice (kundfaktura)** | Invoice the association sends to someone. Do not call it "AR". |

## D

| Term | Explanation |
|---|---|
| **Dark mode** | Dark theme. Every color class must have a `dark:` variant. |
| **Debit / credit (debet / kredit)** | The two sides of double-entry bookkeeping. Every entry must balance. |
| **Dev Container** | Ready-made development environment in Docker that VS Code can open directly. |
| **Discord** | Chat platform. Members link their account and get roles automatically (synced nightly at 04:15). |
| **Discount / discount code (rabatt / rabattkod, `Discount`, `DiscountCode`)** | Percentage or fixed reduction on membership, events or the shop. Can require membership or a code. |

## E

| Term | Explanation |
|---|---|
| **Eloquent** | Laravel's ORM: database tables as PHP objects. |
| **`$embedded`** | The project's own prop that lets the same component be shown both as a standalone page and as a tab inside another page. |
| **Enum** | A fixed list of allowed values, e.g. status `active` or `pending`. Exposed via `GET /api/v1/meta`. |
| **`.env`** | Local configuration file with settings and secrets. Never committed. |
| **Event occurrence (evenemangstillfälle, `EventOccurrence`)** | A specific instance of an event, with its own time, location and capacity. |
| **Expense claim (utlägg, `ExpenseClaim`)** | When a member has paid for something on the association's behalf and is reimbursed. |
| **External organizer (extern arrangör)** | Someone outside the association who runs an event through it. |

## F

| Term | Explanation |
|---|---|
| **Factory** | Creates fake models with test data, used in tests. |
| **Family admin (familjeadmin)** | The person in a family group who pays and manages it. |
| **Family group (familjegrupp, `FamilyGroup`)** | Several members in one household with a shared payer and invoice. |
| **Fiscal year (räkenskapsår, `FiscalYear`)** | The accounting year, here the same as the calendar year. |
| **`fix-permissions.sh`** | Script to run on the server after every deploy. Sets the correct file permissions so the web server can read and write in `storage/` and `bootstrap/cache/`. |
| **`@fluxScripts`** | Loads the Flux JavaScript in the layout. |
| **Flux UI (Free)** | Component library for Livewire (`<flux:button>` and more). The free version lacks `flux:tabs`, `flux:tab`, `flux:option` and `size="lg"`. |
| **Focus ring** | Outline showing which element has keyboard focus. The standard classes are in `docs/design/brand.md`. |
| **`format_money()`** | Helper that displays an amount in öre as `1 250,00 kr`. |
| **Form builder (formulärbyggare, `Form`, `FormQuestion`, `FormSubmission`)** | Tool where admins build custom forms with 13 field types. Located at `/admin/formulär`. |
| **Fortify** | Laravel's package for login, registration, password reset and 2FA. |
| **Fulfillment (leverans, `OrderFulfillment`)** | Handling of a webshop order: picking, shipping or handing it over. |

## G

| Term | Explanation |
|---|---|
| **Gamification (`GamificationTask`, `MemberBadge`, `XpLog`)** | Game elements for members: tasks to complete, XP points and badges. |
| **GDPR** | The EU data protection regulation. The system has consent records, data export and account deletion. |
| **Google Drive (Shared Drive)** | Shared Drive folders linked to sections and roles, with access synced automatically. |
| **Google Workspace** | Google's services for the association. The system provisions accounts and manages Drive and password resets. |
| **Grace period** | Former auditors keep read access to the accounting for 6 months after their period ends. |
| **Graphic profile (grafisk profil)** | Style guide at `/grafiskprofil/light` and `/grafiskprofil/dark`. |

## H

| Term | Explanation |
|---|---|
| **Help texts (hjälptexter)** | Help content edited in admin and shown via `help-icon` and on `/hjalp`. |
| **Honorary member (hedersmedlem)** | Member who pays no fee. |

## I

| Term | Explanation |
|---|---|
| **IMAP** | Protocol for reading email. Used to import email into the system. |
| **Interest registration (intresseanmälan, `EventInterest`)** | When a member marks interest in an event without registering. |
| **ISO 20022** | International standard for bank files, used for bank imports. |
| **`isStaff()`** | Method on `User` that decides whether the user sees the admin navigation. |

## J

| Term | Explanation |
|---|---|
| **Journal entry (verifikation, `JournalEntry`)** | An accounting entry with date, amount, debit and credit lines, and supporting documents. |
| **Journal series (verifikationsserie, `JournalSeries`)** | Number series for journal entries, e.g. A1, A2 and so on. |
| **Journal voucher (bokföringsorder)** | Supporting document for a manual accounting entry. |

## K

| Term | Explanation |
|---|---|
| **Kiosk / scanning station (skanningsstation)** | Standalone screen for check-in or purchases. Uses the `scanner` layout. |

## L

| Term | Explanation |
|---|---|
| **Laravel** | The PHP framework the whole project is built on. |
| **Layout** | Page frame wrapping the content: `public`, `auth`, `scanner`, `onboarding` and `focused`. |
| **Linked role** | Discord feature that shows membership status in Discord. |
| **Livewire** | Framework that makes Blade pages interactive through server requests, without writing your own JS. |
| **Locale** | Language and format setting. Here `sv`, giving Swedish month names and Swedish formatting. |

## M

| Term | Explanation |
|---|---|
| **Magic link** | Login link sent by email, no password needed. |
| **Mailing (utskick, `Mailing`)** | Newsletters and bulk email to selected members (segmentation). Sent by `artisan mailings:process`. |
| **Mail log (`MailLog`)** | Log of emails the system has sent. |
| **Mail template (mailmall, `MailTemplate`)** | Email text stored in the database and edited in admin. There are 27 templates, sent via `MailTemplateService`. |
| **Meeting minutes (mötesprotokoll, `MeetingMinutesItem`)** | What is decided and recorded during a meeting. |
| **Meeting template (mötesmall, `MeetingTemplate`)** | Template for a recurring meeting, with a ready-made agenda. |
| **Member card (medlemskort, "Mitt kort")** | The member's digital membership card with a QR code, at `/mitt-kort`. |
| **Membership fee (medlemsavgift, `MembershipFee`)** | The annual fee, which varies by membership type and year. |
| **Membership period (medlemsperiod, `MembershipPeriod`)** | A membership for a specific year. |
| **Membership type (medlemstyp)** | Type of membership, e.g. standard, student, family or honorary. |
| **Middleware** | Code that runs before a request, e.g. `auth`, which requires login. |
| **Migration** | File that changes the database structure. System data is also added here, using `updateOrInsert`. |
| **Model** | A PHP class that corresponds to a database table, e.g. `Member`. Lives in `app/Models/`. |
| **`mount()`** | Runs when a Livewire component is created, roughly like a constructor. Permission checks go here. |
| **Municipality report (kommunrapport)** | Report to the municipality under Statistics. A manual replacement for Actorsmartbook. |
| **MySQL / SQLite** | Databases. MySQL in production, SQLite locally and in tests. |

## N

| Term | Explanation |
|---|---|
| **Named route** | A route with a name, so links are written `route('admin.members.index')` instead of a hard-coded URL. |
| **Nomination committee (valberedning)** | Group that proposes who should be elected to the board. |
| **Notice (notis, `Notice`)** | Message with a title, text and display period. Can be public or shown only to logged-in users. |

## O

| Term | Explanation |
|---|---|
| **OAuth** | Standard for logging in via another service, e.g. Google or Discord. |
| **Observer** | Code that runs automatically when a model is created, updated or deleted. |
| **OCR number** | Reference number on an invoice that lets the payment be matched automatically. |
| **Onboarding** | The flow a new member goes through the first time. Has its own layout. |
| **OpenAPI** | Standard format describing the API, in `public/api/openapi.yaml`. Must be updated when the API changes. |
| **Opening balance (ingående balans, `OpeningBalance`)** | Account balances at the start of a fiscal year. |
| **Organizational role (org-roll)** | Elected position in the association, e.g. board, auditors, nomination committee or BSK. |
| **ORM** | Object-Relational Mapping: working with the database through objects instead of SQL. |

## P

| Term | Explanation |
|---|---|
| **Pagination (`WithPagination`)** | Splitting long lists into pages, e.g. 25 members per page. |
| **Passkey (WebAuthn)** | Passwordless login with fingerprint, face recognition or a security key. |
| **Paxton (Net2)** | The door access system with RFID cards. Cards are synced from membrum2. |
| **Period lock (periodlåsning, `PeriodLock`)** | Locks an accounting period so it can no longer be changed. |
| **Period (organizational role)** | The time span a position is valid for. It moves through forming → active → reporting → closed. |
| **Permission** | A single right, e.g. `members.view`. Defined in `PermissionSeeder`. |
| **Pest** | The test framework. Run `php artisan test --compact`. |
| **PHP** | The server-side programming language, version 8.4. |
| **Pill nav** | Sub-navigation of rounded buttons that wrap onto new lines. The project's standard instead of tabs. |
| **Pint** | Formats PHP code automatically (`vendor/bin/pint --dirty`). |
| **Poll (omröstning, `Poll`)** | Vote in the system, with options and ballots. |
| **Price tier (prisnivå, `EventPriceTier`)** | Different prices for the same event, e.g. for members and non-members. |
| **Prop** | A value passed into a component from outside. |
| **Public holidays (helgdagar)** | Swedish public holidays are calculated automatically. In calendars only Sunday is red, and the week starts on Monday. |

## Q

| Term | Explanation |
|---|---|
| **QR code** | Code scanned with the camera, for check-in or verifying a member card. |
| **Queue (`QUEUE_CONNECTION=sync`)** | Queue for background jobs. Here jobs run immediately, since the web host has no queue workers. |

## R

| Term | Explanation |
|---|---|
| **Recurring booking (återkommande bokning, `RecurringBooking`)** | Booking that repeats, e.g. every Tuesday, and is generated automatically. |
| **Rental (uthyrning, `Rental`)** | When the association rents out equipment. Has its own item types, prices and waitlist. |
| **Resource (API Resource)** | Class that defines what the API's JSON response looks like. Lives in `app/Http/Resources/`. |
| **RFID** | Wireless cards or tags, used as access cards. |
| **Role (Spatie)** | A set of permissions assigned to users, e.g. `styrelse`. |
| **Route** | The link between a URL and the code that handles it. Defined in `routes/web.php` and `routes/api.php`. |

## S

| Term | Explanation |
|---|---|
| **Scanner** | See *Kiosk / scanning station*. |
| **Section (sektion, `Section`)** | Subgroup of the association, e.g. a game category. Sections form a tree with multiple levels. |
| **Section post (sektionsinlägg, `SectionPost`)** | Post in a section's feed. Can be pinned to the top. |
| **Section role (sektionsroll, `SectionRole`)** | Position within a section, e.g. chair or secretary. |
| **Seeder** | Script that fills the database with test data. In this project, development only. |
| **Service** | Class containing business logic, e.g. `DiscordService`. Lives in `app/Services/`. |
| **Service Provider** | Class that boots and configures parts of the app, e.g. `VoltServiceProvider`. |
| **Settlement** | Financial settlement with an external organizer after an event. |
| **Shared hosting** | The server is shared with others, so there are no WebSockets and no background processes. |
| **Shimmer** | Animated placeholder shown while something loads (`.shimmer-neutral`, `.shimmer-brand`). |
| **SIE / SIE4** | Swedish file format for exporting accounting data to other software. |
| **SMTP** | Protocol for sending email. |
| **Socialite** | Laravel's package for OAuth login. |
| **Spatie laravel-permission** | The package that handles roles and permissions. |
| **SSO** | Single Sign-On: logging in with an existing account, here Google or Discord. |
| **State** | A component's current data, i.e. its public properties such as `$search`. |
| **Stock adjustment (lagerjustering, `StockAdjustment`)** | Change to the stock level of a product variant, with a reason. |
| **Stripe** | Service for card payments. |
| **Submission (ärende, `Submission`)** | Proposal or motion to a meeting. |
| **Supplier invoice (leverantörsfaktura)** | Invoice the association receives from someone. Do not call it "AP". |
| **Survey (enkät, `Survey`)** | Questionnaire sent to members. |
| **Swish** | Swedish mobile payment service. |

## T

| Term | Explanation |
|---|---|
| **Tag (tagg, `Tag`, `MemberTag`)** | Label on members, which can have its own yearly prices. |
| **Tailwind CSS** | CSS framework where you style with classes directly in the HTML. Version 4 is used. |
| **Tournament (turnering, `Tournament`)** | Gaming tournament with teams, participants, phases, rounds, matches and standings. |
| **Two-factor authentication (2FA)** | Login with a password plus a one-time code. |

## U

| Term | Explanation |
|---|---|
| **`ui_theme`** | The user's chosen theme: `light`, `dark` or `system`. |
| **`updateOrInsert`** | Updates a row if it exists, otherwise creates it. Used in migrations for system data. |

## V

| Term | Explanation |
|---|---|
| **Variant (`ProductVariant`)** | A version of a product, e.g. size or color. Has its own stock level. |
| **`vendor/`** | Folder with installed PHP packages. Never edit anything there. |
| **Venue application (lokalansökan, `VenueApplication`)** | Application to use the association's premises. |
| **Vite** | Build tool that bundles CSS and JS (`npm run dev` / `npm run build`). |
| **Volt** | A way to write a Livewire component in a single file (PHP and HTML). Lives in `resources/views/livewire/`. |

## W

| Term | Explanation |
|---|---|
| **Waitlist (väntelista)** | Queue when an event or rental item is full. |
| **WCAG** | Accessibility guidelines, e.g. status must never be shown by color alone, and contrast must be sufficient. |
| **Webhook** | A service calls our server when something happens, e.g. Stripe reporting a payment. Also works the other way, e.g. Discord notifications. |
| **Webshop** | The association's shop with products, variants, cart and orders. |
| **`wire:click`** | Livewire attribute that calls a PHP method on click. |
| **`wire:model`** | Livewire attribute that binds a form field to a PHP property. |
| **`wire:navigate`** | Livewire attribute that lets links change page without a full reload. |
| **WSL** | Windows Subsystem for Linux. The repo lives in WSL, and Git should be run from there. |

## X

| Term | Explanation |
|---|---|
| **`x-cloak`** | Alpine attribute that hides an element until Alpine has started, so it doesn't flash on load. |

## Z

| Term | Explanation |
|---|---|
| **Zettle / SumUp** | Card terminals for in-person sales. Transactions are synced into the accounting. |
