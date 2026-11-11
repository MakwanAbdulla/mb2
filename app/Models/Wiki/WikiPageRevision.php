<?php

namespace App\Models\Wiki;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A saved version of a wiki page: what the title and content were after one
 * save, who saved it and why. Written by WikiPage, never changed afterwards.
 *
 * @property int $id
 * @property int $wiki_page_id
 * @property string $title
 * @property string|null $content sanitized HTML, as on the page
 * @property int|null $user_id
 * @property string|null $summary "Beskriv ändringen", optional
 * @property Carbon $created_at
 */
class WikiPageRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'wiki_page_id',
        'title',
        'content',
        'user_id',
        'summary',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(WikiPage::class, 'wiki_page_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
