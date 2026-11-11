<?php

namespace App\Models\Content;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mailing extends Model
{
    use HasFactory;
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING   = 'sending';
    public const STATUS_SENT      = 'sent';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'mailing_template_id',
        'title',
        'category',
        'subject',
        'body_html',
        'status',
        'created_by',
        'scheduled_at',
        'sent_at',
        'recipients_count',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at'    => 'datetime',
            'sent_at'         => 'datetime',
            'recipients_count'=> 'integer',
        ];
    }

    public function isDraft(): bool     { return $this->status === self::STATUS_DRAFT; }
    public function isScheduled(): bool { return $this->status === self::STATUS_SCHEDULED; }
    public function isSending(): bool   { return $this->status === self::STATUS_SENDING; }
    public function isSent(): bool      { return $this->status === self::STATUS_SENT; }
    public function isCancelled(): bool { return $this->status === self::STATUS_CANCELLED; }
    public function isEditable(): bool  { return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SCHEDULED]); }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MailingTemplate::class, 'mailing_template_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MailingRecipient::class);
    }
}
