<?php

namespace App\Models\Content;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int         $id
 * @property string|null $path_prefix
 * @property string      $title
 * @property string|null $title_draft
 * @property string|null $subtitle
 * @property string|null $subtitle_draft
 * @property string      $slug
 * @property string|null $content
 * @property string|null $content_draft
 * @property bool        $has_draft
 * @property bool        $is_published
 * @property string|null $image_path
 * @property \Carbon\Carbon|null $published_at
 * @property \Carbon\Carbon|null $unpublished_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Page extends Model
{
    /** @use HasFactory<\Database\Factories\PageFactory> */
    use HasFactory, SoftDeletes;

    const PROTECTED_SLUGS = [
        'hem',      // Startsida
        'om-oss',   // Om föreningen
        'kontakt',  // Kontaktsida
    ];

    /** @var list<string> */
    protected $fillable = [
        'seeder_key',
        'path_prefix',
        'title',
        'title_draft',
        'subtitle',
        'subtitle_draft',
        'slug',
        'content',
        'content_draft',
        'has_draft',
        'image_path',
        'is_published',
        'show_in_nav',
        'nav_title',
        'sort_order',
        'published_at',
        'unpublished_at',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'has_draft' => 'boolean',
            'show_in_nav' => 'boolean',
            'published_at' => 'datetime',
            'unpublished_at' => 'datetime',
        ];
    }

    public function url(): string
    {
        return $this->path_prefix
            ? "/{$this->path_prefix}/{$this->slug}"
            : "/{$this->slug}";
    }

    public function isProtected(): bool
    {
        return in_array($this->slug, self::PROTECTED_SLUGS);
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true)
            ->where(function ($q) {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('unpublished_at')
                    ->orWhere('unpublished_at', '>', now());
            });
    }

    public function scopeInNav($query)
    {
        return $query->where('show_in_nav', true)->orderBy('sort_order');
    }

    public function createDraft(): void
    {
        $this->update([
            'title_draft' => $this->title,
            'subtitle_draft' => $this->subtitle,
            'content_draft' => $this->content,
            'has_draft' => true,
        ]);
    }

    public function publishDraft(): void
    {
        $this->update([
            'title' => $this->title_draft,
            'subtitle' => $this->subtitle_draft,
            'content' => $this->content_draft,
            'title_draft' => null,
            'subtitle_draft' => null,
            'content_draft' => null,
            'has_draft' => false,
            'is_published' => true,
        ]);
    }

    public function discardDraft(): void
    {
        $this->update([
            'title_draft' => null,
            'subtitle_draft' => null,
            'content_draft' => null,
            'has_draft' => false,
        ]);
    }
}
