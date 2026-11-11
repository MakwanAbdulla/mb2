<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $purpose
 * @property string|null $owner_type
 * @property int|null    $owner_id
 * @property string      $driver
 * @property string|null $mailbox
 * @property string|null $imap_host
 * @property int         $imap_port
 * @property string      $imap_encryption
 * @property string|null $imap_username
 * @property string|null $imap_password
 * @property string      $imap_folder
 * @property string|null $smtp_host
 * @property int         $smtp_port
 * @property string|null $smtp_username
 * @property string|null $smtp_password
 * @property bool        $is_enabled
 */
class InboxMailbox extends Model
{
    public const DRIVER_GOOGLE = 'google_workspace';
    public const DRIVER_IMAP   = 'imap';

    public const PURPOSE_OFFICE     = 'office';
    public const PURPOSE_ACCOUNTING = 'accounting';

    protected $fillable = [
        'name', 'purpose', 'owner_type', 'owner_id',
        'driver', 'mailbox',
        'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password', 'imap_folder',
        'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'imap_password' => 'encrypted',
            'smtp_password' => 'encrypted',
            'imap_port'     => 'integer',
            'smtp_port'     => 'integer',
            'is_enabled'    => 'boolean',
        ];
    }

    public function owner(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }

    /** The display address (varies by driver). */
    public function address(): string
    {
        return $this->driver === self::DRIVER_IMAP
            ? ($this->imap_username ?? '')
            : ($this->mailbox ?? '');
    }

    public static function forPurpose(string $purpose): ?self
    {
        return static::where('purpose', $purpose)->where('is_enabled', true)->first();
    }

    public static function forOwner(object $owner): ?self
    {
        return static::where('owner_type', $owner::class)
            ->where('owner_id', $owner->id)
            ->where('is_enabled', true)
            ->first();
    }
}
