<?php

namespace App\Models;

use App\Concerns\HasComments;
use App\Concerns\HasPolls;
use App\Models\InboxMailbox;
use App\Models\Meeting\MeetingAgendaItem;
use App\Models\Organization\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property string      $type
 * @property string      $title
 * @property string|null $body
 * @property string      $status
 * @property int|null    $section_id
 * @property int|null    $agenda_item_id
 * @property string|null $source_gmail_id
 * @property string|null $source_gmail_subject
 * @property string|null $source_gmail_from
 * @property int|null    $source_mailbox_id
 * @property string|null $board_response
 * @property \Carbon\Carbon|null $resolved_at
 * @property int         $created_by
 */
class Submission extends Model
{
    use HasComments, HasPolls;

    protected $fillable = [
        'type', 'title', 'body', 'status',
        'section_id', 'agenda_item_id',
        'source_gmail_id', 'source_gmail_subject', 'source_gmail_from', 'source_mailbox_id',
        'board_response', 'resolved_at', 'created_by',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public const TYPES = [
        'issue'  => 'Ärende',
        'motion' => 'Motion',
    ];

    public const STATUSES = [
        'submitted'    => 'Inlämnad',
        'under_review' => 'Under behandling',
        'on_agenda'    => 'På dagordning',
        'resolved'     => 'Behandlad',
        'rejected'     => 'Avvisad',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function agendaItem(): BelongsTo
    {
        return $this->belongsTo(MeetingAgendaItem::class, 'agenda_item_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sourceMailbox(): BelongsTo
    {
        return $this->belongsTo(InboxMailbox::class, 'source_mailbox_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isOpen(): bool
    {
        return \in_array($this->status, ['submitted', 'under_review']);
    }
}
