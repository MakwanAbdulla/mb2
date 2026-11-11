<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * @property string      $key
 * @property string      $category
 * @property string|null $label
 * @property string|null $value
 * @property string      $type
 * @property string|null $description
 */
class SystemSetting extends Model
{
    public const TYPE_STRING    = 'string';
    public const TYPE_INTEGER   = 'integer';
    public const TYPE_BOOLEAN   = 'boolean';
    public const TYPE_JSON      = 'json';
    public const TYPE_ENCRYPTED = 'encrypted';

    // Known categories — used for grouping in admin UI
    public const CATEGORY_GENERAL       = 'general';
    public const CATEGORY_MEMBERSHIP    = 'membership';
    public const CATEGORY_BOOKING       = 'booking';
    public const CATEGORY_ECONOMY       = 'economy';
    public const CATEGORY_NOTIFICATIONS = 'notifications';
    public const CATEGORY_SECTIONS      = 'sections';
    public const CATEGORY_INBOX         = 'inbox';

    protected $primaryKey = 'key';
    public $incrementing  = false;
    protected $keyType    = 'string';

    /** @var list<string> */
    protected $fillable = [
        'key',
        'category',
        'label',
        'value',
        'type',
        'description',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::find($key);

        if (! $setting) {
            return $default;
        }

        return match ($setting->type) {
            self::TYPE_INTEGER   => (int) $setting->value,
            self::TYPE_BOOLEAN   => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
            self::TYPE_JSON      => json_decode($setting->value, true),
            self::TYPE_ENCRYPTED => $setting->value === '' ? '' : Crypt::decryptString($setting->value),
            default              => $setting->value,
        };
    }

    public static function set(string $key, mixed $value, string $type = self::TYPE_STRING): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => static::prepareValueForStorage($value, $type), 'type' => $type]
        );
    }

    /**
     * Like firstOrCreate(), but encrypts the value when the row's type is
     * TYPE_ENCRYPTED and doesn't exist yet — used by seeders, which write
     * raw attribute arrays and so would otherwise bypass set()'s encryption.
     */
    public static function firstOrCreateSetting(array $attributes): self
    {
        $existing = static::find($attributes['key']);
        if ($existing) {
            return $existing;
        }

        $attributes['value'] = static::prepareValueForStorage($attributes['value'], $attributes['type'] ?? self::TYPE_STRING);

        return static::create($attributes);
    }

    public static function prepareValueForStorage(mixed $value, string $type): string
    {
        $stringValue = is_array($value) ? json_encode($value) : (string) $value;

        if ($type === self::TYPE_ENCRYPTED && $stringValue !== '') {
            return Crypt::encryptString($stringValue);
        }

        return $stringValue;
    }

    /** All settings for a given category, keyed by key. */
    public static function forCategory(string $category): \Illuminate\Support\Collection
    {
        return static::where('category', $category)->orderBy('key')->get()->keyBy('key');
    }
}
