<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'name'                   => $this->name,
            'slug'                   => $this->slug,
            'description'            => $this->description,
            'type'                   => $this->type,
            'status'                 => $this->status,
            'price_ore'              => $this->price_ore,
            'price_formatted'        => number_format($this->price_ore / 100, 2, ',', ' ') . ' kr',
            'requires_membership'    => $this->requires_membership,
            'show_in_event_checkout' => $this->show_in_event_checkout,
            'track_stock'            => $this->track_stock,
            'is_available'           => $this->isAvailable(),
            'has_variants'           => $this->hasVariants(),
            'dimension_label'        => $this->whenLoaded('dimension', fn () => $this->dimension?->label),
            'image_url'              => $this->image_path
                ? asset('storage/' . $this->image_path)
                : null,
            'sort_order'             => $this->sort_order,
            'variants'               => ProductVariantResource::collection($this->whenLoaded('variants')),
            'created_at'             => $this->created_at->toIso8601String(),
            'updated_at'             => $this->updated_at->toIso8601String(),
        ];
    }
}
