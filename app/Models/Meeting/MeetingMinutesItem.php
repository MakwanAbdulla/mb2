<?php

namespace App\Models\Meeting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $meeting_id
 * @property int         $agenda_item_id
 * @property string|null $content
 * @property string|null $decision
 * @property bool        $is_decision
 */
class MeetingMinutesItem extends Model
{
    protected $fillable = [
        'meeting_id', 'agenda_item_id', 'content', 'decision', 'is_decision',
    ];

    protected $casts = [
        'is_decision' => 'boolean',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function agendaItem(): BelongsTo
    {
        return $this->belongsTo(MeetingAgendaItem::class, 'agenda_item_id');
    }
}
