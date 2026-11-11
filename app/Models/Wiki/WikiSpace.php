<?php

namespace App\Models\Wiki;

use App\Enums\WikiReadAccess;
use App\Models\Organization\Section;
use App\Models\User;
use App\Support\WikiAccess;
use Database\Factories\Wiki\WikiSpaceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A wiki space, owned by a group or by nobody (association-wide).
 *
 * @property int $id
 * @property int|null $group_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property WikiReadAccess $read_access
 * @property Carbon|null $archived_at hidden from lists and read-only, pages stay readable
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WikiSpace extends Model
{
    /** @use HasFactory<WikiSpaceFactory> */
    use HasFactory;

    protected $fillable = [
        'group_id',
        'name',
        'slug',
        'description',
        'read_access',
        'archived_at',
    ];

    // Mirror the column default so a freshly created model knows it without a refresh.
    protected $attributes = [
        'read_access' => 'members',
    ];

    protected function casts(): array
    {
        return [
            'read_access' => WikiReadAccess::class,
            'archived_at' => 'datetime',
        ];
    }

    // TODO(#168): point at Group once sections become groups.
    public function group(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'group_id');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(WikiPage::class);
    }

    /** Top-level pages, in tree order. */
    public function rootPages(): HasMany
    {
        return $this->pages()->whereNull('parent_id')->orderBy('sort_order')->orderBy('title');
    }

    /**
     * All pages in tree order, grouped by parent_id ('' = top level), for
     * drawing the tree at any depth with one query.
     */
    public function pagesByParent(): Collection
    {
        return $this->pages()
            ->orderBy('sort_order')->orderBy('title')
            ->get(['id', 'wiki_space_id', 'parent_id', 'title', 'slug', 'sort_order'])
            ->groupBy(fn (WikiPage $p) => $p->parent_id ?? '')
            ->map->values();
    }

    /** Association-wide space without an owning group. */
    public function isShared(): bool
    {
        return $this->group_id === null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** Spaces the user (null = guest) may read — same rules as WikiSpacePolicy::view(). */
    public function scopeReadableBy(Builder $query, ?User $user): Builder
    {
        return WikiAccess::forUser($user)->applyReadable($query);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** The group's space, created on first need. Safe to call again for the same group. */
    // TODO(#168): take a Group once sections become groups.
    public static function forGroup(Section $group): self
    {
        return self::firstOrCreate(['group_id' => $group->id], [
            'name' => $group->name,
            'slug' => self::uniqueSlug($group->name),
            'read_access' => WikiReadAccess::Members,
        ]);
    }

    /** A slug from the name, with -2, -3 … appended while it is taken. */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'utrymme';
        $slug = $base;

        for ($i = 2; self::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
