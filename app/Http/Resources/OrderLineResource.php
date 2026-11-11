<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'category'       => $this->category,
            'slot_role'      => $this->slot_role,
            'description'    => $this->description,
            'quantity'       => $this->quantity,
            'unit_price_ore' => $this->unit_price_ore,
            'discount_ore'   => $this->discount_ore,
            'line_total_ore' => $this->line_total_ore,
        ];
    }
}
