<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Content is left out of the page tree (index), included for a single page. */
class WikiPageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'title' => $this->title,
            'slug' => $this->slug,
            'sort_order' => $this->sort_order,
            'content' => $this->when(array_key_exists('content', $this->resource->getAttributes()), fn () => $this->content),
            'updated_by' => $this->whenLoaded('updater', fn () => $this->updater ? ['id' => $this->updater->id, 'name' => $this->updater->name] : null),
            'url' => route('wiki.page', [$this->space ?? $this->wiki_space_id, $this->resource], absolute: false),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
