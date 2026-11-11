<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,
            'parent_id'   => $this->parent_id,
            'is_active'   => $this->is_active,
            'visibility'  => $this->visibility,
            'accepts_issues' => $this->accepts_issues,
            'sort_order'  => $this->sort_order,

            $this->mergeWhen($this->relationLoaded('children'), [
                'children' => SectionResource::collection($this->children),
            ]),
        ];
    }
}
