<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactMessage extends Model
{
    public const STATUS_NEW     = 'new';
    public const STATUS_READ    = 'read';
    public const STATUS_REPLIED = 'replied';
    public const STATUS_SPAM    = 'spam';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'subject',
        'message',
        'status',
        'notes',
        'ip_address',
        'replied_at',
        'replied_by',
    ];

    protected function casts(): array
    {
        return [
            'replied_at' => 'datetime',
        ];
    }

    public function repliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by');
    }

    public function scopeNew($query)
    {
        return $query->where('status', self::STATUS_NEW);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [self::STATUS_NEW, self::STATUS_READ]);
    }

    public function isNew(): bool
    {
        return $this->status === self::STATUS_NEW;
    }

    public function markRead(): void
    {
        if ($this->status === self::STATUS_NEW) {
            $this->update(['status' => self::STATUS_READ]);
        }
    }

    public function markReplied(int $userId): void
    {
        $this->update([
            'status'     => self::STATUS_REPLIED,
            'replied_at' => now(),
            'replied_by' => $userId,
        ]);
    }

    public function markSpam(): void
    {
        $this->update(['status' => self::STATUS_SPAM]);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_NEW     => 'Ny',
            self::STATUS_READ    => 'Läst',
            self::STATUS_REPLIED => 'Besvarad',
            self::STATUS_SPAM    => 'Skräppost',
            default              => $this->status,
        };
    }
}
