<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VenueApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'event_name'        => $this->event_name,
            'description'       => $this->description,
            'responsible_name'  => $this->responsible_name,
            'responsible_phone' => $this->responsible_phone,
            'responsible_email' => $this->responsible_email,
            'starts_at'         => $this->starts_at->toIso8601String(),
            'ends_at'           => $this->ends_at->toIso8601String(),
            'participants'      => $this->participants,
            'needs_overnight'   => $this->needs_overnight,
            'needs_key'         => $this->needs_key,
            'has_alcohol'       => $this->has_alcohol,
            'event_type'        => $this->event_type,
            'event_type_reason' => $this->event_type_reason,
            'resource_ids'      => $this->resource_ids,
            'activity'          => $this->activity,
            'notes'             => $this->notes,
            'status'            => $this->status,
            'admin_notes'       => $this->admin_notes,
            'booking_ids'       => $this->booking_ids,
            'user_id'           => $this->user_id,
            'created_at'        => $this->created_at->toIso8601String(),
            'updated_at'        => $this->updated_at->toIso8601String(),
        ];
    }
}
