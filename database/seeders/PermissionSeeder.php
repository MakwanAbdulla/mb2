<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The source of truth for permissions and the system roles.
 *
 * run() makes the roles match ROLES exactly (dev and tests). Deploys use
 * `php artisan permissions:add-missing` instead, which only adds what is
 * missing, so role changes made in admin or via the API are kept.
 */
class PermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        // Members
        'members.view'              => 'Visa medlemslista',
        'members.edit'              => 'Redigera medlemmar',
        'members.manage'            => 'Skapa / ta bort / exportera',
        'membership-periods.manage' => 'Aktivera medlemsperioder',
        // Payments
        'payments.view'    => 'Visa betalningshistorik',
        'payments.confirm' => 'Bekräfta betalningar manuellt',
        'payments.import'  => 'Importera bankgiro / Swish-filer',
        // Bookings
        'bookings.manage'       => 'Hantera alla bokningar',
        'bookings.categories'   => 'Kategorier och resurser',
        'bookings.applications' => 'Lokalansökningar',
        'bookings.statistics'   => 'Bokningsstatistik',
        'bookings.super'        => 'Boka åt andra / förbikoppla tidsfönster',
        // CMS
        'cms.edit'    => 'Redigera webbsidor',
        'cms.publish' => 'Publicera sidor',
        'help.manage' => 'Hantera hjälpsektioner',
        // Content
        'notices.manage' => 'Anslagstavla',
        // Events
        'events.view'       => 'Visa eventlista i admin',
        'events.create'     => 'Skapa evenemang',
        'events.manage-own' => 'Administrera egna evenemang',
        'events.manage-all' => 'Administrera alla evenemang',
        'events.check-in'   => 'Checka in deltagare',
        'events.economy'    => 'Extern arrangörsekonomi',
        // Rentals
        'rentals.manage' => 'Tilldela / avsluta hyror och väntelista',
        // RFID tags
        'tags.manage' => 'Tilldela / avaktivera RFID-taggar',
        // Shop
        'shop.manage' => 'Produkter, lager och orderhantering',
        // Accounting
        'accounting.view'    => 'Läsa verifikationer, kontoplan och rapporter',
        'accounting.manage'  => 'Bokföra, hantera leverantörsfakturor och kassa',
        'accounting.admin'   => 'Räkenskapsår, periodlåsning, SIE-export och budget',
        'accounting.approve' => 'Attestera fakturor',
        // Forms
        'forms.manage' => 'Skapa / redigera formulär och se inlämnade svar',
        // Surveys
        'surveys.manage' => 'Skapa / redigera / stänga enkäter och se svar',
        // Mailings
        'mailings.manage' => 'Skapa / redigera / skicka utskick och mallar',
        // Meetings
        'meetings.view'   => 'Se möten, dagordningar och protokoll',
        'meetings.manage' => 'Skapa / redigera möten och skriva protokoll',
        // E-signing (DocSign)
        'signing.create' => 'Skicka egna dokument för BankID-signering och se sina egna ärenden',
        'signing.view'   => 'Se alla signeringsärenden och saldo hos DocSign',
        'signing.manage' => 'Hantera alla signeringsärenden, signeringslänkar och inställningar',
        // Tasks
        'tasks.view'   => 'Se uppgifter och kommentera',
        'tasks.manage' => 'Skapa / redigera / tilldela uppgifter',
        // Submissions
        'submissions.view'   => 'Se inkomna ärenden och motioner',
        'submissions.manage' => 'Hantera ärenden, skriva yttranden och lägga på dagordning',
        // Wiki — reading follows each space's read_access (App\Support\WikiAccess), no permission needed.
        // wiki.view sees every space regardless, like signing.view; writing follows the owning group.
        'wiki.view'   => 'Se alla wikiutrymmen',
        'wiki.edit'   => 'Redigera föreningsgemensamma wikiutrymmen',
        'wiki.manage' => 'Skapa / hantera alla wikiutrymmen och sidor',
        // Statistics
        'statistics.view' => 'Se statistiksidor',
        // Gamification
        'gamification.manage' => 'Hantera gamification och XP-logg',
        // Tournaments
        'tournaments.view'   => 'Se turneringar och standings',
        'tournaments.manage' => 'Skapa och hantera turneringar',
        // Org structure
        'sections.manage'  => 'Hantera sektioner och grupper',
        'org-roles.manage' => 'Hantera organisationsroller',
        // Google Workspace
        'workspace.manage' => 'Hantera Google Workspace-konton',
        // System
        'settings.manage'    => 'Systeminställningar',
        'users.manage-roles' => 'Tilldela roller till användare',
        'audit.view'         => 'Granskningslogg',
        'api.access'         => 'Använda REST API (Bearer-token)',
    ];

    /**
     * System roles: name => [label, permissions]. '*' means every permission.
     */
    public const ROLES = [
        // admin — allt
        'admin' => ['label' => 'Administratör', 'permissions' => '*'],

        // rental-manager — skåp + väntelista
        'rental-manager' => ['label' => 'Uthyrningsansvarig', 'permissions' => [
            'rentals.manage',
            'members.view',
        ]],

        // tag-manager — RFID-taggar
        'tag-manager' => ['label' => 'RFID-taggansvarig', 'permissions' => [
            'tags.manage',
            'members.view',
        ]],

        // kassör — betalningar + bokföring
        'treasurer' => ['label' => 'Kassör', 'permissions' => [
            'members.view',
            'payments.view',
            'payments.confirm',
            'payments.import',
            'accounting.view',
            'accounting.manage',
            'accounting.admin',
            'accounting.approve',
            'signing.view',
            'signing.manage',
        ]],

        // member-manager — hela medlemsregistret + betalningar
        'member-manager' => ['label' => 'Medlemsansvarig', 'permissions' => [
            'members.view',
            'members.edit',
            'members.manage',
            'membership-periods.manage',
            'payments.view',
            'payments.confirm',
            'payments.import',
            'statistics.view',
        ]],

        // booking-admin — hela bokningsmodulen
        'booking-admin' => ['label' => 'Bokningsadministratör', 'permissions' => [
            'bookings.manage',
            'bookings.categories',
            'bookings.applications',
            'bookings.statistics',
            'bookings.super',
            'notices.manage',
        ]],

        // website-editor — CMS + anslagstavla + hjälpsektioner
        'website-editor' => ['label' => 'Webbredaktör', 'permissions' => [
            'cms.edit',
            'cms.publish',
            'notices.manage',
            'help.manage',
        ]],

        // event-organizer — Arrangörer: skapa + hantera egna event + incheckning
        'event-organizer' => ['label' => 'Eventarrangör', 'permissions' => [
            'events.view',
            'events.create',
            'events.manage-own',
            'events.check-in',
        ]],

        // shop-manager — Butiksansvariga: produkter, lager, fulfillment
        'shop-manager' => ['label' => 'Butiksansvarig', 'permissions' => [
            'shop.manage',
        ]],

        // styrelse — styrelseledamöter: läsa bokföring + se medlemmar + betalningar + möten
        // Tilldelas automatiskt när en användare läggs in i organisationsrollen "styrelsen"
        'styrelse' => ['label' => 'Styrelse', 'permissions' => [
            'members.view',
            'payments.view',
            'events.view',
            'accounting.view',
            'statistics.view',
            'gamification.manage',
            'tournaments.view',
            'tournaments.manage',
            'meetings.view',
            'meetings.manage',
            'tasks.view',
            'tasks.manage',
            'submissions.view',
            'submissions.manage',
            'signing.view',
            'signing.manage',
            'wiki.view',
            'wiki.edit',
        ]],

        // sekreterare — skriver protokoll och hanterar möten
        'sekreterare' => ['label' => 'Sekreterare', 'permissions' => [
            'members.view',
            'meetings.view',
            'meetings.manage',
            'tasks.view',
            'tasks.manage',
            'submissions.view',
            'submissions.manage',
            'signing.view',
            'signing.manage',
            'wiki.view',
            'wiki.edit',
        ]],

        // revisor — revisorer: läsa bokföring + granskningslogg
        // Tilldelas automatiskt när en användare läggs in i organisationsrollen "revisorer"
        'revisor' => ['label' => 'Revisor', 'permissions' => [
            'members.view',
            'payments.view',
            'accounting.view',
            'audit.view',
        ]],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name => $label) {
            self::permission($name, $label);
        }

        foreach (self::ROLES as $name => $definition) {
            self::role($name, $definition['label'])->syncPermissions(self::permissionsFor($name));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * The permission names a system role should have according to ROLES.
     *
     * @return list<string>
     */
    public static function permissionsFor(string $role): array
    {
        $permissions = self::ROLES[$role]['permissions'];

        return $permissions === '*' ? array_keys(self::PERMISSIONS) : $permissions;
    }

    /**
     * Find or create a permission and give it a label if it has none.
     */
    public static function permission(string $name, string $label): Permission
    {
        $permission = Permission::firstOrCreate(['name' => $name]);

        if (! $permission->label) {
            $permission->forceFill(['label' => $label])->save();
        }

        return $permission;
    }

    /**
     * Find or create a role and give it a label if it has none.
     */
    public static function role(string $name, string $label): Role
    {
        $role = Role::firstOrCreate(['name' => $name]);

        if (! $role->label) {
            $role->forceFill(['label' => $label])->save();
        }

        return $role;
    }
}
