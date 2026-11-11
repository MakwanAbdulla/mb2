<?php

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Accounting\PaymentProvider;

/**
 * An entry in the accounting inbox sender list.
 *
 * type = 'allowed' → sender is trusted, attachments are auto-imported
 * type = 'blocked' → sender is rejected, message is skipped entirely
 * unknown sender   → attachments are imported but flagged needs_approval
 *
 * pattern examples:
 *   faktura@leverantor.se   — exact address match
 *   @fortnox.se             — all addresses from the domain
 *
 * @property int         $id
 * @property string      $type      'allowed'|'blocked'
 * @property string      $pattern
 * @property string|null $label
 * @property bool        $is_active
 * @property string|null $notes
 * @property int         $created_by
 */
class AccountingInboxSender extends Model
{
    public const TYPE_ALLOWED = 'allowed';
    public const TYPE_BLOCKED = 'blocked';

    protected $fillable = [
        'type',
        'pattern',
        'label',
        'is_active',
        'notes',
        'payment_provider_id',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function paymentProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Returns true if this pattern matches the given email address. */
    public function matches(string $email): bool
    {
        $email   = strtolower(trim($email));
        $pattern = strtolower(trim($this->pattern));

        if (str_starts_with($pattern, '@')) {
            return str_ends_with($email, $pattern);
        }

        return $email === $pattern;
    }

    /**
     * Classify a sender email against the active list.
     *
     * Returns:
     *   'allowed'  — matches an active allowed entry → auto-import
     *   'blocked'  — matches an active blocked entry → skip entirely
     *   'unknown'  — no match → import but require approval
     */
    public static function classify(string $email): string
    {
        $email   = strtolower(trim($email));
        $senders = static::active()->get();

        foreach ($senders as $sender) {
            if ($sender->matches($email)) {
                return $sender->type;
            }
        }

        return 'unknown';
    }

    public function isDomainPattern(): bool
    {
        return str_starts_with($this->pattern, '@');
    }

    public function isAllowed(): bool
    {
        return $this->type === self::TYPE_ALLOWED;
    }

    public function isBlocked(): bool
    {
        return $this->type === self::TYPE_BLOCKED;
    }
}
