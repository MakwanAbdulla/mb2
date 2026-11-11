<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'type'         => $this->type,
            'type_label'   => $this->typeLabel(),
            'title'        => $this->title,
            'body'         => $this->body,
            'status'       => $this->status,
            'status_label' => $this->statusLabel(),
            'board_response'  => $this->board_response,
            'agenda_item_id'  => $this->agenda_item_id,
            'resolved_at'     => $this->resolved_at?->toIso8601String(),
            'created_at'   => $this->created_at->toIso8601String(),

            $this->mergeWhen($this->relationLoaded('createdBy'), [
                'created_by' => $this->createdBy ? [
                    'id'   => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                ] : null,
            ]),

            $this->mergeWhen($this->relationLoaded('section'), [
                'section' => $this->section ? [
                    'id'   => $this->section->id,
                    'name' => $this->section->name,
                ] : null,
            ]),

            $this->mergeWhen($this->relationLoaded('agendaItem'), [
                'meeting_id' => $this->agendaItem?->meeting_id,
            ]),
        ];
    }
}
