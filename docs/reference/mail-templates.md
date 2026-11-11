# Mail template system

All transactional mail goes through `MailTemplateService` + `mail_templates`-tabellen. Administratörer redigerar subject + body via `/admin/mail-mallar`.

## Arkitektur

| Fil | Roll |
|---|---|
| `app/Services/MailTemplateService.php` | Registry (`TEMPLATES`-konstant), `render()`, `sampleVariables()` |
| `app/Models/Content/MailTemplate.php` | DB-modell (key, label, description, subject, body, is_active) |
| `database/seeders/MailTemplateSeeder.php` | Standardinnehåll på svenska — `firstOrCreate`, admin-ändringar bevaras vid omseeding |
| `resources/views/emails/template-rendered.blade.php` | Delad HTML-layout (header, body, details-tabell, CTA-knapp, QR, trackningspixel, footer) |

## Mönster för varje mail-klass

```php
class FooMail extends Mailable {
    use WithMailTracking;

    public string  $bodyHtml;
    public array   $detailsRows = [];
    public ?string $ctaUrl      = null;
    public ?string $ctaLabel    = null;

    public function __construct(...) { $this->initTracking(); }

    public function envelope(): Envelope {
        $rendered = app(MailTemplateService::class)->render('template_key', $this->variables());
        return new Envelope(subject: $rendered['subject'] ?? 'Fallback subject');
    }

    public function content(): Content {
        $rendered       = app(MailTemplateService::class)->render('template_key', $this->variables());
        $this->bodyHtml = $rendered['html'] ?? '<p>Fallback.</p>';
        $this->detailsRows = [...];
        $this->ctaUrl   = ...;
        $this->ctaLabel = ...;
        return new Content(view: 'emails.template-rendered');
    }

    private function variables(): array { return [...]; }
}
```

`render()` returnerar null om mallen är inaktiv eller saknas i DB — tillhandahåll alltid fallback-strängar.

## Lägga till ett nytt mail

1. Lägg till post i `MailTemplateService::TEMPLATES` (key, label, description, variables-array)
2. Lägg till exempeldata i `sampleVariables()` match-case
3. Skriv mail-klassen enligt mönstret ovan
4. Lägg till standardinnehåll på svenska i `MailTemplateSeeder`
5. Ta bort eventuell gammal hårdkodad Blade-vy

## Alla 38 mallnycklar

### Membership
| Nyckel | Beskrivning |
|---|---|
| `membership_invoice` | Faktura vid ny anmälan |
| `payment_reminder` | Betalningspåminnelse |
| `membership_renewal` | Förnyelsepåminnelse |
| `membership_applied` | Bekräftelse vid ansökan |
| `membership_applied_admin` | Adminavisering om ny ansökan |
| `membership_activated` | Bekräftelse vid aktivering |
| `membership_payment_failed` | Kort/Swish-betalning nekad eller avbruten |

### Events
| Nyckel | Beskrivning |
|---|---|
| `event_registration_confirmed` | Bekräftad anmälan |
| `event_registration_pending_payment` | Anmälan väntar betalning |
| `event_registration_pending_approval` | Anmälan väntar godkännande |
| `event_registration_waitlisted` | Köplats |
| `event_registration_cancelled` | Avbokad anmälan |
| `event_invitation` | Inbjudan från arrangör |
| `event_message` | Meddelande från arrangör till anmälda/inbjudna |

### Lokalbokningar
| Nyckel | Beskrivning |
|---|---|
| `booking_confirmed` | Bokning bekräftad |
| `booking_pending_approval` | Bokning väntar godkännande |
| `booking_reminder` | Bokningspåminnelse |
| `booking_status_confirmed` | Statusändring — godkänd |
| `booking_status_cancelled` | Statusändring — avbokad |
| `booking_status_rejected` | Statusändring — avslagen |

### Lokalansökningar
| Nyckel | Beskrivning |
|---|---|
| `venue_application_received` | Ansökan mottagen |
| `venue_application_approved` | Ansökan godkänd |
| `venue_application_rejected` | Ansökan avslagen |

### Uthyrning
| Nyckel | Beskrivning |
|---|---|
| `rental_assigned` | Hyrd tillgång tilldelad |
| `rental_terminated` | Hyra avslutad |

### GDPR
| Nyckel | Beskrivning |
|---|---|
| `account_deletion_received` | Raderingsförfrågan mottagen |
| `account_deletion_approved` | Konto raderat |
| `account_deletion_rejected` | Radering avslagen |

### Shop
| Nyckel | Beskrivning |
|---|---|
| `shop_order_confirmed` | Orderbekräftelse till kund |
| `shop_order_admin` | Notis till admin om ny order |
| `order_shipped` | Beställning skickad |
| `order_ready_for_pickup` | Beställning redo för upphämtning |

### Bokföring
| Nyckel | Beskrivning |
|---|---|
| `ar_invoice` | Kundfaktura (PDF-bilaga) |
| `missing_receipt` | Påminnelse om saknat kvitto |
| `invoice_matched` | Faktura matchad mot betalning |

### Enkäter
| Nyckel | Beskrivning |
|---|---|
| `survey_invitation` | Inbjudan att svara på enkät |

### Kontakt
| Nyckel | Beskrivning |
|---|---|
| `contact_confirmation` | Bekräftelse till avsändaren av kontaktformulär |

### Inloggning
| Nyckel | Beskrivning |
|---|---|
| `magic_link` | Lösenordsfri inloggningslänk |

## Reply-To

`Mail::alwaysReplyTo()` fungerar inte på webbhotellet: Symfony Mailer serialiserar headern fel där. I stället sätter en lyssnare på `MessageSending` i `app/Providers/AppServiceProvider.php` headern direkt, med `config('mail.reply_to.address')` och `config('mail.reply_to.name')`. Den gör det bara om mailet inte redan har en egen Reply-To.

## Specialfall

- **`InvoiceMatchedMail`** — behåller egen Blade-vy (`invoice-matched.blade.php`) för komplex tvåsektions-layout. Mallnyckel registrerad men rendering sker i Blade.
- **`ArInvoiceMail`** — migrerad till template-rendered men har också PDF-bilaga via `attachments()`.
- **`AccountDeletionApproved`** — konstruktorn tar `string $firstName` (inte Member-modellen) eftersom medlemmen anonymiseras innan mailet skickas.
- **Multi-status-mail** (`BookingStatusChanged`, `VenueApplicationDecision`) — väljer mallnyckel via `templateKey()` baserat på modellstatus.

## Varför `firstOrCreate` i seedern

Admin-ändringar via `/admin/mail-mallar` bevaras vid `php artisan db:seed`. Bara nya installationer får standardinnehållet. "Återställ till standard"-knappen i admin-UI anropar seedern inline för just den mallen.
