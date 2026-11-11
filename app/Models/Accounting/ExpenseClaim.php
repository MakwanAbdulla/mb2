<?php

namespace App\Models\Accounting;

use App\Models\Membership\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ExpenseClaim extends Model
{
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_REJECTED  = 'rejected';

    public const CLAIM_TYPE_REIMBURSEMENT = 'reimbursement';
    public const CLAIM_TYPE_CARD_RECEIPT  = 'card_receipt';

    protected $fillable = [
        'member_id',
        'description',
        'purchased_at',
        'amount_ore',
        'cost_center_id',
        'receipt_disk',
        'receipt_path',
        'receipt_original_name',
        'receipt_mime_type',
        'receipt_size_bytes',
        'status',
        'claim_type',
        'corporate_card_id',
        'bank_transaction_id',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'journal_entry_id',
    ];

    protected $casts = [
        'purchased_at'      => 'date',
        'submitted_at'      => 'datetime',
        'reviewed_at'       => 'datetime',
        'amount_ore'        => 'integer',
        'receipt_size_bytes' => 'integer',
        'corporate_card_id'  => 'integer',
        'bank_transaction_id' => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function corporateCard(): BelongsTo
    {
        return $this->belongsTo(CorporateCard::class);
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    // ── Status helpers ────────────────────────────────────────────────────────

    public function isCardReceipt(): bool
    {
        return $this->claim_type === self::CLAIM_TYPE_CARD_RECEIPT;
    }

    public function isReimbursement(): bool
    {
        return $this->claim_type === self::CLAIM_TYPE_REIMBURSEMENT;
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_SUBMITTED => 'Inlämnat',
            self::STATUS_APPROVED  => 'Godkänt',
            self::STATUS_REJECTED  => 'Avvisat',
            default                => $this->status,
        };
    }

    // ── File helpers ──────────────────────────────────────────────────────────

    public function receiptExists(): bool
    {
        return Storage::disk($this->receipt_disk)->exists($this->receipt_path);
    }

    public function isPdf(): bool
    {
        return str_contains($this->receipt_mime_type ?? '', 'pdf');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->receipt_mime_type ?? '', 'image/');
    }

    public function sizeForHumans(): string
    {
        $bytes = $this->receipt_size_bytes;
        if ($bytes < 1024) return "{$bytes} B";
        if ($bytes < 1_048_576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1_048_576, 1) . ' MB';
    }

    /** Storage path for a new receipt upload. */
    public static function buildReceiptPath(string $extension): string
    {
        $year = now()->year;
        $uuid = \Illuminate\Support\Str::uuid()->toString();
        $path = "expense-claims/{$year}/{$uuid}." . strtolower($extension);

        Storage::makeDirectory(dirname($path));

        return $path;
    }
}
