<?php

namespace App\Models\Content;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NotificationLog extends Model
{
    protected $table = 'notification_log';

    protected $fillable = [
        'notifiable_type',
        'notifiable_id',
        'notification_type',
        'recipient_email',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Check whether a notification of this type was already sent for the given model.
     */
    public static function alreadySent(Model $notifiable, string $type, ?\Carbon\Carbon $since = null): bool
    {
        $query = static::where('notifiable_type', get_class($notifiable))
            ->where('notifiable_id', $notifiable->getKey())
            ->where('notification_type', $type);

        if ($since) {
            $query->where('sent_at', '>=', $since);
        }

        return $query->exists();
    }

    /**
     * Record a sent notification.
     */
    public static function record(Model $notifiable, string $type, string $email): static
    {
        return static::create([
            'notifiable_type' => get_class($notifiable),
            'notifiable_id'   => $notifiable->getKey(),
            'notification_type' => $type,
            'recipient_email'   => $email,
        ]);
    }
}
