<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'title'            => $this->title,
            'description'      => $this->description,
            'created_by'       => $this->created_by,
            'questions_count'  => $this->whenCounted('questions'),
            'submissions_count' => $this->whenCounted('submissions'),
            'questions'        => FormQuestionResource::collection($this->whenLoaded('questions')),
            'created_at'       => $this->created_at->toIso8601String(),
            'updated_at'       => $this->updated_at->toIso8601String(),
        ];
    }
}
