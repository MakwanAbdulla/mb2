<?php

namespace App\Models\Content;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HelpText extends Model
{
    /** @use HasFactory<\Database\Factories\HelpTextFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'key',
        'default_text',
        'custom_text',
        'url',
        'custom_url',
        'group',
        'field_label',
    ];

    public function displayText(): string
    {
        return $this->custom_text ?? $this->default_text;
    }

    public function displayUrl(): ?string
    {
        if ($this->url === null && $this->custom_url === null) {
            return null;
        }

        return $this->custom_url ?? $this->url;
    }

    public function hasCustomText(): bool
    {
        return $this->custom_text !== null;
    }

    public function hasCustomUrl(): bool
    {
        return $this->custom_url !== null;
    }
}
