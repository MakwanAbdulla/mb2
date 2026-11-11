<?php

namespace App\Models\Gamification;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GamificationTask extends Model
{
    protected $table = 'gamification_tasks';

    protected $fillable = [
        'title', 'description', 'xp_value', 'type',
        'requires_approval', 'daily_completions_per_member',
        'daily_gives_per_member', 'is_active', 'expires_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'requires_approval' => 'boolean',
            'is_active'         => 'boolean',
            'expires_at'        => 'datetime',
        ];
    }

    const TYPE_ADMIN_ASSIGN = 'admin_assign';
    const TYPE_CLAIMABLE    = 'claimable';
    const TYPE_PEER         = 'peer';

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function completions(): HasMany
    {
        return $this->hasMany(TaskCompletion::class, 'task_id');
    }

    public function scopeActive($query): void
    {
        $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_ADMIN_ASSIGN => 'Admin tilldelar',
            self::TYPE_CLAIMABLE    => 'Självrapporterad',
            self::TYPE_PEER         => 'Ge till annan',
            default                 => $this->type,
        };
    }
}
