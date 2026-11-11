<?php

use App\Http\Controllers\Api\V1\AccountDeletionController;
use App\Http\Controllers\Api\V1\AccountingController;
use App\Http\Controllers\Api\V1\ApiClientController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\BookingResourceController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\SigningRequestController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DiscountAdminController;
use App\Http\Controllers\Api\V1\DiscountController;
use App\Http\Controllers\Api\V1\EventAdminController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\EventRegistrationController;
use App\Http\Controllers\Api\V1\ExpenseClaimController;
use App\Http\Controllers\Api\V1\FamilyController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\MailingController;
use App\Http\Controllers\Api\V1\MeetingAgendaItemController;
use App\Http\Controllers\Api\V1\MeetingController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\MembershipController;
use App\Http\Controllers\Api\V1\NavigationAdminController;
use App\Http\Controllers\Api\V1\NavigationController;
use App\Http\Controllers\Api\V1\OrgRoleController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PageAdminController;
use App\Http\Controllers\Api\V1\PageController;
use App\Http\Controllers\Api\V1\WikiPageController;
use App\Http\Controllers\Api\V1\WikiSpaceController;
use App\Http\Controllers\Api\V1\PaxtonController;
use App\Http\Controllers\Api\V1\PollController;
use App\Http\Controllers\Api\V1\RentalController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\ScannerRelayController;
use App\Http\Controllers\Api\V1\SectionController;
use App\Http\Controllers\Api\V1\ShopAdminController;
use App\Http\Controllers\Api\V1\ShopCartController;
use App\Http\Controllers\Api\V1\ShopCheckoutController;
use App\Http\Controllers\Api\V1\ShopProductController;
use App\Http\Controllers\Api\V1\SubmissionController;
use App\Http\Controllers\Api\V1\FormController;
use App\Http\Controllers\Api\V1\SurveyAdminController;
use App\Http\Controllers\Api\V1\SurveyController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\HelpController;
use App\Http\Controllers\Api\V1\HelpAdminController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\VendorController;
use App\Http\Controllers\Api\V1\NoticeController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\BookingCategoryController;
use App\Http\Controllers\Api\V1\BookingAdminController;
use App\Http\Controllers\Api\V1\RecurringBookingController;
use App\Http\Controllers\Api\V1\VenueApplicationController;
use App\Http\Controllers\Api\V1\GamificationController;
use App\Http\Controllers\Api\V1\TournamentController;
use App\Http\Controllers\Api\V1\StatisticsController;
use App\Http\Controllers\Api\V1\MeetingTemplateController;
use App\Http\Controllers\Api\V1\MailingTemplateController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Controllers\Api\V1\AccountingGapsController;
use App\Http\Controllers\Api\V1\AccountingConfigController;
use App\Http\Controllers\Api\V1\AccountingInboxController;
use App\Http\Controllers\Api\V1\BankImportController;
use App\Http\Controllers\Api\V1\MailTemplateController;
use App\Http\Controllers\Api\V1\MailLogController;
use App\Http\Controllers\Api\V1\RfidTagController;
use App\Http\Controllers\Api\V1\SystemSettingController;
use App\Http\Controllers\Api\V1\MembershipFeeController;
use App\Http\Controllers\Api\V1\AdminFamilyGroupController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\BookingStatisticsController;
use App\Http\Controllers\Api\V1\ApprovalRuleController;
use App\Http\Controllers\Api\V1\AccountingRevisionController;
use App\Http\Controllers\Api\V1\BudgetController;
use App\Http\Controllers\Api\V1\InboxMailboxController;
use App\Http\Controllers\Api\V1\AccountingDocumentController;
use App\Http\Controllers\Api\V1\MemberCardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — v1
|--------------------------------------------------------------------------
| Base URL: /api/v1
| Auth: Laravel Sanctum (Bearer token for external clients, cookie for SPA)
|
| Documentation: /api-docs (Swagger UI)
| OpenAPI spec:  /api/openapi.yaml
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    // ── Public endpoints ──────────────────────────────────────────────────

    Route::get('meta', [MetaController::class, 'index'])->name('meta');

    Route::prefix('auth')->name('auth.')->middleware('throttle:api-auth')->group(function () {
        Route::post('tokens', [AuthController::class, 'login'])->name('login');
        Route::post('client-tokens', [ApiClientController::class, 'token'])->name('client-tokens');
    });

    Route::prefix('pages')->name('pages.')->group(function () {
        Route::get('/', [PageController::class, 'index'])->name('index');
        Route::get('{slug}', [PageController::class, 'show'])->name('show');
    });

    Route::get('navigation', [NavigationController::class, 'index'])->name('navigation.index');

    // Wiki — public (auth optional; a Bearer token unlocks what that user may read)
    Route::prefix('wiki/spaces')->name('wiki.spaces.')->group(function () {
        Route::get('/', [WikiSpaceController::class, 'index'])->name('index');
        Route::get('{slug}', [WikiSpaceController::class, 'show'])->name('show');
        Route::get('{slug}/pages', [WikiPageController::class, 'index'])->name('pages.index');
        Route::get('{slug}/pages/{pageSlug}', [WikiPageController::class, 'show'])->name('pages.show');
        Route::get('{slug}/pages/{pageSlug}/revisions', [WikiPageController::class, 'revisions'])->name('pages.revisions.index');
        Route::get('{slug}/pages/{pageSlug}/revisions/{revisionId}', [WikiPageController::class, 'revision'])->whereNumber('revisionId')->name('pages.revisions.show');
    });

    // Help — public (auth optional; auth unlocks show_auth sections)
    Route::prefix('help')->name('help.')->group(function () {
        Route::get('/', [HelpController::class, 'index'])->name('index');
        Route::get('{groupSlug}', [HelpController::class, 'show'])->name('show');
    });

    // Events — list and detail are public (visibility rules enforced in controller)
    Route::prefix('events')->name('events.')->group(function () {
        Route::get('/', [EventController::class, 'index'])->name('index');
        Route::get('{slug}', [EventController::class, 'show'])->name('show');
    });

    // Surveys — accessible via invitation token or open-{id} shorthand
    Route::prefix('surveys')->name('surveys.')->group(function () {
        Route::get('{token}', [SurveyController::class, 'show'])->name('show');
        Route::post('{token}/responses', [SurveyController::class, 'store'])->name('responses.store');
    });

    // Notices — public active notices (guests see is_public only)
    Route::get('notices', [NoticeController::class, 'index'])->name('notices.index');

    // Booking categories — public list (active only)
    Route::get('booking-categories', [BookingCategoryController::class, 'index'])->name('booking-categories.index');

    // Membership fees — public (needed for self-registration flow)
    Route::get('membership-fees', [MembershipFeeController::class, 'index'])->name('membership-fees.index');

    // Member card QR verification — public, HMAC is the security
    Route::post('verify-member-qr', [MemberCardController::class, 'verify'])->name('member-card.verify');

    // Discount codes — public validate endpoint (auth optional, member eligibility checked if logged in)
    Route::post('discounts/validate', [DiscountController::class, 'validate'])->name('discounts.validate');

    // Shop products — public (member-only products filtered by auth)
    Route::prefix('shop')->name('shop.')->group(function () {
        Route::prefix('products')->name('products.')->group(function () {
            Route::get('/', [ShopProductController::class, 'index'])->name('index');
            Route::get('{slug}', [ShopProductController::class, 'show'])->name('show');
        });

        // Cart — session-based (works for SPA cookie auth; Bearer token clients should manage state)
        Route::prefix('cart')->name('cart.')->group(function () {
            Route::get('/', [ShopCartController::class, 'show'])->name('show');
            Route::delete('/', [ShopCartController::class, 'clear'])->name('clear');
            Route::post('items', [ShopCartController::class, 'store'])->name('items.store');
            Route::patch('items/{id}', [ShopCartController::class, 'update'])->name('items.update');
            Route::delete('items/{id}', [ShopCartController::class, 'destroy'])->name('items.destroy');
        });

        // Checkout — convert cart to order and initiate payment
        Route::post('checkout', [ShopCheckoutController::class, 'store'])->name('checkout');
    });

    // ── Authenticated endpoints ───────────────────────────────────────────

    Route::middleware(['auth:sanctum', 'api.access'])->group(function () {

        // Auth
        Route::prefix('auth')->name('auth.')->group(function () {
            Route::delete('tokens/current', [AuthController::class, 'logout'])->name('logout');
            Route::get('me', [AuthController::class, 'me'])->name('me');
        });

        // Own membership
        Route::prefix('membership')->name('membership.')->group(function () {
            Route::get('/', [MembershipController::class, 'show'])->name('show');
            Route::post('/', [MembershipController::class, 'store'])->name('store');
            Route::patch('/', [MembershipController::class, 'update'])->name('update');
        });

        // Own orders / invoices
        Route::prefix('orders')->name('orders.')->group(function () {
            Route::get('/', [OrderController::class, 'index'])->name('index');
            Route::get('{order}', [OrderController::class, 'show'])->name('show');
        });

        // Own active rentals + admin all-rentals view
        Route::get('rentals', [RentalController::class, 'index'])->name('rentals.index');
        Route::get('admin/rentals', [RentalController::class, 'adminIndex'])->name('admin.rentals.index');

        // Own family group
        Route::prefix('family')->name('family.')->group(function () {
            Route::get('/', [FamilyController::class, 'show'])->name('show');
            Route::delete('membership', [FamilyController::class, 'leave'])->name('leave');
            Route::post('invitation/accept', [FamilyController::class, 'acceptInvitation'])->name('invitation.accept');
            Route::delete('invitation', [FamilyController::class, 'declineInvitation'])->name('invitation.decline');
        });

        // GDPR account deletion requests (self-service)
        Route::prefix('account-deletion-requests')->name('account-deletion-requests.')->group(function () {
            Route::post('/', [AccountDeletionController::class, 'store'])->name('store');
            Route::delete('pending', [AccountDeletionController::class, 'cancel'])->name('cancel');
        });

        // Events — registration, participant list, check-in (auth required)
        Route::prefix('events')->name('events.')->group(function () {
            Route::post('{slug}/registrations', [EventRegistrationController::class, 'store'])
                ->name('registrations.store');
            Route::get('{slug}/registrations', [EventRegistrationController::class, 'index'])
                ->name('registrations.index');
            Route::post('{slug}/check-in', [EventRegistrationController::class, 'checkIn'])
                ->name('check-in');
            Route::post('{slug}/registrations/{registration}/approve', [EventRegistrationController::class, 'approve'])
                ->name('registrations.approve');
            Route::post('{slug}/registrations/{registration}/reject', [EventRegistrationController::class, 'reject'])
                ->name('registrations.reject');
        });

        // Paxton Net2 sync agent (requires tags.manage permission)
        Route::middleware('can:tags.manage')->prefix('paxton')->name('paxton.')->group(function () {
            Route::get('sync-queue', [PaxtonController::class, 'syncQueue'])->name('sync-queue');
            Route::post('sync-confirm', [PaxtonController::class, 'syncConfirm'])->name('sync-confirm');
        });

        // Admin: member management (requires members.manage permission)
        Route::middleware('can:members.manage')->group(function () {
            Route::prefix('members')->name('members.')->group(function () {
                Route::get('/', [MemberController::class, 'index'])->name('index');
                Route::post('/', [MemberController::class, 'store'])->name('store');
                Route::get('{member}', [MemberController::class, 'show'])->name('show');
                Route::patch('{member}', [MemberController::class, 'update'])->name('update');
                Route::delete('{member}', [MemberController::class, 'destroy'])->name('destroy');
            });

            Route::prefix('admin/account-deletion-requests')->name('admin.account-deletion-requests.')->group(function () {
                Route::get('/', [AccountDeletionController::class, 'index'])->name('index');
                Route::post('{deletionRequest}/approve', [AccountDeletionController::class, 'approve'])->name('approve');
                Route::post('{deletionRequest}/reject', [AccountDeletionController::class, 'reject'])->name('reject');
            });
        });

        // Meetings
        Route::prefix('meetings')->name('meetings.')->group(function () {
            Route::get('/', [MeetingController::class, 'index'])->name('index');
            Route::post('/', [MeetingController::class, 'store'])->name('store');
            Route::get('{meeting}', [MeetingController::class, 'show'])->name('show');
            Route::patch('{meeting}', [MeetingController::class, 'update'])->name('update');

            // Attendee check-in
            Route::post('{meeting}/attendees/check-in', [MeetingController::class, 'checkIn'])->name('attendees.check-in');

            // Agenda items
            Route::prefix('{meeting}/agenda-items')->name('agenda-items.')->group(function () {
                Route::get('/', [MeetingAgendaItemController::class, 'index'])->name('index');
                Route::post('/', [MeetingAgendaItemController::class, 'store'])->name('store');
                Route::patch('{agendaItem}', [MeetingAgendaItemController::class, 'update'])->name('update');
                Route::delete('{agendaItem}', [MeetingAgendaItemController::class, 'destroy'])->name('destroy');
            });
        });

        // Submissions (ärenden & motioner)
        Route::prefix('submissions')->name('submissions.')->group(function () {
            Route::get('/', [SubmissionController::class, 'index'])->name('index');
            Route::post('/', [SubmissionController::class, 'store'])->name('store');
            Route::get('{submission}', [SubmissionController::class, 'show'])->name('show');
            Route::patch('{submission}', [SubmissionController::class, 'update'])->name('update');
        });

        // Polls / omröstningar
        Route::prefix('polls')->name('polls.')->group(function () {
            Route::get('pending', [PollController::class, 'pending'])->name('pending');
            Route::get('{poll}', [PollController::class, 'show'])->name('show');
            Route::post('{poll}/vote', [PollController::class, 'vote'])->name('vote');
            Route::post('/', [PollController::class, 'store'])->name('store');
            Route::post('{poll}/open', [PollController::class, 'open'])->name('open');
            Route::post('{poll}/close', [PollController::class, 'close'])->name('close');
            Route::post('{poll}/close-manual', [PollController::class, 'closeManual'])->name('close-manual');
        });

        // Mailings
        Route::prefix('mailings')->name('mailings.')->group(function () {
            Route::get('/', [MailingController::class, 'index'])->name('index');
            Route::post('/', [MailingController::class, 'store'])->name('store');
            Route::get('{mailing}', [MailingController::class, 'show'])->name('show');
            Route::patch('{mailing}', [MailingController::class, 'update'])->name('update');
            Route::post('{mailing}/send', [MailingController::class, 'send'])->name('send');
            Route::post('{mailing}/cancel', [MailingController::class, 'cancel'])->name('cancel');
            Route::delete('{mailing}', [MailingController::class, 'destroy'])->name('destroy');
        });

        // Expense claims / utläggsredovisning
        Route::prefix('expense-claims')->name('expense-claims.')->group(function () {
            Route::get('/', [ExpenseClaimController::class, 'index'])->name('index');
            Route::post('/', [ExpenseClaimController::class, 'store'])->name('store');
            Route::get('{expenseClaim}', [ExpenseClaimController::class, 'show'])->name('show');
            Route::post('{expenseClaim}/approve', [ExpenseClaimController::class, 'approve'])->name('approve');
            Route::post('{expenseClaim}/reject', [ExpenseClaimController::class, 'reject'])->name('reject');
        });

        // Bookings / lokalbokningar
        Route::prefix('bookings')->name('bookings.')->group(function () {
            Route::get('/', [BookingController::class, 'index'])->name('index');
            Route::post('/', [BookingController::class, 'store'])->name('store');
            Route::get('{booking}', [BookingController::class, 'show'])->name('show');
            Route::patch('{booking}', [BookingController::class, 'update'])->name('update');
            Route::delete('{booking}', [BookingController::class, 'destroy'])->name('destroy');
        });

        // Booking resources (lokaler / rooms)
        Route::prefix('booking-resources')->name('booking-resources.')->group(function () {
            Route::get('/', [BookingResourceController::class, 'index'])->name('index');
            Route::get('{bookingResource}', [BookingResourceController::class, 'show'])->name('show');
        });

        // Wiki pages — writing (reading is public, above)
        Route::prefix('wiki/spaces/{slug}/pages')->name('wiki.spaces.pages.')->group(function () {
            Route::post('/', [WikiPageController::class, 'store'])->name('store');
            Route::patch('{pageSlug}', [WikiPageController::class, 'update'])->name('update');
            Route::delete('{pageSlug}', [WikiPageController::class, 'destroy'])->name('destroy');
        });

        // Sections
        Route::prefix('sections')->name('sections.')->group(function () {
            Route::get('/', [SectionController::class, 'index'])->name('index');
            Route::post('/', [SectionController::class, 'store'])->name('store');
            Route::get('{section}', [SectionController::class, 'show'])->name('show');
            Route::patch('{section}', [SectionController::class, 'update'])->name('update');
            Route::delete('{section}', [SectionController::class, 'destroy'])->name('destroy');
        });

        // Roles & permissions
        Route::prefix('roles')->name('roles.')->group(function () {
            Route::get('/', [RoleController::class, 'index'])->name('index');
            Route::post('/', [RoleController::class, 'store'])->name('store');
            Route::get('{role}', [RoleController::class, 'show'])->name('show');
            Route::patch('{role}', [RoleController::class, 'update'])->name('update');
            Route::delete('{role}', [RoleController::class, 'destroy'])->name('destroy');
        });
        Route::put('users/{user}/roles', [RoleController::class, 'syncUserRoles'])->name('users.roles.sync');

        // Accounting
        Route::prefix('accounting')->name('accounting.')->group(function () {
            Route::get('fiscal-years', [AccountingController::class, 'fiscalYears'])->name('fiscal-years');
            Route::get('fiscal-years/{fiscalYear}/opening-balances', [AccountingController::class, 'openingBalances'])->name('fiscal-years.opening-balances.index');
            Route::put('fiscal-years/{fiscalYear}/opening-balances', [AccountingController::class, 'saveOpeningBalances'])->name('fiscal-years.opening-balances.save');
            // SIE4 export / import
            Route::get('fiscal-years/{fiscalYear}/sie4',  [AccountingController::class, 'exportSie4'])->name('sie4.export');
            Route::post('fiscal-years/{fiscalYear}/sie4', [AccountingController::class, 'importSie4'])->name('sie4.import');
            // Dashboard stats
            Route::get('dashboard', [AccountingController::class, 'dashboard'])->name('dashboard');
            Route::get('accounts', [AccountingController::class, 'accounts'])->name('accounts.index');
            Route::post('accounts', [AccountingController::class, 'storeAccount'])->name('accounts.store');
            Route::patch('accounts/{account}', [AccountingController::class, 'updateAccount'])->name('accounts.update');
            Route::delete('accounts/{account}', [AccountingController::class, 'destroyAccount'])->name('accounts.destroy');
            Route::get('journal-entries', [AccountingController::class, 'journalEntries'])->name('journal-entries.index');
            Route::post('journal-entries', [AccountingController::class, 'storeJournalEntry'])->name('journal-entries.store');
            Route::get('journal-entries/{journalEntry}', [AccountingController::class, 'showJournalEntry'])->name('journal-entries.show');
            Route::get('vendors', [VendorController::class, 'index'])->name('vendors.index');
            Route::get('vendors/{vendor}', [VendorController::class, 'show'])->name('vendors.show');
            Route::post('vendors', [VendorController::class, 'store'])->name('vendors.store');
            Route::patch('vendors/{vendor}', [VendorController::class, 'update'])->name('vendors.update');
            Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
            Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
            Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
            Route::patch('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
            Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
            Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
            Route::patch('invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
            Route::post('invoices/{invoice}/approve', [InvoiceController::class, 'approve'])->name('invoices.approve');
            Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])->name('invoices.mark-paid');
            Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
            Route::post('receipt-scan', [InvoiceController::class, 'receiptScan'])->name('receipt-scan');
            Route::post('invoices/import-zip', [InvoiceController::class, 'importZip'])->name('invoices.import-zip');
        });

        // Admin: events (all statuses, full CRUD)
        Route::prefix('admin/events')->name('admin.events.')->group(function () {
            Route::get('/', [EventAdminController::class, 'index'])->name('index');
            Route::post('/', [EventAdminController::class, 'store'])->name('store');
            Route::patch('{event}', [EventAdminController::class, 'update'])->name('update');
            Route::post('{event}/publish', [EventAdminController::class, 'publish'])->name('publish');
            Route::post('{event}/cancel', [EventAdminController::class, 'cancel'])->name('cancel');
            Route::post('{event}/occurrences', [EventAdminController::class, 'storeOccurrence'])->name('occurrences.store');
            Route::patch('{event}/occurrences/{occurrence}', [EventAdminController::class, 'updateOccurrence'])->name('occurrences.update');
            Route::delete('{event}/occurrences/{occurrence}', [EventAdminController::class, 'destroyOccurrence'])->name('occurrences.destroy');
            Route::post('{event}/price-tiers', [EventAdminController::class, 'storePriceTier'])->name('price-tiers.store');
            Route::patch('{event}/price-tiers/{priceTier}', [EventAdminController::class, 'updatePriceTier'])->name('price-tiers.update');
            Route::delete('{event}/price-tiers/{priceTier}', [EventAdminController::class, 'destroyPriceTier'])->name('price-tiers.destroy');
            Route::post('{event}/addons', [EventAdminController::class, 'storeAddon'])->name('addons.store');
            Route::patch('{event}/addons/{addon}', [EventAdminController::class, 'updateAddon'])->name('addons.update');
            Route::delete('{event}/addons/{addon}', [EventAdminController::class, 'destroyAddon'])->name('addons.destroy');
            // Admission
            Route::get('{event}/admission', [EventAdminController::class, 'admissionSummary'])->name('admission.summary');
            Route::post('{event}/admission/approve', [EventAdminController::class, 'approveRegistrations'])->name('admission.approve');
            Route::post('{event}/admission/reject', [EventAdminController::class, 'rejectRegistrations'])->name('admission.reject');
            Route::post('{event}/admission/admit-waitlist', [EventAdminController::class, 'admitFromWaitlist'])->name('admission.admit-waitlist');
            Route::post('{event}/admission/walkin', [EventAdminController::class, 'addWalkin'])->name('admission.walkin');
            // Check-in
            Route::post('{event}/occurrences/{occurrence}/check-in', [EventAdminController::class, 'checkIn'])->name('occurrences.check-in');
        });

        // Scanner relay — lets a paired device feed raw scans into the browser-based
        // scanner kiosk instead of checking in directly itself.
        Route::prefix('scanner-relay')->name('scanner-relay.')->group(function () {
            Route::post('{session}/scan', [ScannerRelayController::class, 'scan'])->name('scan');
            Route::post('{session}/heartbeat', [ScannerRelayController::class, 'heartbeat'])->name('heartbeat');
        });

        // Forms admin
        Route::prefix('admin/forms')->name('admin.forms.')->group(function () {
            Route::get('/', [FormController::class, 'index'])->name('index');
            Route::post('/', [FormController::class, 'store'])->name('store');
            Route::get('{form}', [FormController::class, 'show'])->name('show');
            Route::put('{form}', [FormController::class, 'update'])->name('update');
            Route::delete('{form}', [FormController::class, 'destroy'])->name('destroy');
        });

        // Surveys admin
        Route::prefix('admin/surveys')->name('admin.surveys.')->group(function () {
            Route::get('/', [SurveyAdminController::class, 'index'])->name('index');
            Route::post('/', [SurveyAdminController::class, 'store'])->name('store');
            Route::get('{survey}', [SurveyAdminController::class, 'show'])->name('show');
            Route::patch('{survey}', [SurveyAdminController::class, 'update'])->name('update');
            Route::delete('{survey}', [SurveyAdminController::class, 'destroy'])->name('destroy');
            Route::get('{survey}/responses', [SurveyAdminController::class, 'responses'])->name('responses');
        });

        // Shop admin
        Route::prefix('admin/shop')->name('admin.shop.')->group(function () {
            Route::prefix('products')->name('products.')->group(function () {
                Route::get('/', [ShopAdminController::class, 'productIndex'])->name('index');
                Route::post('/', [ShopAdminController::class, 'productStore'])->name('store');
                Route::patch('{product}', [ShopAdminController::class, 'productUpdate'])->name('update');
                Route::delete('{product}', [ShopAdminController::class, 'productDestroy'])->name('destroy');
                Route::post('{product}/variants', [ShopAdminController::class, 'variantStore'])->name('variants.store');
                Route::patch('{product}/variants/{variant}', [ShopAdminController::class, 'variantUpdate'])->name('variants.update');
                Route::delete('{product}/variants/{variant}', [ShopAdminController::class, 'variantDestroy'])->name('variants.destroy');
            });
            Route::prefix('orders')->name('orders.')->group(function () {
                Route::get('/', [ShopAdminController::class, 'orderIndex'])->name('index');
                Route::get('{order}', [ShopAdminController::class, 'orderShow'])->name('show');
                Route::patch('{order}', [ShopAdminController::class, 'orderUpdate'])->name('update');
            });
        });

        // Rentals admin write
        Route::post('admin/rentals', [RentalController::class, 'adminStore'])->name('admin.rentals.store');
        Route::patch('admin/rentals/{rental}', [RentalController::class, 'adminUpdate'])->name('admin.rentals.update');

        // Rental item types & year pricing (admin)
        Route::prefix('admin/rental-item-types')->name('admin.rental-item-types.')->group(function () {
            Route::get('/', [RentalController::class, 'adminItemTypes'])->name('index');
            Route::post('/', [RentalController::class, 'storeItemType'])->name('store');
            Route::patch('{type}', [RentalController::class, 'updateItemType'])->name('update');
            Route::get('{type}/prices', [RentalController::class, 'indexPrices'])->name('prices.index');
            Route::post('{type}/prices', [RentalController::class, 'storePrice'])->name('prices.store');
            Route::patch('{type}/prices/{price}', [RentalController::class, 'updatePrice'])->name('prices.update');
        });

        // Discounts admin
        Route::prefix('admin/discounts')->name('admin.discounts.')->group(function () {
            Route::get('/', [DiscountAdminController::class, 'index'])->name('index');
            Route::post('/', [DiscountAdminController::class, 'store'])->name('store');
            Route::get('{discount}', [DiscountAdminController::class, 'show'])->name('show');
            Route::patch('{discount}', [DiscountAdminController::class, 'update'])->name('update');
            Route::delete('{discount}', [DiscountAdminController::class, 'destroy'])->name('destroy');
            Route::post('{discount}/codes', [DiscountAdminController::class, 'storeCode'])->name('codes.store');
            Route::delete('{discount}/codes/{code}', [DiscountAdminController::class, 'destroyCode'])->name('codes.destroy');
            Route::get('{discount}/codes/zip', [DiscountAdminController::class, 'downloadCodesZip'])->name('codes.zip');
            Route::get('{discount}/codes/{code}/png', [DiscountAdminController::class, 'downloadCodePng'])->name('code.png');
        });

        // Pages admin
        Route::prefix('admin/pages')->name('admin.pages.')->group(function () {
            Route::get('/', [PageAdminController::class, 'index'])->name('index');
            Route::post('/', [PageAdminController::class, 'store'])->name('store');
            Route::patch('{page}', [PageAdminController::class, 'update'])->name('update');
            Route::post('{page}/publish', [PageAdminController::class, 'publish'])->name('publish');
            Route::post('{page}/unpublish', [PageAdminController::class, 'unpublish'])->name('unpublish');
            Route::delete('{page}', [PageAdminController::class, 'destroy'])->name('destroy');
        });

        // Navigation admin
        Route::prefix('admin/navigation')->name('admin.navigation.')->group(function () {
            Route::post('/', [NavigationAdminController::class, 'store'])->name('store');
            Route::patch('{navigationItem}', [NavigationAdminController::class, 'update'])->name('update');
            Route::delete('{navigationItem}', [NavigationAdminController::class, 'destroy'])->name('destroy');
        });

        // Tags
        Route::prefix('tags')->name('tags.')->group(function () {
            Route::get('/', [TagController::class, 'index'])->name('index');
            Route::post('/', [TagController::class, 'store'])->name('store');
            Route::delete('{tag}', [TagController::class, 'destroy'])->name('destroy');
        });

        // E-signing (DocSign)
        Route::prefix('admin/signing-requests')->name('admin.signing-requests.')->group(function () {
            Route::get('/', [SigningRequestController::class, 'index'])->name('index');
            Route::post('/', [SigningRequestController::class, 'store'])->name('store');
            Route::get('{signingRequest}', [SigningRequestController::class, 'show'])->name('show');
            Route::post('{signingRequest}/pause', [SigningRequestController::class, 'pause'])->name('pause');
            Route::post('{signingRequest}/resume', [SigningRequestController::class, 'resume'])->name('resume');
            Route::post('{signingRequest}/cancel', [SigningRequestController::class, 'cancel'])->name('cancel');
        });

        // Contact messages
        Route::prefix('contact-messages')->name('contact-messages.')->group(function () {
            Route::get('/', [ContactController::class, 'index'])->name('index');
            Route::get('{contactMessage}', [ContactController::class, 'show'])->name('show');
            Route::patch('{contactMessage}', [ContactController::class, 'update'])->name('update');
        });

        // Org roles (read)
        Route::prefix('org-roles')->name('org-roles.')->group(function () {
            Route::get('/', [OrgRoleController::class, 'index'])->name('index');
            Route::get('{orgRole}', [OrgRoleController::class, 'show'])->name('show');
        });

        // Notices admin (CRUD)
        Route::prefix('admin/notices')->name('admin.notices.')->group(function () {
            Route::get('/', [NoticeController::class, 'adminIndex'])->name('index');
            Route::post('/', [NoticeController::class, 'store'])->name('store');
            Route::patch('{notice}', [NoticeController::class, 'update'])->name('update');
            Route::delete('{notice}', [NoticeController::class, 'destroy'])->name('destroy');
        });

        // Tasks (view/manage)
        Route::prefix('tasks')->name('tasks.')->group(function () {
            Route::get('/', [TaskController::class, 'index'])->name('index');
            Route::post('/', [TaskController::class, 'store'])->name('store');
            Route::get('{task}', [TaskController::class, 'show'])->name('show');
            Route::patch('{task}', [TaskController::class, 'update'])->name('update');
        });

        // Recurring bookings
        Route::prefix('recurring-bookings')->name('recurring-bookings.')->group(function () {
            Route::get('/', [RecurringBookingController::class, 'index'])->name('index');
            Route::get('{recurringBooking}', [RecurringBookingController::class, 'show'])->name('show');
            Route::delete('{recurringBooking}', [RecurringBookingController::class, 'destroy'])->name('destroy');
        });

        // Venue applications
        Route::prefix('venue-applications')->name('venue-applications.')->group(function () {
            Route::get('/', [VenueApplicationController::class, 'index'])->name('index');
            Route::get('{venueApplication}', [VenueApplicationController::class, 'show'])->name('show');
        });

        // Booking categories (admin CRUD)
        Route::prefix('admin/booking-categories')->name('admin.booking-categories.')->group(function () {
            Route::get('/', [BookingCategoryController::class, 'adminIndex'])->name('index');
            Route::post('/', [BookingCategoryController::class, 'store'])->name('store');
            Route::patch('{bookingCategory}', [BookingCategoryController::class, 'update'])->name('update');
            Route::delete('{bookingCategory}', [BookingCategoryController::class, 'destroy'])->name('destroy');
        });

        // Booking admin (all bookings + resource management)
        Route::prefix('admin/bookings')->name('admin.bookings.')->group(function () {
            Route::get('/', [BookingAdminController::class, 'index'])->name('index');
            Route::patch('{booking}', [BookingAdminController::class, 'update'])->name('update');
        });
        Route::prefix('admin/booking-resources')->name('admin.booking-resources.')->group(function () {
            Route::get('/', [BookingAdminController::class, 'resources'])->name('index');
            Route::post('/', [BookingAdminController::class, 'storeResource'])->name('store');
            Route::patch('{bookingResource}', [BookingAdminController::class, 'updateResource'])->name('update');
            Route::delete('{bookingResource}', [BookingAdminController::class, 'destroyResource'])->name('destroy');
        });

        // Venue applications admin
        Route::prefix('admin/venue-applications')->name('admin.venue-applications.')->group(function () {
            Route::post('{venueApplication}/approve', [VenueApplicationController::class, 'approve'])->name('approve');
            Route::post('{venueApplication}/reject', [VenueApplicationController::class, 'reject'])->name('reject');
        });

        // Tournaments
        Route::prefix('tournaments')->name('tournaments.')->group(function () {
            Route::get('/', [TournamentController::class, 'index'])->name('index');
            Route::get('{tournament}', [TournamentController::class, 'show'])->name('show');
        });

        // Gamification
        Route::prefix('gamification')->name('gamification.')->group(function () {
            Route::get('leaderboard', [GamificationController::class, 'leaderboard'])->name('leaderboard');
            Route::get('me', [GamificationController::class, 'me'])->name('me');
            Route::post('kudos/{member}', [GamificationController::class, 'giveKudos'])->name('kudos.give');
        });

        // Statistics
        Route::prefix('admin/statistics')->name('admin.statistics.')->group(function () {
            Route::get('members', [StatisticsController::class, 'members'])->name('members');
            Route::get('events', [StatisticsController::class, 'events'])->name('events');
            Route::get('revenue', [StatisticsController::class, 'revenue'])->name('revenue');
        });

        // Help admin
        Route::prefix('admin/help')->name('admin.help.')->group(function () {
            Route::get('sections', [HelpAdminController::class, 'sections'])->name('sections.index');
            Route::post('sections', [HelpAdminController::class, 'storeSection'])->name('sections.store');
            Route::patch('sections/{helpSection}', [HelpAdminController::class, 'updateSection'])->name('sections.update');
            Route::delete('sections/{helpSection}', [HelpAdminController::class, 'destroySection'])->name('sections.destroy');
            Route::get('texts', [HelpAdminController::class, 'texts'])->name('texts.index');
            Route::patch('texts/{helpText}', [HelpAdminController::class, 'updateText'])->name('texts.update');
        });

        // Meeting templates
        Route::prefix('admin/meeting-templates')->name('admin.meeting-templates.')->group(function () {
            Route::get('/', [MeetingTemplateController::class, 'index'])->name('index');
            Route::post('/', [MeetingTemplateController::class, 'store'])->name('store');
            Route::get('{meetingTemplate}', [MeetingTemplateController::class, 'show'])->name('show');
            Route::patch('{meetingTemplate}', [MeetingTemplateController::class, 'update'])->name('update');
            Route::delete('{meetingTemplate}', [MeetingTemplateController::class, 'destroy'])->name('destroy');
            Route::post('{meetingTemplate}/items', [MeetingTemplateController::class, 'storeItem'])->name('items.store');
            Route::patch('{meetingTemplate}/items/{meetingTemplateItem}', [MeetingTemplateController::class, 'updateItem'])->name('items.update');
            Route::delete('{meetingTemplate}/items/{meetingTemplateItem}', [MeetingTemplateController::class, 'destroyItem'])->name('items.destroy');
        });

        // Mailing templates
        Route::prefix('admin/mailing-templates')->name('admin.mailing-templates.')->group(function () {
            Route::get('/', [MailingTemplateController::class, 'index'])->name('index');
            Route::post('/', [MailingTemplateController::class, 'store'])->name('store');
            Route::get('{mailingTemplate}', [MailingTemplateController::class, 'show'])->name('show');
            Route::patch('{mailingTemplate}', [MailingTemplateController::class, 'update'])->name('update');
            Route::delete('{mailingTemplate}', [MailingTemplateController::class, 'destroy'])->name('destroy');
        });

        // Google Workspace (read-only)
        Route::prefix('admin/workspace')->name('admin.workspace.')->group(function () {
            Route::get('accounts', [WorkspaceController::class, 'accounts'])->name('accounts');
            Route::get('drives', [WorkspaceController::class, 'drives'])->name('drives');
            Route::get('delegates', [WorkspaceController::class, 'delegates'])->name('delegates');
        });

        // Accounting — gaps (bank accounts, transactions, cost centers) + budget CRUD
        Route::prefix('accounting')->name('accounting.')->group(function () {
            Route::get('bank-accounts', [AccountingGapsController::class, 'bankAccounts'])->name('bank-accounts.index');
            Route::post('bank-accounts', [AccountingGapsController::class, 'storeBankAccount'])->name('bank-accounts.store');
            Route::patch('bank-accounts/{bankAccount}', [AccountingGapsController::class, 'updateBankAccount'])->name('bank-accounts.update');
            Route::delete('bank-accounts/{bankAccount}', [AccountingGapsController::class, 'destroyBankAccount'])->name('bank-accounts.destroy');

            // Bank file imports
            Route::post('bank-accounts/{bankAccount}/import',          [BankImportController::class, 'importBankStatement'])->name('bank-accounts.import');
            Route::post('bank-accounts/{bankAccount}/swish-import',    [BankImportController::class, 'importSwish'])->name('bank-accounts.swish-import');
            Route::post('bank-accounts/{bankAccount}/bank-fee-import', [BankImportController::class, 'importBankFees'])->name('bank-accounts.bank-fee-import');
            Route::post('lb-return',                                    [BankImportController::class, 'parseLbReturn'])->name('lb-return');

            // reconcile-suggestions MUST come before {bankTransaction} wildcard to avoid shadowing
            Route::get('bank-transactions/reconcile-suggestions', [AccountingGapsController::class, 'reconcileSuggestions'])->name('bank-transactions.reconcile-suggestions');
            Route::get('bank-transactions', [AccountingGapsController::class, 'bankTransactions'])->name('bank-transactions.index');
            Route::get('bank-transactions/{bankTransaction}', [AccountingGapsController::class, 'showBankTransaction'])->name('bank-transactions.show');
            Route::patch('bank-transactions/{bankTransaction}', [AccountingGapsController::class, 'updateBankTransaction'])->name('bank-transactions.update');
            Route::post('bank-transactions/{bankTransaction}/confirm-match', [AccountingGapsController::class, 'confirmMatch'])->name('bank-transactions.confirm-match');

            Route::get('cost-centers', [AccountingGapsController::class, 'costCenters'])->name('cost-centers.index');
            Route::post('cost-centers', [AccountingGapsController::class, 'storeCostCenter'])->name('cost-centers.store');
            Route::patch('cost-centers/{costCenter}', [AccountingGapsController::class, 'updateCostCenter'])->name('cost-centers.update');
            Route::delete('cost-centers/{costCenter}', [AccountingGapsController::class, 'destroyCostCenter'])->name('cost-centers.destroy');

            // Budgets (full CRUD)
            Route::get('budgets', [BudgetController::class, 'index'])->name('budgets.index');
            Route::post('budgets', [BudgetController::class, 'store'])->name('budgets.store');
            Route::get('budgets/{budget}', [BudgetController::class, 'show'])->name('budgets.show');
            Route::patch('budgets/{budget}', [BudgetController::class, 'update'])->name('budgets.update');
            Route::post('budgets/{budget}/lines', [BudgetController::class, 'storeLine'])->name('budgets.lines.store');
            Route::patch('budgets/{budget}/lines/{line}', [BudgetController::class, 'updateLine'])->name('budgets.lines.update');
            Route::delete('budgets/{budget}/lines/{line}', [BudgetController::class, 'destroyLine'])->name('budgets.lines.destroy');

            // Config
            Route::get('posting-rules', [AccountingConfigController::class, 'postingRules'])->name('posting-rules.index');
            Route::post('posting-rules', [AccountingConfigController::class, 'storePostingRule'])->name('posting-rules.store');
            Route::patch('posting-rules/{postingRule}', [AccountingConfigController::class, 'updatePostingRule'])->name('posting-rules.update');
            Route::delete('posting-rules/{postingRule}', [AccountingConfigController::class, 'destroyPostingRule'])->name('posting-rules.destroy');

            Route::get('journal-series', [AccountingConfigController::class, 'journalSeries'])->name('journal-series.index');
            Route::post('journal-series', [AccountingConfigController::class, 'storeJournalSeries'])->name('journal-series.store');
            Route::patch('journal-series/{journalSeries}', [AccountingConfigController::class, 'updateJournalSeries'])->name('journal-series.update');

            Route::get('journal-templates', [AccountingConfigController::class, 'journalTemplates'])->name('journal-templates.index');
            Route::post('journal-templates', [AccountingConfigController::class, 'storeJournalTemplate'])->name('journal-templates.store');
            Route::patch('journal-templates/{journalTemplate}', [AccountingConfigController::class, 'updateJournalTemplate'])->name('journal-templates.update');
            Route::delete('journal-templates/{journalTemplate}', [AccountingConfigController::class, 'destroyJournalTemplate'])->name('journal-templates.destroy');

            Route::get('payment-providers', [AccountingConfigController::class, 'paymentProviders'])->name('payment-providers.index');
            Route::post('payment-providers', [AccountingConfigController::class, 'storePaymentProvider'])->name('payment-providers.store');
            Route::patch('payment-providers/{paymentProvider}', [AccountingConfigController::class, 'updatePaymentProvider'])->name('payment-providers.update');

            Route::get('payment-terms', [AccountingConfigController::class, 'paymentTerms'])->name('payment-terms.index');
            Route::post('payment-terms', [AccountingConfigController::class, 'storePaymentTerm'])->name('payment-terms.store');
            Route::patch('payment-terms/{paymentTerm}', [AccountingConfigController::class, 'updatePaymentTerm'])->name('payment-terms.update');

            Route::post('fiscal-years', [AccountingConfigController::class, 'storeFiscalYear'])->name('fiscal-years.store');
            Route::patch('fiscal-years/{fiscalYear}', [AccountingConfigController::class, 'updateFiscalYear'])->name('fiscal-years.update');

            Route::get('corporate-cards', [AccountingConfigController::class, 'corporateCards'])->name('corporate-cards.index');
            Route::post('corporate-cards', [AccountingConfigController::class, 'storeCorporateCard'])->name('corporate-cards.store');
            Route::patch('corporate-cards/{corporateCard}', [AccountingConfigController::class, 'updateCorporateCard'])->name('corporate-cards.update');

            // Accounting inbox
            Route::get('inbox', [AccountingInboxController::class, 'index'])->name('inbox.index');
            Route::patch('inbox/{accountingInboxItem}', [AccountingInboxController::class, 'update'])->name('inbox.update');

            // Accounting documents
            Route::get('documents',                    [AccountingDocumentController::class, 'index'])->name('documents.index');
            Route::post('documents',                   [AccountingDocumentController::class, 'store'])->name('documents.store');
            Route::post('documents/{document}/link',   [AccountingDocumentController::class, 'link'])->name('documents.link');
            Route::delete('documents/{document}/link', [AccountingDocumentController::class, 'unlink'])->name('documents.unlink');
            Route::delete('documents/{document}',      [AccountingDocumentController::class, 'destroy'])->name('documents.destroy');

            // Approval rules
            Route::get('approval-rules', [ApprovalRuleController::class, 'index'])->name('approval-rules.index');
            Route::post('approval-rules', [ApprovalRuleController::class, 'store'])->name('approval-rules.store');
            Route::patch('approval-rules/{approvalRule}', [ApprovalRuleController::class, 'update'])->name('approval-rules.update');
            Route::delete('approval-rules/{approvalRule}', [ApprovalRuleController::class, 'destroy'])->name('approval-rules.destroy');
            Route::post('approval-rules/{approvalRule}/toggle', [ApprovalRuleController::class, 'toggle'])->name('approval-rules.toggle');

            // Revision — bulk-approve MUST come before {journalEntry} wildcard
            Route::get('revision',                          [AccountingRevisionController::class, 'index'])->name('revision.index');
            Route::post('revision/bulk-approve',            [AccountingRevisionController::class, 'bulkApprove'])->name('revision.bulk-approve');
            Route::post('revision/{journalEntry}/review',   [AccountingRevisionController::class, 'review'])->name('revision.review');
            Route::delete('revision/{journalEntry}/review', [AccountingRevisionController::class, 'unapprove'])->name('revision.unapprove');
        });

        // Transactional mail templates
        Route::prefix('admin/mail-templates')->name('admin.mail-templates.')->group(function () {
            Route::get('/', [MailTemplateController::class, 'index'])->name('index');
            Route::get('{mailTemplate}', [MailTemplateController::class, 'show'])->name('show');
            Route::patch('{mailTemplate}', [MailTemplateController::class, 'update'])->name('update');
        });

        // Mail log
        Route::prefix('admin/mail-log')->name('admin.mail-log.')->group(function () {
            Route::get('/', [MailLogController::class, 'index'])->name('index');
            Route::get('{mailLog}', [MailLogController::class, 'show'])->name('show');
        });

        // RFID tags
        Route::prefix('admin/rfid-tags')->name('admin.rfid-tags.')->group(function () {
            Route::get('/', [RfidTagController::class, 'index'])->name('index');
            Route::post('/', [RfidTagController::class, 'store'])->name('store');
            Route::get('prices', [RfidTagController::class, 'prices'])->name('prices.index');
            Route::post('prices', [RfidTagController::class, 'storePrice'])->name('prices.store');
            Route::patch('prices/{price}', [RfidTagController::class, 'updatePrice'])->name('prices.update');
            Route::get('{memberTag}', [RfidTagController::class, 'show'])->name('show');
            Route::patch('{memberTag}', [RfidTagController::class, 'update'])->name('update');
        });

        // System settings
        Route::prefix('admin/settings')->name('admin.settings.')->group(function () {
            Route::get('/', [SystemSettingController::class, 'index'])->name('index');
            Route::patch('{key}', [SystemSettingController::class, 'update'])->name('update');
        });

        // Membership fees (admin CRUD)
        Route::prefix('admin/membership-fees')->name('admin.membership-fees.')->group(function () {
            Route::get('/', [MembershipFeeController::class, 'adminIndex'])->name('index');
            Route::post('/', [MembershipFeeController::class, 'store'])->name('store');
            Route::patch('{membershipFee}', [MembershipFeeController::class, 'update'])->name('update');
            Route::delete('{membershipFee}', [MembershipFeeController::class, 'destroy'])->name('destroy');
        });

        // Admin family groups
        Route::prefix('admin/family-groups')->name('admin.family-groups.')->group(function () {
            Route::get('/', [AdminFamilyGroupController::class, 'index'])->name('index');
            Route::get('{familyGroup}', [AdminFamilyGroupController::class, 'show'])->name('show');
            Route::post('{familyGroup}/remove-member', [AdminFamilyGroupController::class, 'removeMember'])->name('remove-member');
        });

        // Audit log
        Route::prefix('admin/audit-log')->name('admin.audit-log.')->group(function () {
            Route::get('/', [AuditLogController::class, 'index'])->name('index');
        });

        // Inbox mailboxes (kansliinkorg configuration)
        Route::prefix('admin/inbox-mailboxes')->name('admin.inbox-mailboxes.')->group(function () {
            Route::get('/', [InboxMailboxController::class, 'index'])->name('index');
            Route::post('/', [InboxMailboxController::class, 'store'])->name('store');
            Route::get('{mailbox}', [InboxMailboxController::class, 'show'])->name('show');
            Route::patch('{mailbox}', [InboxMailboxController::class, 'update'])->name('update');
            Route::delete('{mailbox}', [InboxMailboxController::class, 'destroy'])->name('destroy');
        });

        // Accounting reports (JSON + PDF)
        Route::prefix('accounting/reports')->name('accounting.reports.')->group(function () {
            Route::get('balance-sheet',    [ReportController::class, 'balanceSheet'])->name('balance-sheet');
            Route::get('income-statement', [ReportController::class, 'incomeStatement'])->name('income-statement');
            Route::get('general-ledger',   [ReportController::class, 'generalLedger'])->name('general-ledger');
            Route::get('budget-variance',  [ReportController::class, 'budgetVariance'])->name('budget-variance');
            Route::get('journal-list',     [ReportController::class, 'journalList'])->name('journal-list');
            Route::get('vendor-report',    [ReportController::class, 'vendorReport'])->name('vendor-report');
        });

        // Booking statistics
        Route::get('admin/booking-statistics', [BookingStatisticsController::class, 'index'])->name('admin.booking-statistics.index');
    });
});
