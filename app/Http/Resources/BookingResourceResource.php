<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'slug'              => $this->slug,
            'description'       => $this->description,
            'location'          => $this->location,
            'capacity'          => $this->capacity,
            'min_duration'      => $this->min_duration,
            'max_duration'      => $this->max_duration,
            'requires_approval' => $this->requires_approval,
            'active'            => $this->active,
            'color'             => $this->color,
            'icon'              => $this->icon,

            $this->mergeWhen($this->relationLoaded('category'), [
                'category' => $this->category ? [
                    'id'   => $this->category->id,
                    'name' => $this->category->name,
                ] : null,
            ]),
        ];
    }
}
