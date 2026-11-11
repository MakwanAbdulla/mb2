<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class JournalTemplate extends Model
{
    protected $fillable = [
        'name',
        'description',
        'series',
        'lines',
        'is_active',
    ];

    protected $casts = [
        'lines'     => 'array',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
