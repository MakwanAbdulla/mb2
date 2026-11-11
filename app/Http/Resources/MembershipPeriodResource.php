<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'year'            => $this->year,
            'membership_type' => $this->membership_type,
            'status'          => $this->status,
            'activated_at'    => $this->activated_at?->toIso8601String(),
            'expires_at'      => $this->expires_at?->toIso8601String(),
        ];
    }
}
