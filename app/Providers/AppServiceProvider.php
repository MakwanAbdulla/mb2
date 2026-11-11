<?php

namespace App\Providers;

use App\Events\OrderPaid;
use App\Events\OrderPaymentFailed;
use App\Listeners\HandleOrderPaid;
use App\Listeners\HandleOrderPaymentFailed;
use App\Listeners\LogFailedMail;
use App\Listeners\LogSentMail;
use App\Mail\GmailApiTransport;
use App\Models\Booking\Booking;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use App\Models\Organization\OrganizationalRole;
use App\Models\Organization\Section;
use App\Models\User;
use App\Observers\BookingObserver;
use App\Observers\MemberObserver;
use App\Observers\MembershipPeriodObserver;
use App\Observers\SectionObserver;
use App\Observers\UserObserver;
use App\Services\Accounting\SumupClient;
use App\Services\Signing\DocsignClient;
use App\Services\Accounting\ZettleClient;
use App\Services\WidgetService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Discord\DiscordExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mime\Address;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WidgetService::class);

        $this->app->singleton(ZettleClient::class, fn () => new ZettleClient(
            clientId: config('services.zettle.client_id'),
            assertion: config('services.zettle.assertion'),
        ));

        $this->app->singleton(SumupClient::class, fn () => new SumupClient(
            apiKey: config('services.sumup.api_key'),
        ));

        $this->app->singleton(DocsignClient::class, fn () => DocsignClient::fromConfig());
    }

    public function boot(): void
    {
        // API rate limits: per token/user when authenticated, per IP otherwise.
        RateLimiter::for('api', fn (Request $request) => $request->user()
            ? Limit::perMinute(120)->by('user:'.$request->user()->id)
            : Limit::perMinute(60)->by('ip:'.$request->ip()));

        // Credential exchange endpoints — brute-force protection.
        RateLimiter::for('api-auth', fn (Request $request) => Limit::perMinute(10)->by('ip:'.$request->ip()));

        // Fortify calls Passkeys::ignoreRoutes() — load the routes file directly to bypass that
        Route::middleware('web')->group(base_path('vendor/laravel/passkeys/routes/routes.php'));

        // Reply-To via raw headers — Mail::alwaysReplyTo() går via Symfony-serialisering
        // som är trasig på delad hosting. Listenern sätter headern direkt istället.
        Event::listen(MessageSending::class, function (MessageSending $event) {
            $headers = $event->message->getHeaders();
            if (! $headers->has('Reply-To')) {
                $address = config('mail.reply_to.address');
                $name = config('mail.reply_to.name', config('app.name'));
                if ($address) {
                    $headers->addMailboxListHeader('Reply-To', [new Address($address, $name)]);
                }
            }
        });

        Event::listen(SocialiteWasCalled::class, [DiscordExtendSocialite::class, 'handle']);

        Event::listen(OrderPaid::class, HandleOrderPaid::class);
        Event::listen(OrderPaymentFailed::class, HandleOrderPaymentFailed::class);
        Event::listen(MessageSent::class, LogSentMail::class);

        // FailedMessageEvent is a Symfony Mailer event, not a Laravel event.
        // Register it via the Symfony event dispatcher after the mailer is resolved.
        $this->app->resolving('mailer', function ($mailer) {
            $transport = $mailer->getSymfonyTransport();
            if (method_exists($transport, 'getDispatcher')) {
                $transport->getDispatcher()->addListener(
                    FailedMessageEvent::class,
                    [app(LogFailedMail::class), 'handle'],
                );
            }
        });
        Mail::extend('gmail-api', fn () => new GmailApiTransport);

        Relation::morphMap([
            'member' => Member::class,
            'section' => Section::class,
            'org_role' => OrganizationalRole::class,
        ]);

        Member::observe(MemberObserver::class);
        MembershipPeriod::observe(MembershipPeriodObserver::class);
        User::observe(UserObserver::class);
        Booking::observe(BookingObserver::class);
        Section::observe(SectionObserver::class);

        $widgets = $this->app->make(WidgetService::class);

        $widgets->register(
            tag: 'membership-fees',
            view: 'components.cms.membership-fees',
            label: 'Medlemsavgifter',
            description: 'Visar en tabell med aktiva medlemsavgifter för innevarande år, och nästa år om de är inlagda.',
        );

        $widgets->register(
            tag: 'organizational-role',
            view: 'components.cms.organizational-role',
            label: 'Organisationsroll',
            description: 'Visar ledamöter och deras titlar i en roll (t.ex. styrelsen, valberedningen) för aktuell period.',
            params: [
                ['name' => 'slug', 'required' => true,  'description' => 'Rollens slug, t.ex. "styrelsen"'],
                ['name' => 'year', 'required' => false, 'description' => 'År (standard: innevarande år)'],
            ],
            example: '[organizational-role slug="styrelsen"]',
        );

        $widgets->register(
            tag: 'page',
            view: 'components.cms.page-link',
            label: 'Länk till sida',
            description: 'Infogar en länk till en annan sida. Sidans ID hittar du i admin-listan över sidor.',
            params: [
                ['name' => 'id',      'required' => true,  'description' => 'Sidans ID.'],
                ['name' => 'text',    'required' => false, 'description' => 'Egen länktext. Standard: sidans rubrik.'],
                ['name' => 'new_tab', 'required' => false, 'description' => 'Ange "true" för att öppna länken i en ny flik.'],
                ['name' => 'box',     'required' => false, 'description' => 'Ange "true" för att visa länken som en markerad ruta istället för en vanlig länk i texten. Standard: false.'],
            ],
            example: '[page id="1"]',
        );

        $widgets->register(
            tag: 'menu',
            view: 'components.cms.menu-link',
            label: 'Länk till menyalternativ',
            description: 'Infogar en länk baserad på ett menyalternativ. ID:t hittar du i admin-listan över menyalternativ.',
            params: [
                ['name' => 'id',      'required' => true,  'description' => 'Menyalternativets ID.'],
                ['name' => 'text',    'required' => false, 'description' => 'Egen länktext. Standard: menyalternativets etikett.'],
                ['name' => 'new_tab', 'required' => false, 'description' => 'Ange "true" för att öppna länken i en ny flik.'],
                ['name' => 'box',     'required' => false, 'description' => 'Ange "true" för att visa länken som en markerad ruta istället för en vanlig länk i texten. Standard: false.'],
            ],
            example: '[menu id="1"]',
        );

        $widgets->register(
            tag: 'route',
            view: 'components.cms.route-link',
            label: 'Länk till route',
            description: 'Infogar en länk till en namngiven Laravel-route. Extra attribut (utöver name/text/new_tab/box) skickas vidare som routeparametrar.',
            params: [
                ['name' => 'name',    'required' => true,  'description' => 'Routens namn, t.ex. "kontakt".'],
                ['name' => 'text',    'required' => false, 'description' => 'Egen länktext. Standard: routens namn.'],
                ['name' => 'new_tab', 'required' => false, 'description' => 'Ange "true" för att öppna länken i en ny flik.'],
                ['name' => 'box',     'required' => false, 'description' => 'Ange "true" för att visa länken som en markerad ruta istället för en vanlig länk i texten. Standard: false.'],
            ],
            example: '[route name="kontakt" text="Kontakta oss"]',
        );

        $widgets->register(
            tag: 'url',
            view: 'components.cms.url-link',
            label: 'Länk till extern url',
            description: 'Infogar en länk till en valfri extern webbadress.',
            params: [
                ['name' => 'url',     'required' => true,  'description' => 'Webbadressen, t.ex. "https://example.com".'],
                ['name' => 'text',    'required' => false, 'description' => 'Egen länktext. Standard: webbadressen.'],
                ['name' => 'new_tab', 'required' => false, 'description' => 'Ange "true" för att öppna länken i en ny flik.'],
                ['name' => 'box',     'required' => false, 'description' => 'Ange "true" för att visa länken som en markerad ruta istället för en vanlig länk i texten. Standard: false.'],
            ],
            example: '[url url="https://example.com" text="Besök webbplatsen"]',
        );
    }
}
