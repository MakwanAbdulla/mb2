<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per received email message.
 *
 * @property int                             $id
 * @property string|null                     $message_id        Email Message-ID header
 * @property string                          $from_email
 * @property string|null                     $from_name
 * @property string|null                     $subject
 * @property string|null                     $body_text
 * @property string|null                     $body_html
 * @property \Illuminate\Support\Carbon|null $received_at
 * @property string                          $sender_classification  allowed|blocked|unknown
 * @property bool                            $has_attachments
 */
class AccountingInboxEmail extends Model
{
    public const CLASSIFICATION_ALLOWED = 'allowed';
    public const CLASSIFICATION_BLOCKED = 'blocked';
    public const CLASSIFICATION_UNKNOWN = 'unknown';

    protected $fillable = [
        'message_id',
        'from_email',
        'from_name',
        'original_from_email',
        'subject',
        'body_text',
        'body_html',
        'received_at',
        'sender_classification',
        'has_attachments',
    ];

    protected $casts = [
        'received_at'     => 'datetime',
        'has_attachments' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(AccountingInboxItem::class);
    }

    public function senderLabel(): string
    {
        if ($this->from_name && $this->from_name !== $this->from_email) {
            return $this->from_name . ' <' . $this->from_email . '>';
        }

        return $this->from_email;
    }

    public function isAllowed(): bool
    {
        return $this->sender_classification === self::CLASSIFICATION_ALLOWED;
    }

    public function isBlocked(): bool
    {
        return $this->sender_classification === self::CLASSIFICATION_BLOCKED;
    }

    public function isUnknown(): bool
    {
        return $this->sender_classification === self::CLASSIFICATION_UNKNOWN;
    }
}
