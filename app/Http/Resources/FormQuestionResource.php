<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'type'             => $this->type->value,
            'label'            => $this->label,
            'description'      => $this->description,
            'is_required'      => $this->is_required,
            'sort_order'       => $this->sort_order,
            'scale_min'        => $this->scale_min,
            'scale_max'        => $this->scale_max,
            'scale_icon'       => $this->scale_icon,
            'datetime_variant' => $this->datetime_variant,
            'number_min'       => $this->number_min,
            'number_max'       => $this->number_max,
            'product_id'          => $this->product_id,
            'event_ids'           => $this->event_ids,
            'section_ids'         => $this->section_ids,
            'section_multi'       => $this->section_multi,
            'membership_type'     => $this->membership_type,
            'rental_item_type_id' => $this->rental_item_type_id,
            'options'             => $this->whenLoaded('options', fn () =>
                $this->options->map(fn ($o) => ['id' => $o->id, 'text' => $o->text])
            ),
        ];
    }
}
