<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $google_drive_id
 * @property string $email
 * @property string $permission_id
 * @property string $role
 * @property string $source
 */
class WorkspaceDriveMember extends Model
{
    public const SOURCE_MANUAL  = 'manual';
    public const SOURCE_ROLE    = 'role';
    public const SOURCE_SECTION = 'section';

    protected $fillable = ['google_drive_id', 'email', 'permission_id', 'role', 'source'];
}
