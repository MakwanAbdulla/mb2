<?php

namespace App\Http\Resources;

use App\Models\Poll;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PollResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'question'        => $this->question,
            'type'            => $this->type,
            'type_label'      => Poll::TYPES[$this->type] ?? $this->type,
            'status'          => $this->status,
            'status_label'    => Poll::STATUSES[$this->status] ?? $this->status,
            'eligible_count'  => $this->eligible_count,
            'deadline'        => $this->deadline?->toIso8601String(),
            'opened_at'       => $this->opened_at?->toIso8601String(),
            'closed_at'       => $this->closed_at?->toIso8601String(),

            $this->mergeWhen($this->isClosed(), [
                'result'          => $this->result,
                'result_label'    => $this->resultLabel(),
                'result_yes'      => $this->result_yes,
                'result_no'       => $this->result_no,
                'result_abstain'  => $this->result_abstain,
                'result_note'     => $this->result_note,
                'votes_cast'      => $this->votesCast(),
            ]),

            $this->mergeWhen($this->isOpen(), [
                'votes_cast'      => $this->votesCast(),
            ]),

            $this->mergeWhen($this->relationLoaded('options'), [
                'options' => $this->options->map(fn ($o) => [
                    'id'    => $o->id,
                    'label' => $o->label,
                ]),
            ]),
        ];
    }
}
