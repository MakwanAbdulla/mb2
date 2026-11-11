<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventAddonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description,
            'price_ore'   => $this->price_ore,
            'price_formatted' => number_format($this->price_ore / 100, 2, ',', ' ') . ' kr',
            'options'     => $this->options,
            'max_count'   => $this->max_count,
            'spots_taken' => $this->when(
                $this->max_count !== null,
                fn () => $this->registrationAddons()->count()
            ),
            'sort_order'  => $this->sort_order,
        ];
    }
}
