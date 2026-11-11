<?php

namespace App\Models;

use App\Concerns\HasReactions;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int         $id
 * @property string      $commentable_type
 * @property int         $commentable_id
 * @property int         $author_id
 * @property string      $body
 * @property \Carbon\Carbon|null $edited_at
 */
class Comment extends Model
{
    use HasReactions;

    protected $fillable = ['author_id', 'body', 'edited_at'];

    protected $casts = [
        'edited_at' => 'datetime',
    ];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
