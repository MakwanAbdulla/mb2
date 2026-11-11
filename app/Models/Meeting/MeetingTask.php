<?php

namespace App\Models\Meeting;

use App\Concerns\HasComments;
use App\Models\Membership\Member;
use App\Models\Organization\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int         $id
 * @property string      $title
 * @property string|null $description
 * @property int|null    $meeting_id
 * @property int|null    $agenda_item_id
 * @property int|null    $section_id
 * @property string      $status
 * @property \Carbon\Carbon|null $due_date
 * @property \Carbon\Carbon|null $completed_at
 * @property int         $created_by
 */
class MeetingTask extends Model
{
    use HasComments;

    protected $fillable = [
        'title', 'description', 'sourceable_type', 'sourceable_id',
        'section_id', 'status', 'due_date', 'completed_at', 'created_by',
        'source_gmail_id', 'source_gmail_subject', 'source_gmail_from',
    ];

    protected $casts = [
        'due_date'     => 'date',
        'completed_at' => 'datetime',
    ];

    public const STATUSES = [
        'open'        => 'Öppen',
        'in_progress' => 'Pågår',
        'done'        => 'Klar',
        'cancelled'   => 'Avbruten',
    ];

    public function sourceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'meeting_task_assignees', 'task_id', 'member_id')
            ->withTimestamps();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && ! \in_array($this->status, ['done', 'cancelled']);
    }
}
