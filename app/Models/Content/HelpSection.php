<?php

namespace App\Models\Content;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class HelpSection extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'group_label',
        'content',
        'show_public',
        'show_auth',
        'required_permission',
        'sort_order',
    ];

    protected $casts = [
        'show_public' => 'boolean',
        'show_auth'   => 'boolean',
        'sort_order'  => 'integer',
    ];

    /**
     * All help groups, ordered for display.
     * Each entry: slug, label (matches group_label in DB), description, icon, required_permission|null
     */
    public static function groups(): Collection
    {
        return collect([
            [
                'slug'        => 'allmant',
                'label'       => '',
                'title'       => 'Allmänt',
                'description' => 'Kom igång, systemkrav, dataskydd och hur du kontaktar föreningen.',
                'icon'        => 'info-circle',
                'permission'  => null,
            ],
            [
                'slug'        => 'konto',
                'label'       => 'Konto & inloggning',
                'title'       => 'Konto & inloggning',
                'description' => 'Profil, Google-konto, lösenord, RFID-kort och GDPR.',
                'icon'        => 'person-circle',
                'permission'  => null,
            ],
            [
                'slug'        => 'evenemang',
                'label'       => 'Evenemang',
                'title'       => 'Evenemang',
                'description' => 'Anmälan, biljetter, QR-kod, tillägg och avbokning.',
                'icon'        => 'calendar-event',
                'permission'  => null,
            ],
            [
                'slug'        => 'webshop',
                'label'       => 'Webshop',
                'title'       => 'Webshop',
                'description' => 'Beställningar, betalning, rabattkoder och orderhistorik.',
                'icon'        => 'bag',
                'permission'  => null,
            ],
            [
                'slug'        => 'uthyrning',
                'label'       => 'Uthyrning',
                'title'       => 'Uthyrning',
                'description' => 'Hyra utrustning, intresselistor och uthyrningsregler.',
                'icon'        => 'box-seam',
                'permission'  => null,
            ],
            [
                'slug'        => 'lokalbokningar',
                'label'       => 'Lokalbokningar',
                'title'       => 'Lokalbokningar',
                'description' => 'Boka lokal, regler, iCal och godkännandeflöden.',
                'icon'        => 'door-open',
                'permission'  => null,
            ],
            [
                'slug'        => 'enkater-formular',
                'label'       => 'Enkäter & formulär',
                'title'       => 'Enkäter & formulär',
                'description' => 'Svara på enkäter, fylla i formulär och ändra svar.',
                'icon'        => 'clipboard-check',
                'permission'  => null,
            ],
            [
                'slug'        => 'utlagg',
                'label'       => 'Utlägg',
                'title'       => 'Utlägg',
                'description' => 'Skicka in kvitton, följa upp utlägg och kontonummer.',
                'icon'        => 'receipt',
                'permission'  => null,
            ],
            [
                'slug'        => 'org-roller',
                'label'       => 'Org-roller & möten',
                'title'       => 'Org-roller & möten',
                'description' => 'Dina uppdrag, mötesprotokoll och omröstningar.',
                'icon'        => 'people',
                'permission'  => null,
            ],
            [
                'slug'        => 'admin-medlemmar',
                'label'       => 'Administratör — Medlemmar',
                'title'       => 'Admin: Medlemmar',
                'description' => 'Hantera medlemmar, sektioner, aktivering och GDPR.',
                'icon'        => 'person-badge',
                'permission'  => 'members.view',
            ],
            [
                'slug'        => 'admin-ekonomi',
                'label'       => 'Administratör — Ekonomi',
                'title'       => 'Admin: Ekonomi',
                'description' => 'Bokföring, fakturering, betalningar och bokslut.',
                'icon'        => 'cash-coin',
                'permission'  => 'accounting.view',
            ],
            [
                'slug'        => 'admin-evenemang',
                'label'       => 'Administratör — Evenemang',
                'title'       => 'Admin: Evenemang',
                'description' => 'Skapa och administrera evenemang, biljetter och settlement.',
                'icon'        => 'calendar2-plus',
                'permission'  => 'events.manage',
            ],
            [
                'slug'        => 'admin-kommunikation',
                'label'       => 'Administratör — Kommunikation',
                'title'       => 'Admin: Kommunikation',
                'description' => 'Utskick, enkäter, kontaktmeddelanden och mailmallar.',
                'icon'        => 'envelope-at',
                'permission'  => 'mailings.manage',
            ],
            [
                'slug'        => 'admin-system',
                'label'       => 'Administratör — System',
                'title'       => 'Admin: System',
                'description' => 'Inställningar, mailloggar, API-dokumentation och deployment.',
                'icon'        => 'gear',
                'permission'  => 'system.settings',
            ],
        ]);
    }

    public static function groupBySlug(string $slug): ?array
    {
        return static::groups()->firstWhere('slug', $slug);
    }
}
