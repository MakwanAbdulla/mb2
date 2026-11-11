<?php

namespace App\Models\Content;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class NavigationItem extends Model
{
    /** @use HasFactory<\Database\Factories\NavigationItemFactory> */
    use HasFactory, SoftDeletes;

    const LOCATION_TOPNAV = 'topnav';
    const LOCATION_FOOTER = 'footer';

    /** @var list<string> */
    protected $fillable = [
        'seeder_key',
        'label',
        'icon',
        'route_name',
        'url',
        'location',
        'order',
        'parent_id',
        'permission',
        'requires_auth',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'requires_auth' => 'boolean',
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(NavigationItem::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(NavigationItem::class, 'parent_id')->orderBy('order');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeRootItems($query)
    {
        return $query->whereNull('parent_id')->orderBy('order');
    }

    public function scopeTopnav($query)
    {
        return $query->where('location', self::LOCATION_TOPNAV);
    }

    public function scopeFooter($query)
    {
        return $query->where('location', self::LOCATION_FOOTER);
    }

    public function scopeForUser($query, $user = null)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('requires_auth', false);

            if ($user) {
                $q->orWhere(function ($q2) use ($user) {
                    $q2->where('requires_auth', true)
                        ->where(function ($q3) use ($user) {
                            $q3->whereNull('permission')
                                ->orWhereRaw('? = 1', [$user->hasPermissionTo($q3->getModel()->permission ?? '') ? 1 : 0]);
                        });
                });
            }
        });
    }

    public function resolvedUrl(): ?string
    {
        if ($this->route_name && \Route::has($this->route_name)) {
            return route($this->route_name);
        }

        if ($this->url) {
            return str_starts_with($this->url, 'http')
                ? $this->url
                : url($this->url);
        }

        return null;
    }
}
