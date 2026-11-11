<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'title'        => $this->title,
            'meeting_type' => $this->meeting_type,
            'status'       => $this->status,
            'status_label' => $this->statusLabel(),
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'ends_at'      => $this->ends_at?->toIso8601String(),
            'location'     => $this->location,
            'published_at' => $this->published_at?->toIso8601String(),
            'motion_deadline' => $this->motion_deadline,
            'created_at'   => $this->created_at->toIso8601String(),

            $this->mergeWhen($this->relationLoaded('agendaItems'), [
                'agenda_items' => $this->agendaItems->map(fn ($item) => [
                    'id'          => $item->id,
                    'title'       => $item->title,
                    'description' => $item->description,
                    'item_type'   => $item->item_type,
                    'sort_order'  => $item->sort_order,
                ]),
            ]),

            $this->mergeWhen($this->relationLoaded('attendees'), [
                'attendee_count' => $this->attendees->count(),
            ]),
        ];
    }
}
