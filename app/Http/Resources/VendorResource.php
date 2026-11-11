<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'code'               => $this->code,
            'name'               => $this->name,
            'type'               => $this->type,
            'org_number'         => $this->org_number,
            'bankgiro'           => $this->bankgiro,
            'plusgiro'           => $this->plusgiro,
            'email'              => $this->email,
            'phone'              => $this->phone,
            'default_vat_rate'   => $this->default_vat_rate,
            'is_active'          => $this->is_active,
        ];
    }
}
