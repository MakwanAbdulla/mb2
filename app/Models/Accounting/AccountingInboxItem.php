<?php

namespace App\Models\Accounting;

use App\Models\Accounting\AccountingDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int                             $id
 * @property int|null                        $accounting_inbox_email_id
 * @property string                          $from_email
 * @property string|null                     $from_name
 * @property string|null                     $subject
 * @property \Illuminate\Support\Carbon|null $received_at
 * @property string|null                     $email_message_id
 * @property string                          $filename
 * @property string                          $path
 * @property string|null                     $mime_type
 * @property int                             $size_bytes
 * @property string                          $content_hash
 * @property string                          $status
 * @property string|null                     $notes
 * @property int|null                        $accounting_document_id
 */
class AccountingInboxItem extends Model
{
    public const STATUS_NEEDS_APPROVAL = 'needs_approval';
    public const STATUS_PENDING        = 'pending';
    public const STATUS_LINKED         = 'linked';
    public const STATUS_IGNORED        = 'ignored';

    protected $fillable = [
        'accounting_inbox_email_id',
        'from_email',
        'from_name',
        'original_from_email',
        'subject',
        'received_at',
        'email_message_id',
        'filename',
        'path',
        'mime_type',
        'size_bytes',
        'content_hash',
        'status',
        'notes',
        'accounting_document_id',
        'payment_provider_id',
        'extracted_doc_type',
        'extracted_doc_number',
        'extracted_period_start',
        'extracted_period_end',
        'extracted_net_amount_ore',
        'extracted_gross_amount_ore',
        'extracted_fee_amount_ore',
        'extracted_text',
    ];

    protected $casts = [
        'received_at'              => 'datetime',
        'size_bytes'               => 'integer',
        'extracted_period_start'   => 'date',
        'extracted_period_end'     => 'date',
        'extracted_net_amount_ore' => 'integer',
        'extracted_gross_amount_ore' => 'integer',
        'extracted_fee_amount_ore'   => 'integer',
    ];

    public function email(): BelongsTo
    {
        return $this->belongsTo(AccountingInboxEmail::class, 'accounting_inbox_email_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(AccountingDocument::class, 'accounting_document_id');
    }

    public function paymentProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPdf(): bool
    {
        return str_contains((string) ($this->mime_type ?? ''), 'pdf');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) ($this->mime_type ?? ''), 'image/');
    }

    public function fileExists(): bool
    {
        return Storage::exists($this->path);
    }

    public function sizeForHumans(): string
    {
        $bytes = (int) $this->size_bytes;
        if ($bytes < 1024)    return "{$bytes} B";
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }

    /**
     * Format an öre amount as a human-readable SEK string.
     * e.g. 858479 → "8 584,79 kr"
     */
    public function formatOre(?int $ore): ?string
    {
        if ($ore === null) {
            return null;
        }
        return number_format($ore / 100, 2, ',', ' ') . ' kr';
    }

    public function senderLabel(): string
    {
        if ($this->from_name && $this->from_name !== $this->from_email) {
            return $this->from_name . ' <' . $this->from_email . '>';
        }
        return $this->from_email;
    }
}
