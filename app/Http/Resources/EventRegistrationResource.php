<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventRegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'event_id'        => $this->event_id,
            'status'          => $this->status,
            'price_tier'      => $this->whenLoaded('priceTier', fn () => [
                'id'    => $this->priceTier->id,
                'label' => $this->priceTier->label,
            ]),
            'notes'           => $this->notes,

            // Check-in token only visible when the registration belongs to the caller
            'check_in_token'  => $this->when(
                $request->user()?->id === $this->user_id,
                $this->check_in_token
            ),

            'created_at'      => $this->created_at->toIso8601String(),
        ];
    }
}
