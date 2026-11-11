<?php

namespace App\Models\Content;

use App\Models\Membership\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailingUnsubscribe extends Model
{
    protected $fillable = [
        'email',
        'member_id',
        'mailing_id',
        'unsubscribed_at',
    ];

    protected function casts(): array
    {
        return ['unsubscribed_at' => 'datetime'];
    }

    public static function hasUnsubscribed(string $email): bool
    {
        return static::where('email', strtolower($email))->exists();
    }

    public static function record(string $email, ?int $mailingId = null, ?int $memberId = null): void
    {
        static::firstOrCreate(
            ['email' => strtolower($email)],
            [
                'mailing_id'      => $mailingId,
                'member_id'       => $memberId,
                'unsubscribed_at' => now(),
            ]
        );
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function mailing(): BelongsTo
    {
        return $this->belongsTo(Mailing::class);
    }
}
