<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'series'         => $this->series,
            'number'         => $this->number,
            'date'           => $this->date,
            'description'    => $this->description,
            'is_locked'      => $this->is_locked,
            'is_reviewed'    => $this->isReviewed(),
            'created_at'     => $this->created_at->toIso8601String(),

            $this->mergeWhen($this->relationLoaded('lines'), [
                'lines' => $this->lines->map(fn ($line) => [
                    'id'         => $line->id,
                    'account_id' => $line->account_id,
                    'debit'      => $line->debit,
                    'credit'     => $line->credit,
                    'description' => $line->description,
                ]),
                'total_debit'  => $this->lines->sum('debit'),
                'total_credit' => $this->lines->sum('credit'),
            ]),
        ];
    }
}
