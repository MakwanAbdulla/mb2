<?php

namespace App\Models\Meeting;

use App\Concerns\HasPolls;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int         $id
 * @property int         $meeting_id
 * @property int         $sort_order
 * @property string      $title
 * @property string|null $description
 * @property string|null $minutes_template
 * @property string      $item_type
 * @property bool        $is_required
 */
class MeetingAgendaItem extends Model
{
    use HasPolls;
    protected $fillable = [
        'meeting_id', 'sort_order', 'title', 'description', 'minutes_template', 'item_type', 'is_required',
        'source_gmail_id', 'source_gmail_subject', 'source_gmail_from',
    ];

    protected $casts = [
        'is_required' => 'boolean',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function minutesItem(): HasOne
    {
        return $this->hasOne(MeetingMinutesItem::class, 'agenda_item_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MeetingDocument::class, 'agenda_item_id')->orderBy('created_at');
    }
}
