<?php

namespace App\Models;

use App\Models\Membership\Member;
use App\Models\Booking\VenueApplication;
use App\Models\Booking\Booking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;

/**
 * @property int         $id
 * @property int|null    $user_id
 * @property string      $action
 * @property string      $area
 * @property string      $subject
 * @property string|null $auditable_type
 * @property int|null    $auditable_id
 * @property array|null  $changes
 * @property string|null $ip_address
 */
class AuditLog extends Model
{
    protected $fillable = [
        'user_id', 'action', 'area', 'subject',
        'auditable_type', 'auditable_id', 'changes', 'ip_address',
    ];

    protected $casts = ['changes' => 'array'];

    public const AREA_LABELS = [
        'cms'                  => 'CMS',
        'auth'                 => 'Inloggning',
        'bookings'             => 'Bokningar',
        'recurring_bookings'   => 'Återkommande bokningar',
        'venue_applications'   => 'Lokalansökningar',
        'resources'            => 'Rum',
        'activities'           => 'Aktiviteter',
        'notices'              => 'Notiser',
        'settings'             => 'Inställningar',
        'users'                => 'Användare',
        'ical'                 => 'iCal',
        'members'              => 'Medlemmar',
        'membership_period'    => 'Medlemsperiod',
        'discounts'            => 'Rabatter',
        'help_sections'        => 'Hjälpsektioner',
        'rentals'              => 'Hyror',
        'events'               => 'Evenemang',
        'payments'             => 'Betalningar',
        'meetings'             => 'Möten',
        'meeting_templates'    => 'Mötesmallar',
        'meeting_tasks'        => 'Mötesuppgifter',
        'meeting_submissions'  => 'Motioner',
        'pages'                => 'Sidor',
        'tags'                 => 'Taggar',
        'booking_categories'   => 'Bokningskategorier',
        'workspace'            => 'Google Workspace',
        'wiki'                 => 'Wiki',
    ];

    public const ACTION_LABELS = [
        'widget_error'                    => 'Widget-fel',
        'login_success'                   => 'Inloggning lyckades',
        'login_failed'                    => 'Inloggning misslyckades',
        'ical_accessed'                   => 'iCal hämtad',
        'booking_created'                 => 'Bokning skapad',
        'booking_updated'                 => 'Bokning uppdaterad',
        'booking_cancelled'               => 'Bokning avbokad',
        'booking_approved'                => 'Bokning godkänd',
        'booking_rejected'                => 'Bokning nekad',
        'booking_deleted'                 => 'Bokning borttagen',
        'booking_bulk_approved'           => 'Bokningar godkända (bulk)',
        'booking_bulk_rejected'           => 'Bokningar nekade (bulk)',
        'booking_bulk_cancelled'          => 'Bokningar avbokade (bulk)',
        'booking_category_created'        => 'Bokningskategori skapad',
        'booking_category_updated'        => 'Bokningskategori uppdaterad',
        'booking_category_deleted'        => 'Bokningskategori borttagen',
        'recurring_booking_created'       => 'Återkommande bokning skapad',
        'recurring_booking_updated'       => 'Återkommande bokning uppdaterad',
        'recurring_booking_deleted'       => 'Återkommande bokning borttagen',
        'venue_application_submitted'     => 'Ansökan inskickad',
        'venue_application_approved'      => 'Ansökan godkänd',
        'venue_application_rejected'      => 'Ansökan nekad',
        'venue_application_cancelled'     => 'Ansökan avbruten',
        'resource_created'                => 'Rum skapat',
        'resource_updated'                => 'Rum uppdaterat',
        'resource_deleted'                => 'Rum borttaget',
        'activity_created'                => 'Aktivitet skapad',
        'activity_updated'                => 'Aktivitet uppdaterad',
        'activity_deleted'                => 'Aktivitet borttagen',
        'notice_created'                  => 'Notis skapad',
        'notice_updated'                  => 'Notis uppdaterad',
        'notice_deleted'                  => 'Notis borttagen',
        'settings_saved'                  => 'Inställningar sparade',
        'user_updated'                    => 'Användare uppdaterad',
        'user_roles_synced'               => 'Roller tilldelade',
        'membership_deactivated'          => 'Medlemskap avaktiverat',
        'membership_period_activated'     => 'Medlemsperiod aktiverad',
        'membership_payment_rejected'     => 'Betalning avslagen',
        'membership_payment_failed'       => 'Betalning misslyckades',
        'membership_reactivated'          => 'Medlemskap återaktiverat',
        'discount_redeemed'               => 'Rabattkod inlöst',
        'gdpr_request_rejected'           => 'GDPR-begäran nekad',
        'help_section_created'            => 'Hjälpsektion skapad',
        'help_section_updated'            => 'Hjälpsektion uppdaterad',
        'help_section_deleted'            => 'Hjälpsektion borttagen',
        'rental_assigned'                 => 'Hyra tilldelad',
        'rental_terminated'               => 'Hyra avslutad',
        'rental_invoice_paid'             => 'Hyresfaktura betald',
        'rental_type_created'             => 'Hyrestyp skapad',
        'rental_type_updated'             => 'Hyrestyp uppdaterad',
        'rental_item_added'               => 'Hyresobjekt tillagt',
        'rental_item_updated'             => 'Hyresobjekt uppdaterat',
        'rental_item_deleted'             => 'Hyresobjekt borttaget',
        'event_registration_approved'     => 'Anmälan godkänd',
        'event_registration_rejected'     => 'Anmälan nekad',
        'event_checked_in'                => 'Incheckad på evenemang',
        'member_created'                  => 'Medlem skapad',
        'member_updated'                  => 'Medlem uppdaterad',
        'member_deleted'                  => 'Medlem borttagen',
        'payment_recorded'                => 'Betalning registrerad',
        'meeting_created'                 => 'Möte skapat',
        'meeting_updated'                 => 'Möte uppdaterat',
        'meeting_status_changed'          => 'Mötesstatus ändrad',
        'meeting_minutes_saved'           => 'Mötesprotokoll sparat',
        'meeting_template_saved'          => 'Mötesinstruktion sparad',
        'meeting_template_deleted'        => 'Mötesinstruktion borttagen',
        'meeting_task_status_changed'     => 'Mötesuppgift statusändrad',
        'meeting_submission_status_changed' => 'Motion statusändrad',
        'meeting_submission_added_to_agenda' => 'Motion lagd på dagordning',
        'item_redeemed'                   => 'Artikel inlöst',
        'page_updated'                    => 'Sida uppdaterad',
        'page_published'                  => 'Sida publicerad',
        'page_draft_discarded'            => 'Utkast kasserat',
        'tag_created'                          => 'Tagg skapad',
        'tag_renamed'                          => 'Tagg omdöpt',
        'tag_merged'                           => 'Taggar sammanslagna',
        'tag_deleted'                          => 'Tagg borttagen',
        'workspace_account_provisioned'        => 'Workspace-konto provisionerat',
        'workspace_account_created'            => 'Workspace-konto skapat',
        'workspace_account_updated'            => 'Workspace-konto uppdaterat',
        'workspace_account_linked'             => 'Workspace-konto kopplat',
        'workspace_account_deletion_scheduled' => 'Workspace-konto: radering planerad',
        'workspace_account_deletion_cancelled' => 'Workspace-konto: radering avbruten',
        'workspace_account_purged'             => 'Workspace-konto raderat',
        'workspace_alias_added'                => 'Workspace-alias tillagt',
        'workspace_alias_removed'              => 'Workspace-alias borttaget',
        'workspace_password_reset'             => 'Workspace-lösenord återställt',
        'member_name_changed'                  => 'Namnbyte (Workspace-alias rekommenderas)',
        'wiki_space_created'                   => 'Wikiutrymme skapat',
        'wiki_space_updated'                   => 'Wikiutrymme ändrat',
        'wiki_space_archived'                  => 'Wikiutrymme arkiverat',
        'wiki_space_restored'                  => 'Wikiutrymme återställt',
        'wiki_page_created'                    => 'Wikisida skapad',
        'wiki_page_updated'                    => 'Wikisida ändrad',
        'wiki_page_deleted'                    => 'Wikisida raderad',
    ];

    /** Human-readable labels for deactivation reason codes. */
    public const DEACTIVATION_REASON_LABELS = [
        'grace_period_expired' => 'Nådeperioden har löpt ut',
        'no_membership_period' => 'Saknar aktiv period för innevarande år',
        'gdpr_erasure'         => 'Begäran om radering (GDPR artikel 17)',
        'self_requested'       => 'Självmant raderat konto',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function record(
        string $action,
        string $area,
        string $subject,
        ?Model $auditable = null,
        ?array $changes = null,
        ?int $userId = null,
    ): void {
        static::create([
            'user_id'        => $userId ?? Auth::id(),
            'action'         => $action,
            'area'           => $area,
            'subject'        => $subject,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id'   => $auditable?->getKey(),
            'changes'        => $changes,
            'ip_address'     => request()->ip(),
        ]);
    }

    public function areaLabel(): string
    {
        return self::AREA_LABELS[$this->area] ?? $this->area;
    }

    public function actionLabel(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }

    public function adminUrl(): ?string
    {
        if (! $this->auditable) {
            return null;
        }

        return match ($this->auditable_type) {
            Booking::class          => route('booking.admin.bookings.edit', $this->auditable),
            VenueApplication::class => route('booking.admin.venue-applications.show', $this->auditable),
            Member::class           => route('admin.members.show', $this->auditable),
            default                 => null,
        };
    }
}
