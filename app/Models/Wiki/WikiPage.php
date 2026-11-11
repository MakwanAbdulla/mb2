<?php

namespace App\Models\Wiki;

use App\Models\User;
use App\Support\WikiHtml;
use Database\Factories\Wiki\WikiPageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A page in a wiki space. Pages form a tree per space via parent_id.
 *
 * Every save that creates the page or changes its title or content writes a
 * WikiPageRevision in the same transaction, so the history is never missing
 * a version. Moving a page in the tree is not a revision.
 *
 * @property int $id
 * @property int $wiki_space_id
 * @property int|null $parent_id
 * @property string $title
 * @property string $slug unique per space, including soft-deleted pages; kept on rename
 * @property string|null $content Trix HTML, sanitized on save (WikiHtml)
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class WikiPage extends Model
{
    /** @use HasFactory<WikiPageFactory> */
    use HasFactory, SoftDeletes;

    /** Slugs used by routes under /wiki/{space}/ — never given to a page. */
    public const RESERVED_SLUGS = ['ny-sida'];

    /** "Beskriv ändringen" for the next save's revision; not a column. */
    public ?string $revisionSummary = null;

    protected $fillable = [
        'wiki_space_id',
        'parent_id',
        'title',
        'slug',
        'content',
        'created_by',
        'updated_by',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Children of a trashed page move up to its parent, so they stay in the tree.
        static::deleting(function (WikiPage $page) {
            if (! $page->isForceDeleting()) {
                $page->children()->update(['parent_id' => $page->parent_id]);
            }
        });

        static::saved(function (WikiPage $page) {
            if ($page->wasRecentlyCreated || $page->wasChanged(['title', 'content'])) {
                $page->revisions()->create([
                    'title' => $page->title,
                    'content' => $page->content,
                    'user_id' => $page->updated_by,
                    'summary' => $page->revisionSummary,
                ]);
            }
            $page->revisionSummary = null;
        });
    }

    /** The page and its revision are saved together or not at all. */
    public function save(array $options = []): bool
    {
        return DB::transaction(fn () => parent::save($options));
    }

    /** Never trusted: any group member may write a page. */
    protected function content(): Attribute
    {
        return Attribute::set(fn (?string $value) => WikiHtml::sanitize($value));
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(WikiSpace::class, 'wiki_space_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(WikiPage::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(WikiPage::class, 'parent_id')->orderBy('sort_order')->orderBy('title');
    }

    /** Newest first. */
    public function revisions(): HasMany
    {
        return $this->hasMany(WikiPageRevision::class)->latest('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Pages in spaces the user (null = guest) may read. */
    public function scopeReadableBy(Builder $query, ?User $user): Builder
    {
        return $query->whereHas('space', fn (Builder $q) => $q->readableBy($user));
    }

    // ── Tree ─────────────────────────────────────────────────────────────────

    /** From the top of the tree down to the parent of this page. */
    public function ancestors(): Collection
    {
        $chain = new Collection;
        $seen = [$this->id];

        for ($page = $this->parent; $page && ! in_array($page->id, $seen, true); $page = $page->parent) {
            $chain->prepend($page);
            $seen[] = $page->id;
        }

        return $chain;
    }

    /** Ids of every page below this one. */
    public function descendantIds(): array
    {
        $byParent = self::where('wiki_space_id', $this->wiki_space_id)->pluck('parent_id', 'id')
            ->groupBy(fn ($parentId) => $parentId, preserveKeys: true)
            ->map(fn ($group) => $group->keys()->all());

        $ids = [];
        for ($queue = $byParent->get($this->id, []); $queue !== [];) {
            $id = array_shift($queue);
            $ids[] = $id;
            array_push($queue, ...$byParent->get($id, []));
        }

        return $ids;
    }

    /** A parent must be in the same space, and neither this page nor one below it. */
    public function canHaveParent(?WikiPage $parent): bool
    {
        if ($parent === null) {
            return true;
        }

        return $parent->wiki_space_id === $this->wiki_space_id
            && $parent->id !== $this->id
            && ! in_array($parent->id, $this->descendantIds(), true);
    }

    /** Swap places with the previous (-1) or next (+1) sibling. */
    public function move(int $direction): void
    {
        $siblings = self::where('wiki_space_id', $this->wiki_space_id)
            ->where('parent_id', $this->parent_id)
            ->orderBy('sort_order')->orderBy('title')
            ->get()->values();

        $from = $siblings->search(fn (WikiPage $p) => $p->id === $this->id);
        $to = $from + ($direction < 0 ? -1 : 1);
        if ($from === false || $to < 0 || $to >= $siblings->count()) {
            return;
        }

        // Renumber first: siblings may share a sort_order.
        $ordered = $siblings->all();
        [$ordered[$from], $ordered[$to]] = [$ordered[$to], $ordered[$from]];

        $cases = [];
        foreach ($ordered as $i => $page) {
            if ($page->sort_order !== $i) {
                $cases[(int) $page->id] = $i;
            }
        }

        if ($cases === []) {
            return;
        }

        // One UPDATE for all changed siblings. Not an edit: updated_at and revisions stay.
        $sql = 'CASE id '.implode(' ', array_map(fn (int $id, int $i) => "WHEN {$id} THEN {$i}", array_keys($cases), $cases)).' END';
        self::whereKey(array_keys($cases))->toBase()->update(['sort_order' => DB::raw($sql)]);
    }

    // ── Writing ──────────────────────────────────────────────────────────────

    /** Shared by the page form and the API. */
    public static function rules(WikiSpace $space, ?WikiPage $page = null): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string', 'max:500000'],
            'summary' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($space, $page) {
                $parent = self::where('wiki_space_id', $space->id)->find($value);

                if (! $parent) {
                    $fail('Föräldersidan finns inte i det här utrymmet.');
                } elseif ($page && ! $page->canHaveParent($parent)) {
                    $fail('En sida kan inte ligga under sig själv eller under någon av sina undersidor.');
                }
            }],
        ];
    }

    /** @param array{title: string, content?: ?string, parent_id?: ?int, summary?: ?string} $data */
    public static function createIn(WikiSpace $space, array $data, User $author): self
    {
        $parentId = $data['parent_id'] ?? null;

        $page = new self([
            'wiki_space_id' => $space->id,
            'parent_id' => $parentId,
            'title' => $data['title'],
            'slug' => self::uniqueSlug($space->id, $data['title']),
            'content' => $data['content'] ?? null,
            'sort_order' => self::nextSortOrder($space->id, $parentId),
            'created_by' => $author->id,
            'updated_by' => $author->id,
        ]);
        $page->revisionSummary = $data['summary'] ?? null;
        $page->save();

        return $page;
    }

    /**
     * The slug stays when the title changes, so links keep working.
     *
     * @param  array{title?: string, content?: ?string, parent_id?: ?int, summary?: ?string}  $data
     */
    public function updateFrom(array $data, User $editor): self
    {
        if (array_key_exists('parent_id', $data) && $data['parent_id'] !== $this->parent_id) {
            $this->parent_id = $data['parent_id'];
            $this->sort_order = self::nextSortOrder($this->wiki_space_id, $data['parent_id']);
        }

        $this->fill(array_intersect_key($data, array_flip(['title', 'content'])));
        $this->updated_by = $editor->id;
        $this->revisionSummary = $data['summary'] ?? null;
        $this->save();

        return $this;
    }

    /** A slug from the title, unique in the space (trashed pages count), with -2, -3 … when taken. */
    public static function uniqueSlug(int $spaceId, string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'sida';
        $slug = $base;

        for ($i = 2; in_array($slug, self::RESERVED_SLUGS, true) || self::withTrashed()
            ->where('wiki_space_id', $spaceId)->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private static function nextSortOrder(int $spaceId, ?int $parentId): int
    {
        return (int) self::where('wiki_space_id', $spaceId)->where('parent_id', $parentId)->max('sort_order') + 1;
    }
}
