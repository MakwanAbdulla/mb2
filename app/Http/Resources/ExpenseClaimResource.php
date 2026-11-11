<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'description'     => $this->description,
            'amount'          => $this->amount,
            'amount_formatted' => number_format($this->amount / 100, 2, ',', ' ') . ' kr',
            'claim_type'      => $this->claim_type,
            'status'          => $this->status,
            'status_label'    => $this->statusLabel(),
            'expense_date'    => $this->expense_date?->toDateString(),
            'notes'           => $this->notes,
            'created_at'      => $this->created_at->toIso8601String(),

            $this->mergeWhen($this->relationLoaded('member'), [
                'member' => $this->member ? [
                    'id'   => $this->member->id,
                    'name' => $this->member->full_name,
                ] : null,
            ]),

            $this->mergeWhen($this->relationLoaded('costCenter'), [
                'cost_center' => $this->costCenter ? [
                    'id'   => $this->costCenter->id,
                    'name' => $this->costCenter->name,
                ] : null,
            ]),
        ];
    }
}
