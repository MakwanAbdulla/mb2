<?php

namespace App\Models\Meeting;

use App\Models\Organization\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property int|null    $section_id
 * @property string      $name
 * @property string|null $description
 * @property string      $meeting_type
 * @property int         $default_notice_days
 * @property bool        $include_absences_in_minutes
 * @property int         $created_by
 */
class MeetingTemplate extends Model
{
    protected $fillable = [
        'section_id', 'name', 'description', 'meeting_type', 'is_extraordinary',
        'default_notice_days', 'include_absences_in_minutes', 'created_by',
    ];

    protected $casts = [
        'include_absences_in_minutes' => 'boolean',
        'is_extraordinary'            => 'boolean',
    ];

    public const TYPES = [
        'board_meeting'   => 'Styrelsemöte',
        'members_meeting' => 'Medlemsmöte',
        'annual_general'  => 'Årsmöte',
        'section'         => 'Sektionsmöte',
        'other'           => 'Övrigt',
    ];

    public const MOTION_TYPES = ['members_meeting', 'annual_general'];

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MeetingTemplateItem::class, 'template_id')->orderBy('sort_order');
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'template_id');
    }

    public function roleMappings(): HasMany
    {
        return $this->hasMany(MeetingTemplateRoleMapping::class, 'template_id');
    }

    public function typeLabel(): string
    {
        $base = self::TYPES[$this->meeting_type] ?? $this->meeting_type;
        return $this->is_extraordinary ? "Extra {$base}" : $base;
    }
}
