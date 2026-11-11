<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImapImportJob extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'user_id', 'host', 'port', 'encryption', 'username', 'password',
        'status', 'folders_progress', 'messages_imported', 'messages_total',
        'current_folder', 'error', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'password'         => 'encrypted',
            'folders_progress' => 'array',
            'started_at'       => 'datetime',
            'completed_at'     => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lastUidFor(string $folder): int
    {
        return (int) ($this->folders_progress[$folder] ?? 0);
    }

    public function markFolderProgress(string $folder, int $uid): void
    {
        $progress          = $this->folders_progress ?? [];
        $progress[$folder] = $uid;
        $this->update(['folders_progress' => $progress]);
    }

    public function isPending(): bool   { return $this->status === self::STATUS_PENDING; }
    public function isRunning(): bool   { return $this->status === self::STATUS_RUNNING; }
    public function isCompleted(): bool { return $this->status === self::STATUS_COMPLETED; }
    public function isFailed(): bool    { return $this->status === self::STATUS_FAILED; }

    /** True if job appears stuck (running for over 2 hours without completing). */
    public function isStuck(): bool
    {
        return $this->isRunning()
            && $this->started_at
            && $this->started_at->lt(now()->subHours(2));
    }
}
