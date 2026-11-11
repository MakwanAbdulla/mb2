<?php

namespace App\Models\Meeting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $template_id
 * @property int         $sort_order
 * @property string      $title
 * @property string|null $description
 * @property string|null $minutes_template
 * @property string      $item_type
 * @property bool        $is_required
 */
class MeetingTemplateItem extends Model
{
    protected $fillable = [
        'template_id', 'sort_order', 'title', 'description', 'minutes_template', 'item_type', 'is_required',
    ];

    protected $casts = [
        'is_required' => 'boolean',
    ];

    public const ITEM_TYPES = [
        'standard' => 'Standard',
        'vote'     => 'Beslutspunkt',
        'election' => 'Val',
        'info'     => 'Informationspunkt',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(MeetingTemplate::class, 'template_id');
    }
}
