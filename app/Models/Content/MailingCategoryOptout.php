<?php

namespace App\Models\Content;

use App\Models\Membership\Member;
use App\Support\NotificationCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailingCategoryOptout extends Model
{
    protected $table = 'mailing_category_optouts';

    protected $fillable = [
        'email',
        'member_id',
        'category',
        'opted_out_at',
    ];

    protected function casts(): array
    {
        return ['opted_out_at' => 'datetime'];
    }

    public static function hasOptedOut(string $email, string $category): bool
    {
        if (! NotificationCategory::canOptOut($category)) {
            return false;
        }

        return static::where('email', strtolower($email))
            ->where('category', $category)
            ->exists();
    }

    /** Returns all opted-out category slugs for an email. */
    public static function forEmail(string $email): \Illuminate\Support\Collection
    {
        return static::where('email', strtolower($email))->pluck('category');
    }

    public static function record(string $email, string $category, ?int $memberId = null): void
    {
        if (! NotificationCategory::canOptOut($category)) {
            return;
        }

        static::firstOrCreate(
            ['email' => strtolower($email), 'category' => $category],
            ['member_id' => $memberId, 'opted_out_at' => now()]
        );
    }

    public static function restore(string $email, string $category): void
    {
        static::where('email', strtolower($email))
            ->where('category', $category)
            ->delete();
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
