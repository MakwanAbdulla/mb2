<?php

namespace App\Models\Gamification;

use App\Models\Membership\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskCompletion extends Model
{
    protected $table = 'gamification_task_completions';

    protected $fillable = [
        'task_id', 'member_id', 'given_by_member_id',
        'approved_by_user_id', 'status', 'xp_log_id',
        'note', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    public function task(): BelongsTo
    {
        return $this->belongsTo(GamificationTask::class, 'task_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function givenBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'given_by_member_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
