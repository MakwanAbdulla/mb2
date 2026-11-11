<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'brand' => $this->brand,
            'description' => $this->description,
            'status' => $this->status,
            'visibility' => $this->visibility,

            // Location
            'location_type' => $this->location_type,
            'location_name' => $this->location_name,
            'location_url' => $this->location_url,

            // Registration settings
            'registration_mode' => $this->registration_mode,
            'admission_type' => $this->admission_type,
            'waitlist_mode' => $this->waitlist_mode,
            'requires_membership' => $this->requires_membership,
            'registration_opens_at' => $this->registration_opens_at?->toIso8601String(),
            'registration_closes_at' => $this->registration_closes_at?->toIso8601String(),
            'registration_instructions' => $this->registration_instructions,
            'is_registration_open' => $this->isRegistrationOpen(),

            // Aggregates
            'confirmed_registrations' => $this->whenLoaded(
                'registrations',
                fn () => $this->registrations->where('status', 'confirmed')->count()
            ),

            // Relations
            'occurrences' => EventOccurrenceResource::collection($this->whenLoaded('occurrences')),
            'price_tiers' => EventPriceTierResource::collection($this->whenLoaded('priceTiers')),
            'addons' => EventAddonResource::collection($this->whenLoaded('addons')),
            'organizers' => $this->whenLoaded('organizers', fn () => $this->organizers->map(fn ($m) => [
                'id' => $m->id,
                'full_name' => $m->full_name,
                'role' => $m->pivot->role,
            ])
            ),

            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
