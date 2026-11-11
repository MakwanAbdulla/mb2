<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'number'      => $this->number,
            'name'        => $this->name,
            'type'        => $this->type,
            'is_active'   => $this->is_active,
            'description' => $this->description,
        ];
    }
}
