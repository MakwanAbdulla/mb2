<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SurveyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'description' => $this->description,
            'status'      => $this->status->value,
            'is_open'     => $this->is_open,
            'is_anonymous' => $this->is_anonymous,
            'opens_at'    => $this->opens_at?->toIso8601String(),
            'closes_at'   => $this->closes_at?->toIso8601String(),
            'questions'   => FormQuestionResource::collection($this->whenLoaded('questions')),
        ];
    }
}
