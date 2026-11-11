<?php

namespace App\Models\Content;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $user_id
 * @property string|null $title
 * @property string      $message
 * @property \Carbon\Carbon $starts_at
 * @property \Carbon\Carbon $ends_at
 * @property int         $duration
 * @property string      $type
 * @property bool        $is_public
 */
class Notice extends Model
{
    protected $fillable = ['user_id', 'title', 'message', 'starts_at', 'ends_at', 'duration', 'type', 'is_public'];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at'   => 'date',
        'is_public' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        $today = today();

        return $query->whereDate('starts_at', '<=', $today)
                     ->whereDate('ends_at', '>=', $today);
    }
}
