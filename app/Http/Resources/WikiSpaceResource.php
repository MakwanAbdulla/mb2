<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WikiSpaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'read_access' => $this->read_access->value,
            'group' => $this->group ? ['id' => $this->group->id, 'name' => $this->group->name] : null,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'pages_count' => $this->whenCounted('pages'),
            'url' => route('wiki.space', $this->resource, absolute: false),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
