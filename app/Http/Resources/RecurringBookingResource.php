<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecurringBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'title'              => $this->title,
            'activity'           => $this->activity,
            'booking_type'       => $this->booking_type,
            'participation_info' => $this->participation_info,
            'notes'              => $this->notes,
            'start_time'         => $this->start_time,
            'end_time'           => $this->end_time,
            'frequency_type'     => $this->frequency_type,
            'frequency_label'    => $this->frequencyLabel(),
            'weekday'            => $this->weekday,
            'weekdays'           => $this->weekdays,
            'week_interval'      => $this->week_interval,
            'month_week'         => $this->month_week,
            'month_day'          => $this->month_day,
            'month_interval'     => $this->month_interval,
            'month'              => $this->month,
            'generate_until'     => $this->generate_until?->toDateString(),
            'ends_on'            => $this->ends_on?->toDateString(),
            'last_conflicts'     => $this->last_conflicts,
            'resource_id'        => $this->resource_id,
            'resource_ids'       => $this->effectiveResourceIds(),
            'user_id'            => $this->user_id,
            $this->mergeWhen($this->relationLoaded('resource'), [
                'resource' => $this->resource ? [
                    'id'   => $this->resource->id,
                    'name' => $this->resource->name,
                ] : null,
            ]),
            'created_at'         => $this->created_at->toIso8601String(),
        ];
    }
}
