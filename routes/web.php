<?php

use App\Http\Controllers\Admin\DiscountPrintController;
use App\Http\Controllers\Admin\Sie4ExportController;
use App\Http\Controllers\Admin\SurveyExportController;
use App\Http\Controllers\Auth\DiscordAuthController;
use App\Http\Controllers\Discord\DiscordInteractionController;
use App\Http\Controllers\Signing\DocsignCallbackController;
use App\Http\Controllers\Signing\SigningFileController;
use App\Http\Controllers\Auth\DiscordLinkedRoleController;
use App\Http\Controllers\Auth\KioskController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Booking\IcalController;
use App\Http\Controllers\Booking\ResourceController;
use App\Http\Controllers\Booking\TvController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EventInvitationController;
use App\Http\Controllers\ExpenseClaimReceiptController;
use App\Http\Controllers\GdprExportController;
use App\Http\Controllers\InboxAttachmentController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MagicLinkController;
use App\Http\Controllers\MailingUnsubscribeController;
use App\Http\Controllers\MailTrackController;
use App\Http\Controllers\ScannerRelayController;
use App\Http\Controllers\StripeController;
use App\Http\Controllers\SwishController;
use App\Http\Controllers\TicketQrController;
use App\Http\Controllers\Tournament\TvController as TournamentTvController;
use App\Livewire\Admin\ActivityManager;
use App\Livewire\Admin\AuditLogManager;
use App\Livewire\Admin\Booking\BookingEdit;
use App\Livewire\Admin\Booking\BookingManager;
use App\Livewire\Admin\Booking\CategoryManager;
use App\Livewire\Admin\Booking\RecurringBookingIndex;
use App\Livewire\Admin\Booking\ResourceManager;
use App\Livewire\Admin\Booking\Statistics;
use App\Livewire\Admin\Booking\VenueApplicationManager;
use App\Livewire\Admin\Booking\VenueApplicationReview;
use App\Livewire\Admin\Meeting\MeetingForm;
use App\Livewire\Admin\Meeting\MeetingIndex;
use App\Livewire\Admin\Meeting\MeetingShow;
use App\Livewire\Admin\Meeting\TaskIndex;
use App\Livewire\Admin\Meeting\TaskShow;
use App\Livewire\Admin\Meeting\TemplateForm;
use App\Livewire\Admin\Meeting\TemplateIndex;
use App\Livewire\Admin\Meeting\TemplateShow;
use App\Livewire\Admin\NoticeManager;
use App\Livewire\Admin\Pages\Edit as EditPage;
use App\Livewire\Admin\Statistics\Events;
use App\Livewire\Admin\Statistics\Members;
use App\Livewire\Admin\Statistics\MunicipalityReport;
use App\Livewire\Admin\Statistics\Revenue;
use App\Livewire\Admin\TagManager;
use App\Livewire\Booking\BookingEditUser;
use App\Livewire\Booking\BookingForm;
use App\Livewire\Booking\Calendar;
use App\Livewire\Booking\MyApplications;
use App\Livewire\Booking\MyBookings;
use App\Livewire\Booking\RecurringBookingForm;
use App\Livewire\Booking\VenueApplicationForm;
use App\Livewire\Events\EventCalendar;
use App\Livewire\Meeting\MeetingView;
use App\Livewire\Meeting\MyMeetings;
use App\Livewire\Member\SubmissionIndex;
use App\Livewire\Member\SubmissionShow;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Mail open tracking (public, no auth)
Route::get('/m/o/{token}', [MailTrackController::class, 'open'])->name('mail.track.open');

// Mailing unsubscribe (public, signed URL)
Route::get('/avprenumerera/{mailing}/{email}', [MailingUnsubscribeController::class, 'show'])->name('mailing.unsubscribe');
Route::post('/avprenumerera/{mailing}/{email}', [MailingUnsubscribeController::class, 'confirm'])->name('mailing.unsubscribe.confirm');

// PWA
Route::get('/offline', fn () => view('offline'))->name('offline');
Route::middleware('auth')->post('/pwa-ping', function () {
    $user = Auth::user();
    $now = now();
    $user->pwa_last_seen_at = $now;
    if (! $user->pwa_first_seen_at) {
        $user->pwa_first_seen_at = $now;
    }
    $user->saveQuietly();

    return response()->noContent();
})->name('pwa.ping');

// Wiki (public: guests see public spaces, WikiAccess decides the rest)
Volt::route('/wiki', 'wiki.index')->name('wiki.index');
// Before {page:slug} so the reserved slug wins (WikiPage::RESERVED_SLUGS)
Volt::route('/wiki/{space:slug}/ny-sida', 'wiki.page-form')->middleware('auth')->name('wiki.pages.create');
Volt::route('/wiki/{space:slug}', 'wiki.space')->name('wiki.space');
Volt::route('/wiki/{space:slug}/{page:slug}', 'wiki.page')->scopeBindings()->name('wiki.page');
Volt::route('/wiki/{space:slug}/{page:slug}/redigera', 'wiki.page-form')->middleware('auth')->scopeBindings()->name('wiki.pages.edit');
Volt::route('/wiki/{space:slug}/{page:slug}/historik', 'wiki.history')->scopeBindings()->name('wiki.pages.history');
Volt::route('/wiki/{space:slug}/{page:slug}/historik/{revision}', 'wiki.revision')->scopeBindings()->name('wiki.pages.revision');

// Turneringar (public)
Volt::route('/turneringar', 'tournaments.index')->name('tournaments.index');
Volt::route('/turneringar/{tournament}', 'tournaments.show')->name('tournaments.show');
Route::get('/turneringar/{tournament}/tv/stallning', [TournamentTvController::class, 'standings'])->name('tournaments.tv.standings');
Route::get('/turneringar/{tournament}/tv/matchschema', [TournamentTvController::class, 'draw'])->name('tournaments.tv.draw');
Route::get('/turneringar/{tournament}/tv/bracket', [TournamentTvController::class, 'bracket'])->name('tournaments.tv.bracket');
Route::get('/turneringar/{tournament}/tv/timer', [TournamentTvController::class, 'timer'])->name('tournaments.tv.timer');
Route::get('/turneringar/{tournament}/tv/info', [TournamentTvController::class, 'info'])->name('tournaments.tv.info');
Route::get('/turneringar/{tournament}/tv/resultat', [TournamentTvController::class, 'recap'])->name('tournaments.tv.recap');
Route::get('/turneringar/{tournament}/tv/meddelande', [TournamentTvController::class, 'message'])->name('tournaments.tv.message');
Route::get('/turneringar/{tournament}/pdf/stallning', [TournamentTvController::class, 'pdfStandings'])->name('tournaments.pdf.standings');
Route::get('/turneringar/{tournament}/pdf/bracket', [TournamentTvController::class, 'pdfBracket'])->name('tournaments.pdf.bracket');

// Public website
Volt::route('/gamification', 'gamification.leaderboard')->name('gamification.leaderboard');
Volt::route('/', 'pages.home')->name('home');
Volt::route('/hjalp', 'help-page')->name('help');
Volt::route('/hjalp/{groupSlug}', 'help-group')->name('help.group');

// Graphic profile / style guide
Route::get('/grafiskprofil/light', fn () => view('grafisk-profil', ['theme' => 'light']))->name('grafiskprofil.light');
Route::get('/grafiskprofil/dark', fn () => view('grafisk-profil', ['theme' => 'dark']))->name('grafiskprofil.dark');

// API documentation (Swagger UI — staff only)
Route::get('/api-docs', function () {
    abort_unless(Auth::check() && Auth::user()->isStaff(), 403);

    return view('api-docs');
})->middleware('auth')->name('api-docs');

// Kiosk
Route::get('/kiosk/login', [KioskController::class, 'show'])->name('kiosk.login');
Route::post('/kiosk/login', [KioskController::class, 'login'])->name('kiosk.login.post');
Route::post('/kiosk/logout', [KioskController::class, 'logout'])->middleware('auth')->name('kiosk.logout');

// Google SSO
Route::get('/auth/google', [SocialAuthController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [SocialAuthController::class, 'callback'])->name('auth.google.callback');
Route::get('/auth/google/link', [SocialAuthController::class, 'linkRedirect'])->middleware('auth')->name('auth.google.link');
Route::delete('/auth/google/personal', [SocialAuthController::class, 'unlinkPersonal'])->middleware('auth')->name('auth.google.unlink');

Route::get('/auth/discord', [DiscordAuthController::class, 'loginRedirect'])->name('auth.discord');
Route::get('/auth/discord/login-callback', [DiscordAuthController::class, 'loginCallback'])->name('auth.discord.login-callback');
Route::get('/discord/linked-role', [DiscordLinkedRoleController::class, 'redirect'])->name('discord.linked-role.redirect');
Route::get('/discord/linked-role/callback', [DiscordLinkedRoleController::class, 'callback'])->name('discord.linked-role.callback');

Route::get('/auth/discord/link', [DiscordAuthController::class, 'redirect'])->middleware('auth')->name('auth.discord.link');
Route::get('/auth/discord/callback', [DiscordAuthController::class, 'callback'])->middleware('auth')->name('auth.discord.callback');
Route::delete('/auth/discord', [DiscordAuthController::class, 'unlink'])->middleware('auth')->name('auth.discord.unlink');

// Magic link (passwordless)
Route::middleware('guest')->group(function () {
    Route::post('/auth/magic-link', [MagicLinkController::class, 'request'])->name('magic-link.request');
    Route::get('/auth/magic-link/{token}', [MagicLinkController::class, 'verify'])->name('magic-link.verify');
});

// Auth views and POST routes are handled by Fortify (registered in FortifyServiceProvider)

// Authenticated
Route::middleware('auth')->group(function () {
    Volt::route('/dashboard', 'pages.dashboard')->name('dashboard');
    Volt::route('/min-inkorg', 'pages.my-inbox')->name('my-inbox');
    Volt::route('/installningar/importera-mail', 'settings.imap-import')->name('settings.imap-import');

    Route::get('/profile', fn () => redirect()->to(route('dashboard').'#installningar'))->name('profile.edit');
    Route::get('/mina-uppgifter/export', GdprExportController::class)->name('gdpr.export');

    // Membership
    Route::get('/membership', fn () => redirect()->to(route('dashboard').'#medlemskap'))->name('membership.show');
    Volt::route('/membership/edit', 'membership.edit')->name('membership.edit');
    Volt::route('/bli-medlem', 'membership.apply')->name('membership.apply');
    Volt::route('/bli-medlem/betala', 'membership.payment')->name('membership.payment');

    // Invoices — shop orders
    Route::get('/fakturor/{order}/visa', [InvoiceController::class, 'view'])->name('invoice.view');
    Route::get('/fakturor/{order}/ladda-ner', [InvoiceController::class, 'download'])->name('invoice.download');

    // Invoices — AR (manual / accounting)
    Route::get('/bokforing/fakturor/{invoice}/visa', [InvoiceController::class, 'viewArInvoice'])->name('invoice.ar.view');
    Route::get('/bokforing/fakturor/{invoice}/ladda-ner', [InvoiceController::class, 'downloadArInvoice'])->name('invoice.ar.download');

    // Protected accounting document downloads (private disk — never public URLs)
    // All accounting files — vendor invoices, receipts, contracts — route through here.
    Route::get('/dokument/{document}', [DocumentController::class, 'inline'])->name('document.inline');
    Route::get('/dokument/{document}/ladda-ner', [DocumentController::class, 'download'])->name('document.download');

    // Submissions (member-facing)
    Route::get('/arenden', SubmissionIndex::class)->name('submissions.index');
    Route::get('/arenden/{submission}', SubmissionShow::class)->name('submissions.show');

    // Digital member card
    Volt::route('/mitt-kort', 'pages.member-card')->name('member.card');

    // Expense claims (member-facing)
    Volt::route('/utlagg', 'member.expense-claims.index')->name('expense-claims.index');
    Volt::route('/utlagg/skicka-in', 'member.expense-claims.create')->name('expense-claims.create');
    Route::get('/utlagg/{claim}/kvitto', [ExpenseClaimReceiptController::class, 'inline'])->name('expense-claims.receipt.inline');
    Route::get('/utlagg/{claim}/kvitto/ladda-ner', [ExpenseClaimReceiptController::class, 'download'])->name('expense-claims.receipt.download');

    // CMS admin
    Volt::route('/admin/pages', 'admin.pages.index')->name('admin.pages.index');
    Volt::route('/admin/pages/create', 'admin.pages.create')->name('admin.pages.create');
    Route::get('/admin/pages/{page}/edit', EditPage::class)->name('admin.pages.edit');

    // Contact messages admin
    Volt::route('/admin/kontakt', 'admin.contact.index')->name('admin.contact.index');
    Volt::route('/admin/kontakt/{contactMessage}', 'admin.contact.show')->name('admin.contact.show');

    // Navigation admin
    Volt::route('/admin/navigation', 'admin.navigation.index')->name('admin.navigation.index');
    Volt::route('/admin/navigation/create', 'admin.navigation.form')->name('admin.navigation.create');
    Volt::route('/admin/navigation/{item}/edit', 'admin.navigation.form')->name('admin.navigation.edit');

    // Help texts admin
    Volt::route('/admin/help-texts', 'admin.help-texts.index')->name('admin.help-texts.index');

    // Help sections admin
    Volt::route('/admin/hjalp-sektioner', 'admin.help-sections.index')->name('admin.help-sections.index');

    // Membership fees admin
    Volt::route('/admin/membership-fees', 'admin.membership-fees.index')->name('admin.membership-fees.index');

    // Booking module
    Route::get('/kalender', Calendar::class)->name('booking.calendar');
    Route::get('/rum', [ResourceController::class, 'index'])->name('booking.resources.index');
    Route::get('/boka', BookingForm::class)->name('booking.create');
    Route::get('/mina-bokningar', MyBookings::class)->name('booking.my');
    Route::get('/mina-bokningar/{booking}/redigera', BookingEditUser::class)->name('booking.edit');
    Route::get('/ansok-om-lokal', VenueApplicationForm::class)->name('booking.venue-application.create');
    Route::get('/mina-ansokningar', MyApplications::class)->name('booking.my-applications');

    Route::get('/admin/bokningar', BookingManager::class)->name('booking.admin.bookings.index');
    Route::get('/admin/bokningar/{booking}/redigera', BookingEdit::class)->name('booking.admin.bookings.edit');
    Route::get('/admin/upprepande-bokningar', RecurringBookingIndex::class)->name('booking.admin.recurring-bookings.index');
    Route::get('/admin/upprepande-bokningar/skapa', RecurringBookingForm::class)->name('booking.admin.recurring-bookings.create');
    Route::get('/admin/upprepande-bokningar/redigera', RecurringBookingForm::class)->name('booking.admin.recurring-bookings.edit');
    Route::get('/admin/rum', ResourceManager::class)->name('booking.admin.resources.index');
    Route::get('/admin/rum-kategorier', CategoryManager::class)->name('booking.admin.categories.index');
    Route::get('/admin/aktiviteter', ActivityManager::class)->name('admin.activities.index');
    Route::get('/admin/taggar', TagManager::class)->name('admin.tags.index');
    Route::get('/admin/notiser', NoticeManager::class)->name('admin.notices.index');
    Volt::route('/admin/installningar', 'admin.settings')->name('admin.settings');
    Route::redirect('/admin/bokningsinställningar', '/admin/installningar#bokningar')->name('booking.admin.settings');
    Route::get('/admin/bokningsstatistik', Statistics::class)->name('booking.admin.statistics');
    Route::get('/admin/statistik/medlemmar', Members::class)->name('admin.statistics.members');
    Route::get('/admin/statistik/evenemang', Events::class)->name('admin.statistics.events');
    Route::get('/admin/statistik/intakter', Revenue::class)->name('admin.statistics.revenue');
    Route::get('/admin/statistik/kommunrapport', MunicipalityReport::class)->name('admin.statistics.municipality-report');
    Volt::route('/admin/gamification', 'admin.gamification.overview')->name('admin.gamification.index');
    // Turneringar (admin)
    Volt::route('/admin/turneringar', 'admin.tournaments.index')->name('admin.tournaments.index');
    Volt::route('/admin/turneringar/skapa', 'admin.tournaments.form')->name('admin.tournaments.create');
    Volt::route('/admin/turneringar/{tournament}/redigera', 'admin.tournaments.form')->name('admin.tournaments.edit');
    Volt::route('/admin/turneringar/{tournament}', 'admin.tournaments.manage')->name('admin.tournaments.manage');
    Volt::route('/admin/turneringar/{tournament}/lottning/{phase}', 'admin.tournaments.draw')->name('admin.tournaments.draw');
    Volt::route('/admin/gamification/tasks', 'admin.gamification.tasks.index')->name('admin.gamification.tasks.index');
    Volt::route('/admin/gamification/tasks/skapa', 'admin.gamification.tasks.form')->name('admin.gamification.tasks.create');
    Volt::route('/admin/gamification/tasks/{task}/redigera', 'admin.gamification.tasks.form')->name('admin.gamification.tasks.edit');
    Volt::route('/admin/gamification/godkann', 'admin.gamification.approvals')->name('admin.gamification.godkann');
    Volt::route('/gamification/tasks', 'gamification.tasks')->name('gamification.tasks');
    Route::get('/admin/handelser', AuditLogManager::class)->name('admin.audit-log');
    Route::get('/admin/lokalansokningar', VenueApplicationManager::class)->name('booking.admin.venue-applications.index');
    Route::get('/admin/lokalansokningar/{application}', VenueApplicationReview::class)->name('booking.admin.venue-applications.show');

    // Meeting tasks
    Route::get('/admin/uppgifter', TaskIndex::class)->name('admin.tasks.index');
    Route::get('/admin/uppgifter/{task}', TaskShow::class)->name('admin.tasks.show');

    // Submissions (ärenden + motioner) — admin
    Route::get('/admin/arenden', App\Livewire\Admin\Meeting\SubmissionIndex::class)->name('admin.submissions.index');
    Route::get('/admin/arenden/{submission}', App\Livewire\Admin\Meeting\SubmissionShow::class)->name('admin.submissions.show');

    // E-signing (DocSign) — static paths before the {signingRequest} wildcard
    Volt::route('/admin/signeringar', 'admin.signing.index')->name('admin.signing.index');
    Volt::route('/admin/signeringar/ny', 'admin.signing.create')->name('admin.signing.create');
    Route::get('/admin/signeringar/fil/{file}', [SigningFileController::class, 'original'])->name('admin.signing.file');
    Volt::route('/admin/signeringar/{signingRequest}', 'admin.signing.show')->name('admin.signing.show');
    Route::get('/admin/signeringar/{signingRequest}/signerad', [SigningFileController::class, 'signed'])->name('admin.signing.signed');

    // Meetings admin — statiska prefix-rutter MÅSTE komma före wildcard {meeting}
    Route::get('/admin/moten', MeetingIndex::class)->name('admin.meetings.index');
    Route::get('/admin/moten/skapa', MeetingForm::class)->name('admin.meetings.create');
    Route::get('/admin/moten/mallar', TemplateIndex::class)->name('admin.meetings.templates.index');
    Route::get('/admin/moten/mallar/skapa', TemplateForm::class)->name('admin.meetings.templates.create');
    Route::get('/admin/moten/mallar/{template}', TemplateShow::class)->name('admin.meetings.templates.show');
    Route::get('/admin/moten/mallar/{template}/redigera', TemplateForm::class)->name('admin.meetings.templates.edit');
    Route::get('/admin/moten/{meeting}', MeetingShow::class)->name('admin.meetings.show');
    Route::get('/admin/moten/{meeting}/redigera', MeetingForm::class)->name('admin.meetings.edit');

    // Organizational roles admin
    Volt::route('/admin/org-roles', 'admin.org-roles.index')->name('admin.org-roles.index');
    Volt::route('/admin/org-roles/{role}', 'admin.org-roles.show')->name('admin.org-roles.show');
    Volt::route('/admin/org-roles/{role}/edit', 'admin.org-roles.edit')->name('admin.org-roles.edit');

    // Google Workspace
    Volt::route('/admin/workspace', 'admin.workspace.index')->name('admin.workspace.index');
    Volt::route('/admin/workspace/drive', 'admin.workspace.drive')->name('admin.workspace.drive');
    Volt::route('/admin/workspace/inkorgar', 'admin.workspace.mailboxes')->name('admin.workspace.mailboxes');
    Volt::route('/admin/workspace/rapporter', 'admin.workspace.reports')->name('admin.workspace.reports');

    // Office inbox (kansli)
    Volt::route('/admin/kansliinkorg', 'admin.office-inbox')->name('admin.office-inbox');

    // Inbox attachment download (signed URL, short-lived)
    Route::get('/inbox/attachment/{mailboxId}/{messageId}/{attachmentId}', [InboxAttachmentController::class, 'download'])
        ->name('inbox.attachment.download')
        ->where('attachmentId', '.+');

    // Family group management
    Volt::route('/familj/hantera', 'family.manage')->name('family.manage');

    // Group browse + self-management
    Volt::route('/grupper', 'groups.index')->name('groups.index');
    Volt::route('/grupper/{section}', 'groups.show')->name('groups.show');
    Volt::route('/grupper/{section}/hantera', 'groups.manage')->name('groups.manage');

    // Groups admin
    Volt::route('/admin/grupper', 'admin.sections.index')->name('admin.sections.index');
    Volt::route('/admin/grupper/skapa', 'admin.sections.create')->name('admin.sections.create');
    Volt::route('/admin/grupper/{section}', 'admin.sections.show')->name('admin.sections.show');
    Volt::route('/admin/grupper/{section}/redigera', 'admin.sections.edit')->name('admin.sections.edit');

    // Wiki admin
    Volt::route('/admin/wiki', 'admin.wiki.index')->name('admin.wiki.index');

    // Events admin
    Volt::route('/admin/evenemang', 'admin.events.index')->name('admin.events.index');
    Volt::route('/admin/evenemang/skapa', 'admin.events.create')->name('admin.events.create');
    Volt::route('/admin/evenemang/{event}', 'admin.events.show')->name('admin.events.show');
    Volt::route('/admin/evenemang/{event}/redigera', 'admin.events.edit')->name('admin.events.edit');
    Volt::route('/admin/evenemang/{event}/antagning', 'admin.events.admission')->name('admin.events.admission');

    // Roles & permissions admin
    Volt::route('/admin/roller', 'admin.roles.index')->name('admin.roles.index');
    Volt::route('/admin/anvandare', 'admin.users.index')->name('admin.users.index');

    // API clients admin
    Volt::route('/admin/api-klienter', 'admin.api-clients.index')->name('admin.api-clients.index');

    // Discount admin
    Volt::route('/admin/rabatter', 'admin.discounts.index')->name('admin.discounts.index');
    Route::get('/admin/rabatter/{discount}/skriv-ut', [DiscountPrintController::class, 'printCodes'])->name('admin.discounts.print');
    Route::get('/admin/rabatter/{discount}/koder/{code}/png', [DiscountPrintController::class, 'downloadCodePng'])->name('admin.discounts.code.png');
    Route::get('/admin/rabatter/{discount}/koder/zip', [DiscountPrintController::class, 'downloadCodesZip'])->name('admin.discounts.codes.zip');
    Volt::route('/admin/scanna', 'admin.discounts.scan')->name('admin.discounts.scan');
    Volt::route('/admin/scanna/kiosk', 'scanner.kiosk')->name('admin.scanner.kiosk');
    Route::get('/admin/scanna/parkoppling/{session}/kod', [ScannerRelayController::class, 'qr'])->name('scanner-relay.qr');
    Route::get('/admin/scanna/parkoppling/{session}/poll', [ScannerRelayController::class, 'poll'])->name('scanner-relay.poll');

    // Rental admin
    Volt::route('/admin/hyror', 'admin.rentals.index')->name('admin.rentals.index');
    Volt::route('/admin/hyror/fakturor', 'admin.rentals.invoices')->name('admin.rentals.invoices');
    Volt::route('/admin/hyror/nivaer', 'admin.rentals.prices')->name('admin.rentals.prices');

    // RFID tag admin
    Volt::route('/admin/rfid-taggar', 'admin.rfid-tags.index')->name('admin.rfid-tags.index');
    Volt::route('/admin/rfid-taggar/priser', 'admin.rfid-tags.prices')->name('admin.rfid-tags.prices');

    // Mailings admin
    Volt::route('/admin/utskick', 'admin.mailings.index')->name('admin.mailings.index');
    Volt::route('/admin/utskick/skapa', 'admin.mailings.create')->name('admin.mailings.create');
    Volt::route('/admin/utskick/{mailing}/redigera', 'admin.mailings.edit')->name('admin.mailings.edit');
    Volt::route('/admin/utskick/mallar', 'admin.mailing-templates.index')->name('admin.mailing-templates.index');
    Volt::route('/admin/utskick/mallar/skapa', 'admin.mailing-templates.create')->name('admin.mailing-templates.create');
    Volt::route('/admin/utskick/mallar/{mailingTemplate}/redigera', 'admin.mailing-templates.edit')->name('admin.mailing-templates.edit');

    // Forms admin
    Volt::route('/admin/formulär', 'admin.forms.index')->name('admin.forms.index');
    Volt::route('/admin/formulär/skapa', 'admin.forms.create')->name('admin.forms.create');
    Volt::route('/admin/formulär/{form}/redigera', 'admin.forms.edit')->name('admin.forms.edit');
    Volt::route('/admin/formulär/{form}/svar', 'admin.forms.responses')->name('admin.forms.responses');
    Volt::route('/admin/formulär/{form}/forhandsgranska', 'admin.forms.preview')->name('admin.forms.preview');

    // Surveys admin
    Volt::route('/admin/enkater', 'admin.surveys.index')->name('admin.surveys.index');
    Volt::route('/admin/enkater/skapa', 'admin.surveys.create')->name('admin.surveys.create');
    Volt::route('/admin/enkater/{survey}/redigera', 'admin.surveys.edit')->name('admin.surveys.edit');
    Volt::route('/admin/enkater/{survey}/inbjudningar', 'admin.surveys.invitations')->name('admin.surveys.invitations');
    Volt::route('/admin/enkater/{survey}/svar', 'admin.surveys.responses')->name('admin.surveys.responses');
    Volt::route('/admin/enkater/{survey}/forhandsgranska', 'admin.surveys.preview')->name('admin.surveys.preview');
    Route::get('/admin/enkater/{survey}/svar/export', SurveyExportController::class)->name('admin.surveys.responses.export');

    // Shop admin
    Volt::route('/admin/butik/produkter', 'admin.shop.products.index')->name('admin.shop.products.index');
    Volt::route('/admin/butik/produkter/skapa', 'admin.shop.products.create')->name('admin.shop.products.create');
    Volt::route('/admin/butik/produkter/{product}/redigera', 'admin.shop.products.edit')->name('admin.shop.products.edit');
    Volt::route('/admin/butik/produkter/{product}/lager', 'admin.shop.products.stock')->name('admin.shop.products.stock');
    Volt::route('/admin/butik/ordrar', 'admin.shop.orders.index')->name('admin.shop.orders.index');
    Volt::route('/admin/butik/ordrar/{order}', 'admin.shop.orders.show')->name('admin.shop.orders.show');
    Volt::route('/admin/butik/kunder', 'admin.accounting.customers.index')->name('admin.shop.customers.index');
    Volt::route('/admin/butik/kunder/{customer}', 'admin.accounting.customers.show')->name('admin.shop.customers.show');
    Volt::route('/admin/butik/kundfakturor', 'admin.accounting.invoices.receivable.index')->name('admin.shop.invoices.index');
    Volt::route('/admin/butik/kundfakturor/ny', 'admin.accounting.invoices.receivable.create')->name('admin.shop.invoices.create');
    Volt::route('/admin/butik/kundfakturor/{invoice}', 'admin.accounting.invoices.receivable.show')->name('admin.shop.invoices.show');
    Volt::route('/admin/butik/rabatter', 'admin.discounts.index')->name('admin.shop.discounts.index');

    // Accounting admin
    Volt::route('/admin/bokforing', 'admin.accounting.dashboard')->name('admin.accounting.dashboard');
    Volt::route('/admin/bokforing/kontoplan', 'admin.accounting.accounts.index')->name('admin.accounting.accounts.index');
    Volt::route('/admin/bokforing/kostnadsstallen', 'admin.accounting.cost-centers.index')->name('admin.accounting.cost-centers.index');
    Volt::route('/admin/bokforing/verifikationsserier', 'admin.accounting.journal-series.index')->name('admin.accounting.journal-series.index');
    Volt::route('/admin/bokforing/rakenskapsar', 'admin.accounting.fiscal-years.index')->name('admin.accounting.fiscal-years.index');
    Volt::route('/admin/bokforing/rakenskapsar/{fiscalYear}/ib', 'admin.accounting.fiscal-years.opening-balances')->name('admin.accounting.fiscal-years.opening-balances');
    Route::get('/admin/bokforing/rakenskapsar/{fiscalYear}/sie4', Sie4ExportController::class)->name('admin.accounting.fiscal-years.sie4');
    Volt::route('/admin/bokforing/sie4-import', 'admin.accounting.fiscal-years.sie-import')->name('admin.accounting.fiscal-years.sie-import');
    Volt::route('/admin/bokforing/verifikationer', 'admin.accounting.journal-entries.index')->name('admin.accounting.journal-entries.index');
    Volt::route('/admin/bokforing/verifikationer/ny', 'admin.accounting.journal-entries.create')->name('admin.accounting.journal-entries.create');
    Volt::route('/admin/bokforing/verifikationer/{journalEntry}', 'admin.accounting.journal-entries.show')->name('admin.accounting.journal-entries.show');
    Volt::route('/admin/bokforing/bankkonton', 'admin.accounting.bank-accounts.index')->name('admin.accounting.bank-accounts.index');
    Volt::route('/admin/bokforing/bankkonton/importera', 'admin.accounting.bank-accounts.import')->name('admin.accounting.bank-accounts.import');
    Volt::route('/admin/bokforing/bankkonton/lb-aterredovisning', 'admin.accounting.bank-accounts.lb-return')->name('admin.accounting.bank-accounts.lb-return');
    Volt::route('/admin/bokforing/bankkonton/swish-import', 'admin.accounting.bank-accounts.swish-import')->name('admin.accounting.bank-accounts.swish-import');
    Volt::route('/admin/bokforing/bankkonton/bankavgifter', 'admin.accounting.bank-accounts.bank-fee-import')->name('admin.accounting.bank-accounts.bank-fee-import');
    Volt::route('/admin/bokforing/bokforingsregler', 'admin.accounting.posting-rules.index')->name('admin.accounting.posting-rules.index');
    Volt::route('/admin/bokforing/konteringsmallar', 'admin.accounting.journal-templates.index')->name('admin.accounting.journal-templates.index');
    Volt::route('/admin/bokforing/betalningsvillkor', 'admin.accounting.payment-terms.index')->name('admin.accounting.payment-terms.index');
    Volt::route('/admin/bokforing/banktransaktioner', 'admin.accounting.bank-transactions.index')->name('admin.accounting.bank-transactions.index');
    Volt::route('/admin/bokforing/banktransaktioner/stam-av', 'admin.accounting.bank-transactions.reconcile')->name('admin.accounting.bank-transactions.reconcile');
    Volt::route('/admin/bokforing/banktransaktioner/{bankTransaction}', 'admin.accounting.bank-transactions.show')->name('admin.accounting.bank-transactions.show');
    Volt::route('/admin/bokforing/betaltjanster', 'admin.accounting.payment-providers.index')->name('admin.accounting.payment-providers.index');
    Volt::route('/admin/bokforing/leverantorer', 'admin.accounting.vendors.index')->name('admin.accounting.vendors.index');
    Volt::route('/admin/bokforing/leverantorer/{vendor}', 'admin.accounting.vendors.show')->name('admin.accounting.vendors.show');
    Volt::route('/admin/bokforing/revision', 'admin.accounting.revision.index')->name('admin.accounting.revision.index');
    Volt::route('/admin/bokforing/rapporter/verifikationslista', 'admin.accounting.reports.journal-list')->name('admin.accounting.reports.journal-list');
    Volt::route('/admin/bokforing/rapporter/huvudbok', 'admin.accounting.reports.general-ledger')->name('admin.accounting.reports.general-ledger');
    Volt::route('/admin/bokforing/rapporter/resultatrakning', 'admin.accounting.reports.income-statement')->name('admin.accounting.reports.income-statement');
    Volt::route('/admin/bokforing/rapporter/balansrakning', 'admin.accounting.reports.balance-sheet')->name('admin.accounting.reports.balance-sheet');
    Volt::route('/admin/bokforing/rapporter/budgetuppfoljning', 'admin.accounting.reports.budget-variance')->name('admin.accounting.reports.budget-variance');
    Volt::route('/admin/bokforing/rapporter/leverantorsrapport', 'admin.accounting.reports.vendor-report')->name('admin.accounting.reports.vendor-report');
    Volt::route('/admin/bokforing/budget', 'admin.accounting.budgets.index')->name('admin.accounting.budgets.index');
    Volt::route('/admin/bokforing/budget/{budget}/redigera', 'admin.accounting.budgets.edit')->name('admin.accounting.budgets.edit');
    Volt::route('/admin/bokforing/dokument', 'admin.accounting.documents.index')->name('admin.accounting.documents.index');
    Volt::route('/admin/bokforing/fakturainkorg', 'admin.accounting.inbox.index')->name('admin.accounting.inbox.index');
    Volt::route('/admin/bokforing/kontokort', 'admin.accounting.corporate-cards.index')->name('admin.accounting.corporate-cards.index');
    Volt::route('/admin/bokforing/fakturor/vantar-attest', 'admin.accounting.invoices.pending-approval')->name('admin.accounting.invoices.pending-approval');
    Volt::route('/admin/bokforing/attestregler', 'admin.accounting.approval-rules.index')->name('admin.accounting.approval-rules.index');
    Volt::route('/admin/bokforing/kvittoskanning', 'admin.accounting.receipt-scan')->name('admin.accounting.receipt-scan');
    Volt::route('/admin/bokforing/leverantorsfakturor', 'admin.accounting.invoices.payable.index')->name('admin.accounting.invoices.payable.index');
    Volt::route('/admin/bokforing/leverantorsfakturor/ny', 'admin.accounting.invoices.payable.create')->name('admin.accounting.invoices.payable.create');
    Volt::route('/admin/bokforing/leverantorsfakturor/importera-zip', 'admin.accounting.invoices.payable.import-zip')->name('admin.accounting.invoices.payable.import-zip');
    Volt::route('/admin/bokforing/leverantorsfakturor/{invoice}', 'admin.accounting.invoices.payable.show')->name('admin.accounting.invoices.payable.show');
    Volt::route('/admin/bokforing/kundreskontra', 'admin.accounting.invoices.receivable.index')->name('admin.accounting.invoices.receivable.index');
    Volt::route('/admin/bokforing/kundreskontra/ny', 'admin.accounting.invoices.receivable.create')->name('admin.accounting.invoices.receivable.create');
    Volt::route('/admin/bokforing/kundreskontra/{invoice}', 'admin.accounting.invoices.receivable.show')->name('admin.accounting.invoices.receivable.show');
    Volt::route('/admin/bokforing/kunder', 'admin.accounting.customers.index')->name('admin.accounting.customers.index');
    Volt::route('/admin/bokforing/kunder/{customer}', 'admin.accounting.customers.show')->name('admin.accounting.customers.show');

    // Payment admin
    Volt::route('/admin/betalningar', 'admin.payments.index')->name('admin.payments.index');

    // Mail templates admin
    Volt::route('/admin/mail-mallar', 'admin.mail-templates.index')->name('admin.mail-templates.index');
    Volt::route('/admin/mail-logg', 'admin.mail-log.index')->name('admin.mail-log.index');

    // Member admin
    Volt::route('/admin/members', 'admin.members.index')->name('admin.members.index');
    Volt::route('/admin/members/avaktiveringar', 'admin.members.deactivation-report')->name('admin.members.deactivation-report');
    Volt::route('/admin/members/raderingsbegaranden', 'admin.members.deletion-requests')->name('admin.members.deletion-requests');
    Volt::route('/admin/members/create', 'admin.members.create')->name('admin.members.create');
    Volt::route('/admin/members/{member}', 'admin.members.show')->name('admin.members.show');
    Volt::route('/admin/members/{member}/edit', 'admin.members.edit')->name('admin.members.edit');
    Volt::route('/admin/familjegrupper', 'admin.family-groups.index')->name('admin.family-groups.index');
});

// Public shop (no auth required)
Volt::route('/butik', 'shop.index')->name('shop.index');
Volt::route('/butik/{product:slug}', 'shop.show')->name('shop.show');

// Checkout (no auth required — guest checkout supported)
Volt::route('/kassa', 'shop.checkout')->name('shop.checkout');
Volt::route('/kassa/bekraftelse/{order}', 'shop.confirmation')->name('shop.confirmation');

// My orders (auth required)
Route::middleware('auth')->group(function () {
    Volt::route('/mina-bestallningar', 'shop.my-orders')->name('shop.my-orders');
});

// Public event pages (no auth required)
Route::get('/evenemang/kalender', EventCalendar::class)->name('events.calendar');
Volt::route('/evenemang', 'events.index')->name('events.index');
Volt::route('/evenemang/{event:slug}', 'events.show')->name('events.show');
Volt::route('/evenemang/{event:slug}/betala', 'events.payment')->name('events.payment');

// My event registrations (auth required)
Route::middleware('auth')->group(function () {
    Volt::route('/mina-anmalningar', 'events.my-registrations')->name('events.my-registrations');
});

// Accept event invitation via token (auth required)
Route::middleware('auth')->get(
    '/evenemang/{event:slug}/bjudan/{token}',
    EventInvitationController::class
)->name('events.accept-invitation');

// Payment initiation — no auth required (controllers verify ownership via session/user_id)
Route::post('/betala/stripe', [StripeController::class, 'checkout'])->name('payment.stripe.checkout');
Route::get('/betala/stripe/success', [StripeController::class, 'success'])->name('payment.stripe.success');
Route::post('/betala/swish', [SwishController::class, 'checkout'])->name('payment.swish.checkout');
Route::get('/betala/swish/pending', [SwishController::class, 'pending'])->name('payment.swish.pending');
Route::get('/betala/swish/status', [SwishController::class, 'status'])->name('payment.swish.status');
Route::get('/betala/swish/qr', [SwishController::class, 'qr'])->name('payment.swish.qr');

// Swish emulator (payment.swish_emulator_enabled) — dev/staging only, never real money
Route::get('/betala/swish/emulator', [SwishController::class, 'emulator'])->name('payment.swish.emulator');
Route::post('/betala/swish/emulator/approve', [SwishController::class, 'emulatorApprove'])->name('payment.swish.emulator.approve');
Route::post('/betala/swish/emulator/decline', [SwishController::class, 'emulatorDecline'])->name('payment.swish.emulator.decline');

// Payment callbacks (no auth, CSRF excluded in bootstrap/app.php)
Route::post('/stripe/webhook', [StripeController::class, 'webhook'])->name('stripe.webhook');
Route::post('/swish/callback', [SwishController::class, 'callback'])->name('swish.callback');

// Discord interactions (no auth — requests are signed by Discord and verified in the controller; CSRF excluded in bootstrap/app.php)
Route::post('/discord/interactions', DiscordInteractionController::class)->name('discord.interactions');

// DocSign callbacks (no auth — per-request secret token; CSRF excluded in bootstrap/app.php)
Route::match(['get', 'post'], '/docsign/callback/{signingRequest}/{token}/{event}', DocsignCallbackController::class)
    ->whereIn('event', ['signed', 'completed'])
    ->middleware('throttle:60,1')
    ->name('docsign.callback');

// Booking: public feeds (no auth required)
Route::get('/tv', [TvController::class, 'index'])->name('booking.tv');
Route::get('/tv/{resource:slug}', [TvController::class, 'room'])->name('booking.tv.room');
Route::get('/ical/{token?}', [IcalController::class, 'all'])->name('booking.ical.all');
Route::get('/rum/{resource:slug}/ical/{token?}', [IcalController::class, 'room'])->name('booking.ical.room');
Route::get('/mina-bokningar/ical/{token}', [IcalController::class, 'user'])->name('booking.ical.user');

// Survey respond page — public, token is the secret (invitation or "open-{id}")
Volt::route('/enkater/{token}', 'pages.surveys.respond')->name('surveys.respond');

// Standalone forms — requires auth
Volt::route('/formulär/{form}', 'pages.forms.fill')->name('forms.fill');

// Ticket QR codes — public, no auth (token is the secret)
Route::get('/biljett/{token}/qr', [TicketQrController::class, 'show'])->name('ticket.qr');

// Meetings — member-facing (auth required)
Route::middleware('auth')->group(function () {
    Route::get('/mina-moten', MyMeetings::class)->name('meetings.mine');
    Route::get('/moten/{meeting}', MeetingView::class)->name('meetings.show');
});

// Member card verification — public (HMAC token is the security)
Volt::route('/verifiera/{member}/{token}', 'pages.member-verify')->name('member.verify');

// Contact page (before catch-all so it gets the Livewire form)
Volt::route('/kontakt', 'pages.contact')->name('page.contact');

// Catch-all for CMS pages — must be last
Volt::route('/{path}', 'pages.show')->where('path', '.+')->name('page.show');
