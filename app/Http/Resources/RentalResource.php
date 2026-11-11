<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RentalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'item'            => $this->whenLoaded('item', fn () => [
                'id'    => $this->item->id,
                'label' => $this->item->label,
                'type'  => [
                    'id'   => $this->item->type->id,
                    'name' => $this->item->type->name,
                ],
            ]),
            'price_per_year'           => $this->price_per_year,
            'price_per_year_formatted' => $this->formattedPrice(),
            'started_at'               => $this->started_at->toDateString(),
            'ended_at'                 => $this->ended_at?->toDateString(),
            'is_active'                => $this->isActive(),
            'notes'                    => $this->notes,
        ];
    }
}
