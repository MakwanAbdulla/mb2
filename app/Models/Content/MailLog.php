<?php

namespace App\Models\Content;

use App\Models\Membership\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailLog extends Model
{
    protected $table = 'mail_log';

    public const STATUS_SENT   = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'mail_type',
        'mailable_class',
        'recipient_email',
        'recipient_name',
        'subject',
        'body_html',
        'member_id',
        'user_id',
        'mailing_id',
        'sent_at',
        'tracking_token',
        'first_opened_at',
        'open_count',
        'delivery_status',
        'delivery_error',
    ];

    protected function casts(): array
    {
        return [
            'sent_at'        => 'datetime',
            'first_opened_at' => 'datetime',
            'open_count'     => 'integer',
        ];
    }

    private const TYPE_LABELS = [
        'membership_invoice'       => 'Faktura',
        'payment_reminder'         => 'Betalningspåminnelse',
        'membership_renewal'       => 'Förnyelse',
        'membership_activated'     => 'Medlemskap aktiverat',
        'membership_applied'       => 'Ansökan mottagen',
        'event_registration'       => 'Eventanmälan',
        'booking_confirmed'        => 'Bokning bekräftad',
        'booking_reminder'         => 'Bokningspåminnelse',
        'booking_status_changed'   => 'Bokningsstatus',
        'rental_assigned'          => 'Hyra tilldelad',
        'rental_terminated'        => 'Hyra avslutad',
        'venue_application_decision' => 'Lokalansökan beslut',
        'venue_application_received' => 'Lokalansökan mottagen',
        'ar_invoice'               => 'Kundfaktura',
        'invoice_matched'          => 'Faktura matchad',
        'missing_receipt'          => 'Kvitto saknas',
        'shop_order_pickup'        => 'Redo för upphämtning',
        'shop_order_shipped'       => 'Beställning skickad',
        'shop_order_confirmation'  => 'Orderbekräftelse',
        'shop_order_admin'         => 'Ny beställning (admin)',
        'survey_invitation'        => 'Enkätinbjudan',
        'account_deletion_approved'=> 'Kontoborttagning godkänd',
        'account_deletion_received'=> 'Kontoborttagning mottagen',
        'account_deletion_rejected'=> 'Kontoborttagning nekad',
        'contact_confirmation'     => 'Kontaktformulär',
        'mailing'                  => 'Utskick',
    ];

    public function typeLabel(): string
    {
        if (! $this->mail_type) {
            return '—';
        }
        return self::TYPE_LABELS[$this->mail_type] ?? $this->mail_type;
    }

    public function wasOpened(): bool
    {
        return $this->open_count > 0;
    }

    public function isFailed(): bool
    {
        return $this->delivery_status === self::STATUS_FAILED;
    }

    public function recordOpen(): void
    {
        $this->increment('open_count');
        if (! $this->first_opened_at) {
            $this->update(['first_opened_at' => now()]);
        }
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mailing(): BelongsTo
    {
        return $this->belongsTo(Mailing::class);
    }
}
