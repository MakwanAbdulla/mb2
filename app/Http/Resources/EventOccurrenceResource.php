<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventOccurrenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'title'             => $this->title,
            'starts_at'         => $this->starts_at->toIso8601String(),
            'ends_at'           => $this->ends_at->toIso8601String(),
            'location_override' => $this->location_override,
            'capacity'          => $this->max_participants,
            'is_dropin'         => $this->is_dropin,
            'is_cancelled'      => $this->isCancelled(),
            'confirmed_count'   => $this->confirmedCount(),
        ];
    }
}
