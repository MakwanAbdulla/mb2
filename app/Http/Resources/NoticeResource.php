<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NoticeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'title'     => $this->title,
            'message'   => $this->message,
            'type'      => $this->type,
            'starts_at' => $this->starts_at->toDateString(),
            'ends_at'   => $this->ends_at->toDateString(),
            'duration'  => $this->duration,
            'is_public' => $this->is_public,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
