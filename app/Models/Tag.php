<?php

namespace App\Models;

use App\Models\Organization\Section;
use App\Models\Booking\Booking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    protected $fillable = ['name', 'slug'];

    public static function findOrCreateByName(string $name): self
    {
        $slug = Str::slug($name);

        return static::firstOrCreate(['slug' => $slug], ['name' => $name]);
    }

    public function bookings(): MorphToMany
    {
        return $this->morphedByMany(Booking::class, 'taggable');
    }

    public function sections(): MorphToMany
    {
        return $this->morphedByMany(Section::class, 'taggable');
    }

    public function sectionPosts(): MorphToMany
    {
        return $this->morphedByMany(\App\Models\SectionPost::class, 'taggable');
    }
}
