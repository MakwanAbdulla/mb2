<?php

namespace Database\Seeders;

use App\Models\Content\MailTemplate;
use App\Services\MailTemplateService;
use Illuminate\Database\Seeder;

class MailTemplateSeeder extends Seeder
{
    /**
     * Default Swedish templates. Run on fresh installs and safe to re-run
     * (firstOrCreate — existing admin edits are preserved on re-run).
     */
    public function run(): void
    {
        $templates = [

            // ── Membership ────────────────────────────────────────────────────
            [
                'key'     => 'membership_invoice',
                'subject' => 'Faktura – {{typ}}smedlemskap {{ar}} – Armagedon',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Tack för din anmälan till Armagedon. Nedan hittar du din faktura för {{typ}}smedlemskap {{ar}}.

Betalningsinformation finns nedan – ange alltid OCR-numret {{ocr_nummer}} som referens så att vi kan matcha din betalning automatiskt.

Du kan även betala direkt via länken nedan med Swish eller kort.

{{betala_url}}

Har du frågor? Kontakta oss på info@armagedon.se.

Välkommen till föreningen!
BODY,
            ],

            [
                'key'     => 'payment_reminder',
                'subject' => 'Påminnelse – obetald faktura – Armagedon',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Vi har ännu inte tagit emot din betalning för {{typ}}.

Det är {{dagar_sedan}} dagar sedan fakturan skickades. Belopp: {{belopp}}.

Var god ange OCR-numret {{ocr_nummer}} som referens vid betalning, eller klicka på länken nedan för att betala med Swish eller kort:

{{betala_url}}

Har du redan betalt kan du bortse från detta meddelande – det kan ta ett par bankdagar innan betalningen registreras.

Kontakta oss på info@armagedon.se om du har frågor.
BODY,
            ],

            [
                'key'     => 'membership_renewal',
                'subject' => 'Dags att förnya ditt Armagedon-medlemskap {{ar}}!',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Ditt {{nuvarande_typ}}smedlemskap i Armagedon gäller till och med i år. Nu är det dags att förnya för {{ar}}!

Som medlem får du tillgång till alla föreningens aktiviteter, spelkvällar och evenemang under hela året.

Förnya ditt medlemskap via länken nedan – det tar bara någon minut:

{{fornya_url}}

Har du frågor är du välkommen att höra av dig på info@armagedon.se.

Vi ses på spelkvällen!
BODY,
            ],

            [
                'key'     => 'membership_applied',
                'subject' => 'Ansökan mottagen – Armagedon',
                'body'    => <<<'BODY'
Tack {{fornamn}}! Din ansökan om {{typ}}smedlemskap i Armagedon har mottagits.

Vi skickar betalningsinformation separat. När betalningen är registrerad aktiveras ditt medlemskap automatiskt.

Har du frågor? Kontakta oss på info@armagedon.se.
BODY,
            ],

            [
                'key'     => 'membership_activated',
                'subject' => 'Välkommen som medlem – Armagedon',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Din betalning är mottagen och ditt {{typ}}smedlemskap i Armagedon för {{ar}} är nu aktivt.

Vi ser fram emot att träffa dig på spelkvällar och evenemang. Du hittar aktuellt schema på vår webbplats.

Har du frågor? Kontakta oss på info@armagedon.se.
BODY,
            ],

            [
                'key'     => 'membership_payment_failed',
                'subject' => 'Din betalning gick inte igenom – Armagedon',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Din betalning för {{typ}}smedlemskapet {{ar}} gick tyvärr inte igenom.

Det kan bero på att kortet nekades, att betalningen avbröts, eller att Swish-sessionen gick ut. Ditt medlemskap har inte blivit aktiverat ännu.

Du kan försöka igen här:

{{betala_url}}

Har du frågor? Kontakta oss på info@armagedon.se.
BODY,
            ],

            // ── Events ────────────────────────────────────────────────────────
            [
                'key'     => 'event_registration_confirmed',
                'subject' => 'Anmälan bekräftad – {{event_namn}}',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Din anmälan till {{event_namn}} är bekräftad.

{{datum}}
{{plats}}

{{instruktioner}}

Vi ser fram emot att se dig!
BODY,
            ],

            [
                'key'     => 'event_registration_pending_payment',
                'subject' => 'Slutför din anmälan – {{event_namn}}',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Vi har tagit emot din anmälan till {{event_namn}} ({{biljett_typ}}). Din plats är reserverad tills betalningen är genomförd.

Belopp att betala: {{belopp}}

Klicka på knappen nedan för att slutföra betalningen och bekräfta din plats.

{{betala_url}}
BODY,
            ],

            [
                'key'     => 'event_registration_pending_approval',
                'subject' => 'Anmälan mottagen – {{event_namn}}',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Vi har tagit emot din anmälan till {{event_namn}} ({{biljett_typ}}).

Din anmälan granskas av arrangören och du kommer att få ett besked så snart den handläggs.

{{datum}}
{{plats}}
BODY,
            ],

            [
                'key'     => 'event_registration_waitlisted',
                'subject' => 'Du står i kö – {{event_namn}}',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Din anmälan till {{event_namn}} ({{biljett_typ}}) har mottagits, men alla platser är för tillfället fulla.

Du har placerats i kö och meddelas automatiskt om en plats öppnas upp.

{{datum}}
{{plats}}
BODY,
            ],

            [
                'key'     => 'event_registration_cancelled',
                'subject' => 'Anmälan avbokad – {{event_namn}}',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Din anmälan till {{event_namn}} ({{datum}}) har avbokats.

Har du frågor är du välkommen att höra av dig till oss.
BODY,
            ],

            [
                'key'     => 'event_invitation',
                'subject' => 'Du är inbjuden – {{event_namn}}',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Du har blivit inbjuden till {{event_namn}}.

{{datum}}
{{plats}}

Klicka på länken nedan för att acceptera inbjudan och anmäla dig:
{{inbjudan_url}}

Du kan också läsa mer om eventet här:
{{event_url}}
BODY,
            ],

            [
                'key'     => 'event_message',
                'subject' => 'Meddelande om {{event_namn}}',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

{{meddelande}}

Läs mer om eventet:
{{event_url}}
BODY,
            ],

            // ── Room Bookings ─────────────────────────────────────────────────
            [
                'key'     => 'booking_confirmed',
                'subject' => 'Bokning bekräftad – {{titel}}',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Din bokning är bekräftad. Detaljerna hittar du nedan.
BODY,
            ],

            [
                'key'     => 'booking_pending_approval',
                'subject' => 'Bokningsförfrågan mottagen – {{titel}}',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Din bokningsförfrågan har mottagits och väntar på godkännande. Du hör av dig så snart vi handlagt den.
BODY,
            ],

            [
                'key'     => 'booking_reminder',
                'subject' => 'Påminnelse: {{titel}} {{datum}}',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Du har en bokning imorgon – {{titel}} i {{lokal}}.

{{extrameddelande}}

Behöver du inte lokalen längre? Avboka så tidigt som möjligt så att andra kan boka den.
BODY,
            ],

            [
                'key'     => 'booking_status_confirmed',
                'subject' => 'Bokning bekräftad – {{titel}}',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Din bokningsförfrågan för {{titel}} har godkänts av administratören.
BODY,
            ],

            [
                'key'     => 'booking_status_cancelled',
                'subject' => 'Bokning avbokad – {{titel}}',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Din bokning av {{lokal}} ({{titel}}) har avbokats.

Har du frågor? Kontakta oss på info@armagedon.se.
BODY,
            ],

            [
                'key'     => 'booking_status_rejected',
                'subject' => 'Bokningsförfrågan nekad – {{titel}}',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Din bokningsförfrågan för {{titel}} har tyvärr inte kunnat beviljas.

{{anledning}}

Har du frågor? Kontakta oss på info@armagedon.se.
BODY,
            ],

            // ── Venue Applications ────────────────────────────────────────────
            [
                'key'     => 'venue_application_received',
                'subject' => 'Ny lokalansökan: {{arrangemang}}',
                'body'    => <<<'BODY'
En ny ansökan om lån av lokal har skickats in och väntar på granskning.

Sökande: {{ansvarig}} ({{epost}})
Arrangemang: {{arrangemang}}
Period: {{period}}
Deltagare: {{deltagare}}
BODY,
            ],

            [
                'key'     => 'venue_application_approved',
                'subject' => 'Ansökan godkänd – {{arrangemang}}',
                'body'    => <<<'BODY'
Hej {{ansvarig}}!

Er ansökan om att låna lokalen för {{arrangemang}} har godkänts. Era bokningar finns nu registrerade i systemet.

{{admin_svar}}

Välkommen!
BODY,
            ],

            [
                'key'     => 'venue_application_rejected',
                'subject' => 'Ansökan nekad – {{arrangemang}}',
                'body'    => <<<'BODY'
Hej {{ansvarig}}!

Er ansökan om att låna lokalen för {{arrangemang}} har tyvärr inte kunnat godkännas.

{{admin_svar}}

Har du frågor är du välkommen att kontakta oss på info@armagedon.se.
BODY,
            ],

            // ── GDPR / Account Deletion ───────────────────────────────────────
            [
                'key'     => 'account_deletion_received',
                'subject' => 'Begäran om radering mottagen – Armagedon',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Vi har tagit emot din begäran om radering av personuppgifter (GDPR artikel 17).

Vi kommer att granska din begäran och återkommer med ett beslut inom 30 dagar. Du kan när som helst återkalla begäran via din profil.

Har du frågor? Kontakta oss på info@armagedon.se.
BODY,
            ],

            [
                'key'     => 'account_deletion_approved',
                'subject' => 'Ditt konto hos Armagedon har raderats',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Din begäran om radering av personuppgifter har godkänts och genomförts. Dina personuppgifter har anonymiserats i enlighet med GDPR artikel 17.

Ditt konto och alla tillhörande personuppgifter är nu borttagna från våra system.

Tack för din tid som medlem i Armagedon. Du är alltid välkommen tillbaka.
BODY,
            ],

            [
                'key'     => 'account_deletion_rejected',
                'subject' => 'Angående din begäran om radering – Armagedon',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Vi har granskat din begäran om radering av personuppgifter och tyvärr inte kunnat bevilja den.

{{admin_svar}}

Om du har frågor om beslutet eller vill överklaga är du välkommen att kontakta oss på info@armagedon.se. Du har också rätt att lämna klagomål till Integritetsskyddsmyndigheten (IMY) på www.imy.se.
BODY,
            ],

            // ── Shop ──────────────────────────────────────────────────────────
            [
                'key'     => 'shop_order_confirmed',
                'subject' => 'Orderbekräftelse {{ordernummer}} – Armagedon',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Tack för din beställning! Vi har tagit emot din betalning och bearbetar nu din order.

Belopp: {{belopp}}
Ordernummer: {{ordernummer}}

Du kan följa din beställning under Mina beställningar på vår webbplats. Har du frågor? Kontakta oss på info@armagedon.se.
BODY,
            ],

            [
                'key'     => 'shop_order_admin',
                'subject' => 'Ny beställning {{ordernummer}} – Armagedon',
                'body'    => <<<'BODY'
En ny beställning har lagts av {{kund}}.

Belopp: {{belopp}}
Order: {{ordernummer}}
BODY,
            ],

            [
                'key'     => 'order_ready_for_pickup',
                'subject' => 'Din beställning {{ordernummer}} är redo att hämtas – Armagedon',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Din beställning {{ordernummer}} är nu redo att hämtas hos oss.

Du hittar oss på vår ordinarie adress under öppettiderna. Ta med ordernumret när du hämtar.

Har du frågor? Kontakta oss på info@armagedon.se.
BODY,
            ],

            [
                'key'     => 'order_shipped',
                'subject' => 'Din beställning {{ordernummer}} är skickad – Armagedon',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Din beställning {{ordernummer}} är nu på väg till dig.

Spårningsnummer: {{sparningsnummer}}

Har du frågor om din beställning? Kontakta oss på info@armagedon.se.
BODY,
            ],

            // ── Accounting ────────────────────────────────────────────────────
            [
                'key'     => 'ar_invoice',
                'subject' => 'Faktura #{{faktura_nr}} från Armagedon',
                'body'    => <<<'BODY'
Hej {{fornamn}},

Bifogat finns faktura #{{faktura_nr}} från Armagedon.

Belopp: {{belopp}}
Förfallodatum: {{forfallodatum}}

Fakturan finns även bifogad som PDF. Har du frågor? Kontakta oss på info@armagedon.se.
BODY,
            ],

            [
                'key'     => 'missing_receipt',
                'subject' => 'Kvitto saknas – {{belopp}} ({{datum}})',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Vi har fått ett kontoutdrag med ett köp på ditt föreningskort som ännu inte har något kvitto:

Butik: {{beskrivning}}
Belopp: {{belopp}}
Datum: {{datum}}

Skicka in kvittot så fort du kan – kassören kan inte bokföra köpet utan underlag.
BODY,
            ],

            [
                'key'     => 'invoice_matched',
                'subject' => 'Faktura {{faktura_nr}} matchad – {{belopp}}',
                'body'    => <<<'BODY'
En betalning har automatiskt matchats mot faktura {{faktura_nr}}.

Belopp: {{belopp}}
Referens: {{referens}}
BODY,
            ],

            // ── Contact Form ──────────────────────────────────────────────────
            [
                'key'     => 'contact_confirmation',
                'subject' => 'Vi har mottagit ditt meddelande – Armagedon',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Tack för att du hörde av dig. Vi har mottagit ditt meddelande om "{{amne}}" och återkommer så snart vi kan – normalt inom 1–3 arbetsdagar.

Har du brådskande frågor är du välkommen att höra av dig direkt på info@armagedon.se.
BODY,
            ],

            // ── Member admin ──────────────────────────────────────────────────
            [
                'key'     => 'membership_applied_admin',
                'subject' => 'Ny medlemsansökan: {{namn}}',
                'body'    => <<<'BODY'
En ny ansökan om medlemskap har inkommit.

Sökande: {{namn}} ({{epost}})
Typ: {{typ}}
Datum: {{datum}}
BODY,
            ],

            // ── Rentals ───────────────────────────────────────────────────────
            [
                'key'     => 'rental_assigned',
                'subject' => 'Hyra tilldelad – {{typ}} {{etikett}}',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Du har tilldelats {{typ}} {{etikett}} i Armagedon från och med {{startdatum}}.

Avgiften är {{pris}} och debiteras i samband med nästa förnyelse av ditt medlemskap. Har du frågor? Kontakta oss på info@armagedon.se.
BODY,
            ],

            [
                'key'     => 'rental_terminated',
                'subject' => 'Hyra avslutad – {{typ}} {{etikett}}',
                'body'    => <<<'BODY'
Hej {{fornamn}}!

Din hyra av {{typ}} {{etikett}} har avslutats per {{slutdatum}}.

Om du har frågor är du välkommen att kontakta oss på info@armagedon.se.
BODY,
            ],

            // ── Surveys ───────────────────────────────────────────────────────
            [
                'key'     => 'survey_invitation',
                'subject' => 'Inbjudan att fylla i enkät: {{enkattitel}}',
                'body'    => <<<'BODY'
Hej!

Du har blivit inbjuden att fylla i enkäten: {{enkattitel}}

{{beskrivning}}

Länken nedan är personlig och kan bara användas en gång.
BODY,
            ],
        ];

        foreach ($templates as $data) {
            $def = MailTemplateService::TEMPLATES[$data['key']] ?? [];
            MailTemplate::firstOrCreate(['key' => $data['key']], [
                'key'         => $data['key'],
                'label'       => $def['label'] ?? $data['key'],
                'description' => $def['description'] ?? '',
                'subject'     => $data['subject'],
                'body'        => $data['body'],
                'is_active'   => true,
            ]);
        }
    }
}
