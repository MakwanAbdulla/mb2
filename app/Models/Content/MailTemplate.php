<?php

namespace App\Models\Content;

use Illuminate\Database\Eloquent\Model;

class MailTemplate extends Model
{
    protected $fillable = [
        'key',
        'label',
        'description',
        'subject',
        'body',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public static function findByKey(string $key): ?self
    {
        return static::where('key', $key)->first();
    }
}
