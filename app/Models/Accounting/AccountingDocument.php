<?php

namespace App\Models\Accounting;

use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\FiscalYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A file attached to accounting records.
 *
 * An AccountingDocument may be linked to an Invoice, a JournalEntry, both,
 * or neither (unlinked pre-posting receipt). Linking is progressive:
 *
 *   Upload → (invoice_id set, journal_entry_id null)
 *   Invoice posted → journal_entry_id set, linked_at recorded
 *
 * Disk paths use UUIDs to guarantee no collisions:
 *   accounting/documents/{year}/{uuid}.{ext}
 *
 * @property int         $id
 * @property string      $type
 * @property int|null    $invoice_id
 * @property int|null    $journal_entry_id
 * @property int|null    $fiscal_year_id
 * @property string      $disk
 * @property string      $path
 * @property string      $original_name
 * @property string      $mime_type
 * @property int         $size_bytes
 * @property string|null $description
 * @property int         $uploaded_by
 * @property \Illuminate\Support\Carbon|null $linked_at
 */
class AccountingDocument extends Model
{
    public const TYPE_INVOICE_ATTACHMENT = 'invoice_attachment';
    public const TYPE_RECEIPT            = 'receipt';
    public const TYPE_CONTRACT           = 'contract';
    public const TYPE_OTHER              = 'other';

    protected $fillable = [
        'type',
        'invoice_id',
        'journal_entry_id',
        'fiscal_year_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'description',
        'uploaded_by',
        'linked_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'linked_at'  => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ── Path factory ──────────────────────────────────────────────────────────

    /**
     * Build a collision-free disk path for a new document and ensure the directory exists.
     * Format: accounting/documents/{year}/{uuid}.{ext}
     */
    public static function buildPath(string $extension, int $year = 0): string
    {
        if ($year === 0) {
            $year = now()->year;
        }

        $path   = 'accounting/documents/' . $year . '/' . Str::uuid()->toString() . '.' . strtolower($extension);
        Storage::makeDirectory(dirname($path));

        return $path;
    }

    // ── Status helpers ────────────────────────────────────────────────────────

    public function isLinked(): bool
    {
        return $this->journal_entry_id !== null;
    }

    public function isUnlinked(): bool
    {
        return $this->journal_entry_id === null;
    }

    // ── File helpers ──────────────────────────────────────────────────────────

    public function fileExists(): bool
    {
        $disk = (string) ($this->attributes['disk'] ?? 'local');
        $path = (string) ($this->attributes['path'] ?? '');
        return Storage::disk($disk)->exists($path);
    }

    /**
     * Delete the file from disk and the database record.
     */
    public function delete(): bool
    {
        $disk = (string) ($this->attributes['disk'] ?? 'local');
        $path = (string) ($this->attributes['path'] ?? '');
        Storage::disk($disk)->delete($path);
        return parent::delete();
    }

    public function sizeForHumans(): string
    {
        $bytes = (int) ($this->attributes['size_bytes'] ?? 0);
        if ($bytes < 1024)    return "{$bytes} B";
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }

    public function typeLabel(): string
    {
        return match ((string) ($this->attributes['type'] ?? '')) {
            self::TYPE_INVOICE_ATTACHMENT => 'Fakturakopia',
            self::TYPE_RECEIPT            => 'Kvitto',
            self::TYPE_CONTRACT           => 'Avtal',
            default                       => 'Övrigt',
        };
    }

    public function isPdf(): bool
    {
        return str_contains((string) ($this->attributes['mime_type'] ?? ''), 'pdf');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) ($this->attributes['mime_type'] ?? ''), 'image/');
    }
}
