<?php

namespace App\Services;

use App\Models\Content\MailTemplate;

/**
 * Renders mail templates stored in the database.
 *
 * Templates use {{variable}} placeholders which are substituted at render time.
 * Body text is plain-text with double newlines as paragraph breaks.
 * Each mail class passes the relevant variables for its template type.
 */
class MailTemplateService
{
    /**
     * All supported template keys with their available variables.
     * Used both for substitution validation and for the admin UI reference panel.
     */
    public const TEMPLATES = [

        // ── Membership ────────────────────────────────────────────────────────
        'membership_invoice' => [
            'label' => 'Faktura – nytt medlemskap',
            'description' => 'Skickas när en ny order skapas och inväntar betalning.',
            'variables' => [
                'fornamn' => 'Medlemmens förnamn',
                'efternamn' => 'Medlemmens efternamn',
                'typ' => 'Medlemskapstyp (t.ex. Vuxen, Ungdom)',
                'ar' => 'Medlemskapsåret (t.ex. 2026)',
                'belopp' => 'Fakturabelopp (t.ex. 350,00 kr)',
                'ocr_nummer' => 'OCR-nummer för bankgiro/referens',
                'betala_url' => 'Länk till betalningssidan',
            ],
        ],

        'payment_reminder' => [
            'label' => 'Betalningspåminnelse',
            'description' => 'Skickas för obetalda orders efter konfigurerbart antal dagar.',
            'variables' => [
                'fornamn' => 'Medlemmens förnamn',
                'efternamn' => 'Medlemmens efternamn',
                'typ' => 'Vad betalningen gäller (t.ex. Vuxenmedlemskap 2026)',
                'belopp' => 'Fakturabelopp (t.ex. 350,00 kr)',
                'ocr_nummer' => 'OCR-nummer för bankgiro/referens',
                'betala_url' => 'Länk till betalningssidan',
                'dagar_sedan' => 'Antal dagar sedan faktura skickades',
            ],
        ],

        'membership_renewal' => [
            'label' => 'Förnyelsepåminnelse',
            'description' => 'Skickas till aktiva medlemmar inför det nya året.',
            'variables' => [
                'fornamn' => 'Medlemmens förnamn',
                'efternamn' => 'Medlemmens efternamn',
                'nuvarande_typ' => 'Nuvarande medlemskapstyp (t.ex. Vuxen)',
                'ar' => 'År att förnya till (t.ex. 2027)',
                'fornya_url' => 'Länk till förnyelseflödet',
            ],
        ],

        'membership_applied' => [
            'label' => 'Nytt medlemskap – ansökan mottagen',
            'description' => 'Skickas till den sökande när en ny medlemsansökan har registrerats.',
            'variables' => [
                'fornamn' => 'Sökandens förnamn',
                'efternamn' => 'Sökandens efternamn',
                'typ' => 'Typ av medlemskap (t.ex. Vuxen, Familj)',
                'ar' => 'Medlemskapsår (t.ex. 2026)',
            ],
        ],

        'membership_activated' => [
            'label' => 'Välkommen som medlem',
            'description' => 'Skickas när ett medlemskap aktiveras (betalning mottagen eller manuellt aktiverat).',
            'variables' => [
                'fornamn' => 'Medlemmens förnamn',
                'efternamn' => 'Medlemmens efternamn',
                'typ' => 'Typ av medlemskap (t.ex. Vuxen)',
                'ar' => 'Medlemskapsår (t.ex. 2026)',
                'medlemsnummer' => 'Tilldelat medlemsnummer',
            ],
        ],

        'membership_payment_failed' => [
            'label' => 'Betalning misslyckades',
            'description' => 'Skickas när en kort- eller Swish-betalning för medlemskap nekas/avbryts (Stripe checkout.session.expired, Swish DECLINED/ERROR, eller admin avslår manuellt).',
            'variables' => [
                'fornamn' => 'Medlemmens förnamn',
                'efternamn' => 'Medlemmens efternamn',
                'typ' => 'Typ av medlemskap (t.ex. Vuxen)',
                'ar' => 'Medlemskapsåret (t.ex. 2026)',
                'betala_url' => 'Länk till betalningssidan för att försöka igen',
            ],
        ],

        // ── Events ────────────────────────────────────────────────────────────
        'event_registration_confirmed' => [
            'label' => 'Evenemangsanmälan – bekräftad',
            'description' => 'Skickas när en anmälan bekräftas (direktanmälan, automatisk eller efter betalning).',
            'variables' => [
                'fornamn' => 'Förnamn',
                'event_namn' => 'Evenemangets namn',
                'biljett_typ' => 'Biljetttyp (t.ex. Vuxen)',
                'datum' => 'Datum för evenemang/tillfällen',
                'plats' => 'Plats / lokal',
                'instruktioner' => 'Arrangörens instruktioner (från evenemanget)',
            ],
        ],

        'event_registration_pending_payment' => [
            'label' => 'Evenemangsanmälan – inväntar betalning',
            'description' => 'Skickas när en plats är reserverad men betalning krävs för att bekräfta.',
            'variables' => [
                'fornamn' => 'Förnamn',
                'event_namn' => 'Evenemangets namn',
                'biljett_typ' => 'Biljetttyp',
                'datum' => 'Datum för evenemang/tillfällen',
                'plats' => 'Plats / lokal',
                'belopp' => 'Belopp att betala (t.ex. 100,00 kr)',
                'betala_url' => 'Länk till betalningssidan',
            ],
        ],

        'event_registration_pending_approval' => [
            'label' => 'Evenemangsanmälan – inväntar godkännande',
            'description' => 'Skickas när anmälan kräver manuellt godkännande av arrangören.',
            'variables' => [
                'fornamn' => 'Förnamn',
                'event_namn' => 'Evenemangets namn',
                'biljett_typ' => 'Biljetttyp',
                'datum' => 'Datum för evenemang/tillfällen',
                'plats' => 'Plats / lokal',
            ],
        ],

        'event_registration_waitlisted' => [
            'label' => 'Evenemangsanmälan – köplats',
            'description' => 'Skickas när alla platser är fulla och anmälan hamnar i kö.',
            'variables' => [
                'fornamn' => 'Förnamn',
                'event_namn' => 'Evenemangets namn',
                'biljett_typ' => 'Biljetttyp',
                'datum' => 'Datum för evenemang/tillfällen',
                'plats' => 'Plats / lokal',
            ],
        ],

        'event_registration_cancelled' => [
            'label' => 'Evenemangsanmälan – avbokad',
            'description' => 'Skickas när en anmälan avbokas eller avvisas av arrangören.',
            'variables' => [
                'fornamn' => 'Förnamn',
                'event_namn' => 'Evenemangets namn',
                'datum' => 'Datum för evenemang/tillfällen',
            ],
        ],

        'event_invitation' => [
            'label' => 'Evenemang – inbjudan',
            'description' => 'Skickas när en arrangör bjuder in en medlem till ett evenemang.',
            'variables' => [
                'fornamn' => 'Mottagarens förnamn',
                'event_namn' => 'Evenemangets namn',
                'datum' => 'Datum för evenemang/tillfällen',
                'plats' => 'Plats / lokal',
                'inbjudan_url' => 'Länk för att acceptera inbjudan',
                'event_url' => 'Länk till eventsidan',
            ],
        ],

        'event_message' => [
            'label' => 'Evenemang – meddelande från arrangör',
            'description' => 'Skickas när arrangören skickar ett meddelande till anmälda, inbjudna eller intresserade.',
            'variables' => [
                'fornamn' => 'Mottagarens förnamn',
                'event_namn' => 'Evenemangets namn',
                'meddelande' => 'Meddelandets brödtext (fri text från arrangören)',
                'event_url' => 'Länk till eventsidan',
            ],
        ],

        // ── Room Bookings ─────────────────────────────────────────────────────
        'booking_confirmed' => [
            'label' => 'Bokning – bekräftad',
            'description' => 'Skickas när en bokning bekräftas direkt (utan godkännandeflöde).',
            'variables' => [
                'fornamn' => 'Bokansvariges förnamn',
                'titel' => 'Bokningstitel',
                'lokal' => 'Lokalens namn',
                'datum' => 'Datum (t.ex. måndag 14 april 2026)',
                'starttid' => 'Starttid (t.ex. 18:00)',
                'sluttid' => 'Sluttid (t.ex. 22:00)',
            ],
        ],

        'booking_pending_approval' => [
            'label' => 'Bokning – inväntar godkännande',
            'description' => 'Skickas när en bokningsförfrågan kräver manuellt godkännande.',
            'variables' => [
                'fornamn' => 'Bokansvariges förnamn',
                'titel' => 'Bokningstitel',
                'lokal' => 'Lokalens namn',
                'datum' => 'Datum',
                'starttid' => 'Starttid',
                'sluttid' => 'Sluttid',
            ],
        ],

        'booking_reminder' => [
            'label' => 'Bokningspåminnelse',
            'description' => 'Skickas dagen innan en bekräftad bokning till dem som aktiverat påminnelser.',
            'variables' => [
                'fornamn' => 'Bokansvariges förnamn',
                'titel' => 'Bokningstitel',
                'lokal' => 'Lokalens namn',
                'datum' => 'Datum och starttid',
                'sluttid' => 'Sluttid',
                'plats' => 'Adress eller platsanvisning (om angiven)',
                'extrameddelande' => 'Valfritt meddelande från inställningar (kan vara tomt)',
            ],
        ],

        'booking_status_confirmed' => [
            'label' => 'Bokningsstatus – bekräftad av admin',
            'description' => 'Skickas när en bokningsförfrågan godkänns av administratör.',
            'variables' => [
                'fornamn' => 'Bokansvariges förnamn',
                'titel' => 'Bokningstitel',
                'lokal' => 'Lokalens namn',
                'datum' => 'Datum',
                'starttid' => 'Starttid',
                'sluttid' => 'Sluttid',
            ],
        ],

        'booking_status_cancelled' => [
            'label' => 'Bokningsstatus – avbokad',
            'description' => 'Skickas när en bokning avbokas.',
            'variables' => [
                'fornamn' => 'Bokansvariges förnamn',
                'titel' => 'Bokningstitel',
                'lokal' => 'Lokalens namn',
                'datum' => 'Datum',
            ],
        ],

        'booking_status_rejected' => [
            'label' => 'Bokningsstatus – nekad',
            'description' => 'Skickas när en bokningsförfrågan nekas av administratör.',
            'variables' => [
                'fornamn' => 'Bokansvariges förnamn',
                'titel' => 'Bokningstitel',
                'lokal' => 'Lokalens namn',
                'datum' => 'Datum',
                'anledning' => 'Anledning till avslag (om angiven)',
            ],
        ],

        // ── Venue Applications ────────────────────────────────────────────────
        'venue_application_received' => [
            'label' => 'Lokalansökan – inkommen (admin)',
            'description' => 'Skickas till administratören när en ny lokalansökan tagits emot.',
            'variables' => [
                'arrangemang' => 'Evenemangets namn',
                'ansvarig' => 'Ansvariges namn',
                'epost' => 'Ansvariges e-postadress',
                'period' => 'Önskad period (datum och tid)',
                'deltagare' => 'Antal förväntade deltagare',
                'granska_url' => 'Länk till granskningssidan i admin',
            ],
        ],

        'venue_application_approved' => [
            'label' => 'Lokalansökan – godkänd',
            'description' => 'Skickas till sökanden när ansökan om lokal godkänns.',
            'variables' => [
                'ansvarig' => 'Ansvariges förnamn',
                'arrangemang' => 'Evenemangets namn',
                'period' => 'Beviljad period',
                'admin_svar' => 'Administratörens meddelande (kan vara tomt)',
            ],
        ],

        'venue_application_rejected' => [
            'label' => 'Lokalansökan – nekad',
            'description' => 'Skickas till sökanden när ansökan om lokal nekas.',
            'variables' => [
                'ansvarig' => 'Ansvariges förnamn',
                'arrangemang' => 'Evenemangets namn',
                'period' => 'Önskad period',
                'admin_svar' => 'Administratörens motivering',
            ],
        ],

        // ── GDPR / Account Deletion ───────────────────────────────────────────
        'account_deletion_received' => [
            'label' => 'GDPR – begäran om radering mottagen',
            'description' => 'Skickas till medlemmen när en begäran om radering av personuppgifter registrerats.',
            'variables' => [
                'fornamn' => 'Medlemmens förnamn',
                'anledning' => 'Anledning angiven av medlemmen (kan vara tom)',
            ],
        ],

        'account_deletion_approved' => [
            'label' => 'GDPR – konto raderat',
            'description' => 'Skickas till medlemmen när raderingsbegäran godkänts och uppgifterna anonymiserats.',
            'variables' => [
                'fornamn' => 'Förnamnet (sparas undan innan anonymisering)',
            ],
        ],

        'account_deletion_rejected' => [
            'label' => 'GDPR – begäran nekad',
            'description' => 'Skickas till medlemmen när en raderingsbegäran nekats.',
            'variables' => [
                'fornamn' => 'Medlemmens förnamn',
                'admin_svar' => 'Administratörens motivering',
            ],
        ],

        // ── Shop ──────────────────────────────────────────────────────────────
        'shop_order_confirmed' => [
            'label' => 'Beställning – orderbekräftelse',
            'description' => 'Skickas till kunden när en beställning i webbutiken bekräftas.',
            'variables' => [
                'fornamn' => 'Kundens förnamn',
                'ordernummer' => 'Ordernummer / betalningsreferens',
                'belopp' => 'Totalt belopp (t.ex. 450,00 kr)',
            ],
        ],

        'shop_order_admin' => [
            'label' => 'Beställning – adminavisering',
            'description' => 'Skickas till administratören när en ny beställning lagts i webbutiken.',
            'variables' => [
                'ordernummer' => 'Ordernummer / betalningsreferens',
                'kund' => 'Kundens namn',
                'belopp' => 'Totalt belopp',
            ],
        ],

        'order_shipped' => [
            'label' => 'Beställning skickad',
            'description' => 'Skickas till kunden när en beställning markerats som skickad.',
            'variables' => [
                'fornamn' => 'Kundens förnamn',
                'ordernummer' => 'Ordernummer / betalningsreferens',
                'sparningsnummer' => 'Spårningsnummer (eller "–" om ej angivet)',
            ],
        ],

        'order_ready_for_pickup' => [
            'label' => 'Beställning redo för upphämtning',
            'description' => 'Skickas till kunden när en beställning är redo att hämtas i butiken.',
            'variables' => [
                'fornamn' => 'Kundens förnamn',
                'ordernummer' => 'Ordernummer / betalningsreferens',
            ],
        ],

        // ── Accounting ────────────────────────────────────────────────────────
        'ar_invoice' => [
            'label' => 'Kundfaktura – e-post',
            'description' => 'Skickas till kunden med kundfakturan bifogad som PDF.',
            'variables' => [
                'fornamn' => 'Kundens namn eller kontaktnamn',
                'faktura_nr' => 'Fakturanummer',
                'belopp' => 'Fakturabelopp (t.ex. 1 500,00 kr)',
                'forfallodatum' => 'Förfallodatum (t.ex. 30/04 2026)',
            ],
        ],

        'missing_receipt' => [
            'label' => 'Saknat kvitto (företagskort)',
            'description' => 'Skickas till kortinnehavaren när en transaktion saknar kvitto.',
            'variables' => [
                'fornamn' => 'Kortinnehavarens förnamn',
                'belopp' => 'Transaktionsbelopp',
                'beskrivning' => 'Transaktionsbeskrivning (t.ex. butiksnamn)',
                'datum' => 'Transaktionsdatum',
            ],
        ],

        'invoice_matched' => [
            'label' => 'Faktura matchad mot betalning',
            'description' => 'Skickas internt när en faktura automatiskt matchats mot en bankbetalning.',
            'variables' => [
                'faktura_nr' => 'Fakturanummer',
                'belopp' => 'Matchat belopp',
                'referens' => 'Betalningsreferens',
            ],
        ],

        // ── Contact Form ──────────────────────────────────────────────────────
        'contact_confirmation' => [
            'label' => 'Kontaktformulär – bekräftelse till avsändaren',
            'description' => 'Skickas automatiskt till den som fyllt i kontaktformuläret.',
            'variables' => [
                'fornamn' => 'Avsändarens förnamn (första ordet i namnet)',
                'amne' => 'Ämnet som angavs i formuläret',
                'meddelande' => 'Meddelandet som skickades (återges som citat)',
            ],
        ],

        // ── Member admin notifications ────────────────────────────────────────
        'membership_applied_admin' => [
            'label' => 'Ny medlemsansökan – adminavisering',
            'description' => 'Skickas till adminkontot när en ny ansökan inkommer (självregistrering eller admin).',
            'variables' => [
                'namn' => 'Sökandens fullständiga namn',
                'epost' => 'Sökandens e-postadress',
                'typ' => 'Typ av medlemskap (t.ex. Vuxen, Familj)',
                'datum' => 'Datum och tid för ansökan',
                'admin_url' => 'Länk till medlemsprofilen i admin',
            ],
        ],

        // ── Rentals ───────────────────────────────────────────────────────────
        'rental_assigned' => [
            'label' => 'Hyra – tilldelad',
            'description' => 'Skickas till medlemmen när en hyrd tillgång (t.ex. skåp) tilldelas.',
            'variables' => [
                'fornamn' => 'Medlemmens förnamn',
                'typ' => 'Typ av hyrd tillgång (t.ex. Skåp)',
                'etikett' => 'Artikelns etikett/nummer (t.ex. #42)',
                'pris' => 'Pris per år (t.ex. 200,00 kr/år)',
                'startdatum' => 'Datum då hyran börjar gälla',
            ],
        ],

        'rental_terminated' => [
            'label' => 'Hyra – avslutad',
            'description' => 'Skickas till medlemmen när en hyra avslutas.',
            'variables' => [
                'fornamn' => 'Medlemmens förnamn',
                'typ' => 'Typ av hyrd tillgång (t.ex. Skåp)',
                'etikett' => 'Artikelns etikett/nummer (t.ex. #42)',
                'slutdatum' => 'Datum då hyran upphörde',
            ],
        ],

        // ── Surveys ───────────────────────────────────────────────────────────
        'survey_invitation' => [
            'label' => 'Enkätinbjudan',
            'description' => 'Skickas till inbjudna när en enkät öppnar för svar.',
            'variables' => [
                'enkattitel' => 'Enkätens titel',
                'beskrivning' => 'Enkätens beskrivning (kan vara tom)',
                'svara_url' => 'Personlig länk för att fylla i enkäten',
            ],
        ],

        // ── Auth ──────────────────────────────────────────────────────────────
        'magic_link' => [
            'label' => 'Inloggningslänk (magic link)',
            'description' => 'Skickas när en användare begär en lösenordsfri inloggningslänk.',
            'variables' => [
                'app_name' => 'Applikationens namn (t.ex. Armagedon)',
                'fornamn' => 'Användarens förnamn',
                'login_url' => 'Den unika inloggningslänken (gäller 15 min)',
            ],
        ],
    ];

    /**
     * Render a template: substitute variables and return subject + HTML body.
     * Returns null if the template is not found or is inactive.
     *
     * @param  array<string, string>  $variables
     * @return array{subject: string, html: string}|null
     */
    public function render(string $key, array $variables): ?array
    {
        $template = MailTemplate::findByKey($key);

        if (! $template || ! $template->is_active) {
            return null;
        }

        $subject = $this->substitute($template->subject, $variables);
        $html = $this->bodyToHtml($this->substitute($template->body, $variables));

        return ['subject' => $subject, 'html' => $html];
    }

    /**
     * Substitute {{variable}} placeholders in a string.
     */
    public function substitute(string $text, array $variables): string
    {
        $search = array_map(fn ($k) => '{{'.$k.'}}', array_keys($variables));
        $replace = array_values($variables);

        return str_replace($search, $replace, $text);
    }

    /**
     * Convert plain-text body to simple HTML paragraphs.
     * Double newline → paragraph break, single newline → <br>.
     */
    public function bodyToHtml(string $text): string
    {
        $paragraphs = preg_split('/\n{2,}/', trim($text));
        $html = '';
        foreach ($paragraphs as $para) {
            $para = htmlspecialchars($para, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $para = nl2br($para);
            $html .= "<p>{$para}</p>\n";
        }

        return $html;
    }

    /**
     * Return sample variable values for a given template key (used for test sends).
     *
     * @return array<string, string>
     */
    public function sampleVariables(string $key): array
    {
        return match ($key) {
            'membership_invoice' => [
                'fornamn' => 'Anna',
                'efternamn' => 'Svensson',
                'typ' => 'Vuxen',
                'ar' => (string) now()->year,
                'belopp' => '350,00 kr',
                'ocr_nummer' => '20260004211',
                'betala_url' => url('/bli-medlem/betala'),
            ],
            'payment_reminder' => [
                'fornamn' => 'Anna',
                'efternamn' => 'Svensson',
                'typ' => 'Vuxenmedlemskap '.now()->year,
                'belopp' => '350,00 kr',
                'ocr_nummer' => '20260004211',
                'betala_url' => url('/bli-medlem/betala'),
                'dagar_sedan' => '14',
            ],
            'membership_renewal' => [
                'fornamn' => 'Anna',
                'efternamn' => 'Svensson',
                'nuvarande_typ' => 'Vuxen',
                'ar' => (string) (now()->year + 1),
                'fornya_url' => url('/bli-medlem'),
            ],
            'membership_applied' => [
                'fornamn' => 'Anna',
                'efternamn' => 'Svensson',
                'typ' => 'Vuxen',
                'ar' => (string) now()->year,
            ],
            'membership_payment_failed' => [
                'fornamn' => 'Anna',
                'efternamn' => 'Svensson',
                'typ' => 'Vuxen',
                'ar' => (string) now()->year,
                'betala_url' => url('/bli-medlem/betala'),
            ],
            'membership_activated' => [
                'fornamn' => 'Anna',
                'efternamn' => 'Svensson',
                'typ' => 'Vuxen',
                'ar' => (string) now()->year,
                'medlemsnummer' => '2026-042',
            ],
            'event_registration_confirmed' => [
                'fornamn' => 'Anna',
                'event_namn' => 'Spelkväll mars 2026',
                'biljett_typ' => 'Vuxen',
                'datum' => '14 mars 2026, 18:00',
                'plats' => 'Spelrummet',
                'instruktioner' => 'Ta med eget spel om du vill!',
            ],
            'event_registration_pending_payment' => [
                'fornamn' => 'Anna',
                'event_namn' => 'Spelkväll mars 2026',
                'biljett_typ' => 'Vuxen',
                'datum' => '14 mars 2026, 18:00',
                'plats' => 'Spelrummet',
                'belopp' => '100,00 kr',
                'betala_url' => url('/evenemang/spelkvall-mars-2026/betala'),
            ],
            'event_registration_pending_approval' => [
                'fornamn' => 'Anna',
                'event_namn' => 'Spelkväll mars 2026',
                'biljett_typ' => 'Vuxen',
                'datum' => '14 mars 2026, 18:00',
                'plats' => 'Spelrummet',
            ],
            'event_registration_waitlisted' => [
                'fornamn' => 'Anna',
                'event_namn' => 'Spelkväll mars 2026',
                'biljett_typ' => 'Vuxen',
                'datum' => '14 mars 2026, 18:00',
                'plats' => 'Spelrummet',
            ],
            'event_registration_cancelled' => [
                'fornamn' => 'Anna',
                'event_namn' => 'Spelkväll mars 2026',
                'datum' => '14 mars 2026, 18:00',
            ],
            'booking_confirmed', 'booking_pending_approval' => [
                'fornamn' => 'Anna',
                'titel' => 'Spelkväll med sektionen',
                'lokal' => 'Stora spelrummet',
                'datum' => 'fredag 17 april 2026',
                'starttid' => '18:00',
                'sluttid' => '22:00',
            ],
            'booking_reminder' => [
                'fornamn' => 'Anna',
                'titel' => 'Spelkväll med sektionen',
                'lokal' => 'Stora spelrummet',
                'datum' => 'fredag 17 april 2026, 18:00',
                'sluttid' => '22:00',
                'plats' => 'Sandwalls plats 1, Borås',
                'extrameddelande' => 'Glöm inte att ta med nyckeln!',
            ],
            'booking_status_confirmed' => [
                'fornamn' => 'Anna',
                'titel' => 'Spelkväll med sektionen',
                'lokal' => 'Stora spelrummet',
                'datum' => 'fredag 17 april 2026',
                'starttid' => '18:00',
                'sluttid' => '22:00',
            ],
            'booking_status_cancelled' => [
                'fornamn' => 'Anna',
                'titel' => 'Spelkväll med sektionen',
                'lokal' => 'Stora spelrummet',
                'datum' => 'fredag 17 april 2026',
            ],
            'booking_status_rejected' => [
                'fornamn' => 'Anna',
                'titel' => 'Spelkväll med sektionen',
                'lokal' => 'Stora spelrummet',
                'datum' => 'fredag 17 april 2026',
                'anledning' => 'Lokalen är redan bokad den perioden.',
            ],
            'venue_application_received' => [
                'arrangemang' => 'Bordsspelsfestival 2026',
                'ansvarig' => 'Erik Johansson',
                'epost' => 'erik@example.se',
                'period' => '12 juni 2026, 10:00 – 13 juni 2026, 18:00',
                'deltagare' => '80',
                'granska_url' => url('/admin/lokalbokningar'),
            ],
            'venue_application_approved' => [
                'ansvarig' => 'Erik',
                'arrangemang' => 'Bordsspelsfestival 2026',
                'period' => '12 juni 2026, 10:00 – 13 juni 2026, 18:00',
                'admin_svar' => 'Välkommen! Nyckeln hämtas i receptionen.',
            ],
            'venue_application_rejected' => [
                'ansvarig' => 'Erik',
                'arrangemang' => 'Bordsspelsfestival 2026',
                'period' => '12 juni 2026, 10:00 – 13 juni 2026, 18:00',
                'admin_svar' => 'Lokalen är tyvärr redan bokad den aktuella perioden.',
            ],
            'account_deletion_received' => [
                'fornamn' => 'Anna',
                'anledning' => 'Jag är inte längre aktiv i föreningen.',
            ],
            'account_deletion_approved' => [
                'fornamn' => 'Anna',
            ],
            'account_deletion_rejected' => [
                'fornamn' => 'Anna',
                'admin_svar' => 'Ditt konto har ett utestående saldo som måste regleras först.',
            ],
            'shop_order_confirmed' => [
                'fornamn' => 'Anna',
                'ordernummer' => 'ORD-2026-0042',
                'belopp' => '450,00 kr',
            ],
            'shop_order_admin' => [
                'ordernummer' => 'ORD-2026-0042',
                'kund' => 'Anna Svensson',
                'belopp' => '450,00 kr',
            ],
            'order_shipped' => [
                'fornamn' => 'Anna',
                'ordernummer' => 'ORD-2026-0042',
                'sparningsnummer' => '123456789SE',
            ],
            'order_ready_for_pickup' => [
                'fornamn' => 'Anna',
                'ordernummer' => 'ORD-2026-0042',
            ],
            'ar_invoice' => [
                'fornamn' => 'Exempelkund AB',
                'faktura_nr' => 'F-2026-0042',
                'belopp' => '1 500,00 kr',
                'forfallodatum' => '30/04 2026',
            ],
            'missing_receipt' => [
                'fornamn' => 'Anna',
                'belopp' => '349,00 kr',
                'beskrivning' => 'COOP BORÅS',
                'datum' => '15/04 2026',
            ],
            'invoice_matched' => [
                'faktura_nr' => 'F-2026-0042',
                'belopp' => '1 500,00 kr',
                'referens' => 'OCR 20260004211',
            ],
            'contact_confirmation' => [
                'fornamn' => 'Anna',
                'amne' => 'Fråga om öppettider',
                'meddelande' => 'Hej! Jag undrar om ni har öppet på söndagar...',
            ],
            'membership_applied_admin' => [
                'namn' => 'Anna Svensson',
                'epost' => 'anna@example.se',
                'typ' => 'Vuxen',
                'datum' => now()->locale('sv')->isoFormat('D MMMM YYYY, HH:mm'),
                'admin_url' => url('/admin/members/1'),
            ],
            'rental_assigned' => [
                'fornamn' => 'Anna',
                'typ' => 'Skåp',
                'etikett' => '#42',
                'pris' => '200,00 kr/år',
                'startdatum' => now()->format('d/m Y'),
            ],
            'rental_terminated' => [
                'fornamn' => 'Anna',
                'typ' => 'Skåp',
                'etikett' => '#42',
                'slutdatum' => now()->format('d/m Y'),
            ],
            'survey_invitation' => [
                'enkattitel' => 'Nöjdhetsenkät 2026',
                'beskrivning' => 'Vi vill veta vad du tycker om vår verksamhet!',
                'svara_url' => url('/enkater/svara/example-token'),
            ],
            'magic_link' => [
                'app_name' => config('app.name'),
                'fornamn' => 'Anna',
                'login_url' => url('/auth/magic-link/example-token'),
            ],
            default => [],
        };
    }
}
