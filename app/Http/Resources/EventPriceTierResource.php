<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventPriceTierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'label'               => $this->label,
            'description'         => $this->description,
            'price_ore'           => $this->price_ore,
            'price_formatted'     => $this->isFree()
                ? 'Gratis'
                : number_format($this->price_ore / 100, 2, ',', ' ') . ' kr',
            'is_free'             => $this->isFree(),
            'requires_membership' => $this->requires_membership,
            'capacity'            => $this->max_count,
            'spots_taken'         => $this->when(
                $this->max_count !== null,
                fn () => $this->registrations()
                    ->whereIn('status', ['confirmed', 'pending_payment', 'pending_approval'])
                    ->count()
            ),
        ];
    }
}
