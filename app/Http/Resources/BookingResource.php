<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'title'        => $this->title,
            'activity'     => $this->activity,
            'booking_type' => $this->booking_type,
            'status'       => $this->status,
            'starts_at'    => $this->starts_at->toIso8601String(),
            'ends_at'      => $this->ends_at->toIso8601String(),
            'location'     => $this->whenLoaded('resource', fn () => $this->resource->name),
            'notes'        => $this->notes,
            'created_at'   => $this->created_at->toIso8601String(),

            $this->mergeWhen($this->relationLoaded('user'), [
                'booked_by' => $this->user ? [
                    'id'   => $this->user->id,
                    'name' => $this->user->name,
                ] : null,
            ]),
        ];
    }
}
