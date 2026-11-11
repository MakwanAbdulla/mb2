<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'first_name'      => $this->first_name,
            'last_name'       => $this->last_name,
            'full_name'       => $this->full_name,
            'email'           => $this->email,
            'phone'           => $this->phone,
            'address'         => $this->address,
            'postal_code'     => $this->postal_code,
            'city'            => $this->city,
            'date_of_birth'   => $this->date_of_birth?->format('Y-m-d'),
            'membership_type' => $this->membership_type,
            'status'          => $this->status,
            'cogworks_id'     => $this->cogworks_id,
            'has_account'     => $this->user_id !== null,
            'created_at'      => $this->created_at->toIso8601String(),
            'updated_at'      => $this->updated_at->toIso8601String(),

            // Loaded relations (only included when eager-loaded)
            'membership_periods' => MembershipPeriodResource::collection(
                $this->whenLoaded('membershipPeriods')
            ),
            'sections' => $this->whenLoaded('sections', fn () =>
                $this->sections->map(fn ($s) => [
                    'id'   => $s->id,
                    'name' => $s->name,
                    'slug' => $s->slug,
                ])
            ),
            'section_roles' => $this->whenLoaded('sectionRoles', fn () =>
                $this->sectionRoles->map(fn ($r) => [
                    'section_id'  => $r->section_id,
                    'title'       => $r->title,
                    'valid_from'  => $r->valid_from?->format('Y-m-d'),
                    'valid_until' => $r->valid_until?->format('Y-m-d'),
                ])
            ),
        ];
    }
}
