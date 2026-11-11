<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int    $id
 * @property int    $poll_id
 * @property string $label
 * @property int    $sort_order
 */
class PollOption extends Model
{
    public $timestamps = false;

    protected $fillable = ['poll_id', 'label', 'sort_order'];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    public function ballots(): HasMany
    {
        return $this->hasMany(PollBallot::class, 'option_id');
    }
}
