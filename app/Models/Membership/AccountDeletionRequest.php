<?php

namespace App\Models\Membership;

use App\Models\Membership\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountDeletionRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'member_id',
        'user_id',
        'reason',
        'status',
        'admin_note',
        'actioned_by',
        'actioned_at',
    ];

    protected function casts(): array
    {
        return [
            'actioned_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Approve the deletion request and anonymize the member's personal data.
     * Must update the request BEFORE calling anonymize() because anonymize()
     * soft-deletes the User, which would cascade-nullify the user_id FK here.
     */
    public function approve(User $actionedBy): void
    {
        $this->update([
            'status'      => self::STATUS_APPROVED,
            'actioned_by' => $actionedBy->id,
            'actioned_at' => now(),
        ]);

        $this->member->anonymize();
    }

    public function reject(User $actionedBy, string $adminNote): void
    {
        $this->update([
            'status'      => self::STATUS_REJECTED,
            'admin_note'  => $adminNote,
            'actioned_by' => $actionedBy->id,
            'actioned_at' => now(),
        ]);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }
}
