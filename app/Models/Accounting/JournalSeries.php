<?php

namespace App\Models\Accounting;

use App\Models\Accounting\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalSeries extends Model
{
    protected $fillable = [
        'code',
        'name',
        'is_manual',
        'is_lockable',
        'is_system',
        'default_for',
        'sort_order',
    ];

    protected $casts = [
        'is_manual'   => 'boolean',
        'is_lockable' => 'boolean',
        'is_system'   => 'boolean',
        'sort_order'  => 'integer',
    ];

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'series', 'code');
    }
}
