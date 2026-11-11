<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'payment_reference' => $this->payment_reference,
            'ocr_number'        => $this->ocr_number,
            'status'            => $this->status,
            'payment_method'    => $this->payment_method,
            'total_ore'         => $this->total_ore,
            'total_formatted'   => number_format($this->total_ore / 100, 2, ',', ' ') . ' kr',
            'paid_at'           => $this->paid_at?->toIso8601String(),
            'created_at'        => $this->created_at->toIso8601String(),
            'lines'             => OrderLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
