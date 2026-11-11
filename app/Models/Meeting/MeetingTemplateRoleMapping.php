<?php

namespace App\Models\Meeting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int    $id
 * @property int    $template_id
 * @property string $section_role_keyword
 * @property string $meeting_role
 */
class MeetingTemplateRoleMapping extends Model
{
    protected $fillable = [
        'template_id', 'section_role_keyword', 'meeting_role',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(MeetingTemplate::class, 'template_id');
    }
}
