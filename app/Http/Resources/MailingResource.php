<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MailingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'subject'         => $this->subject,
            'status'          => $this->status,
            'scheduled_at'    => $this->scheduled_at?->toIso8601String(),
            'sent_at'         => $this->sent_at?->toIso8601String(),
            'recipient_count' => $this->when($this->relationLoaded('recipients'), fn () => $this->recipients->count()),
            'created_at'      => $this->created_at->toIso8601String(),
        ];
    }
}
