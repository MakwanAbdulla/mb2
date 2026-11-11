<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'description' => $this->description,
            'status'      => $this->status,
            'status_label' => $this->statusLabel(),
            'due_date'    => $this->due_date?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'is_overdue'  => $this->isOverdue(),
            'section_id'  => $this->section_id,
            'section'     => $this->whenLoaded('section', fn () => [
                'id'   => $this->section->id,
                'name' => $this->section->name,
            ]),
            'assignees'   => $this->whenLoaded('assignees', fn () =>
                $this->assignees->map(fn ($m) => [
                    'id'         => $m->id,
                    'first_name' => $m->first_name,
                    'last_name'  => $m->last_name,
                ])
            ),
            'created_at'  => $this->created_at->toIso8601String(),
            'updated_at'  => $this->updated_at->toIso8601String(),
        ];
    }
}
