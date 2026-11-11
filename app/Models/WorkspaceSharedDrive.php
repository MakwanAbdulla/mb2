<?php

namespace App\Models;

use App\Models\Organization\OrganizationalRole;
use App\Models\Organization\Section;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string|null $google_drive_id
 * @property string      $name
 * @property string      $member_role
 * @property bool        $public_access
 * @property int|null    $section_id
 * @property int|null    $organizational_role_id
 */
class WorkspaceSharedDrive extends Model
{
    public const ROLE_READER = 'reader';
    public const ROLE_WRITER = 'writer';

    protected $fillable = [
        'google_drive_id',
        'name',
        'member_role',
        'public_access',
        'section_id',
        'organizational_role_id',
    ];

    protected function casts(): array
    {
        return ['public_access' => 'boolean'];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function organizationalRole(): BelongsTo
    {
        return $this->belongsTo(OrganizationalRole::class);
    }

    public function isCreated(): bool
    {
        return $this->google_drive_id !== null;
    }
}
