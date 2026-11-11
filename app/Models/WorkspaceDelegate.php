<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $mailbox
 * @property string $delegate_email
 * @property string $status
 * @property string $source
 */
class WorkspaceDelegate extends Model
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_ROLE   = 'role';

    protected $fillable = ['mailbox', 'delegate_email', 'status', 'source'];
}
