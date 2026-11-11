<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'type'           => $this->type,
            'invoice_number' => $this->invoice_number,
            'invoice_date'   => $this->invoice_date?->toDateString(),
            'due_date'       => $this->due_date?->toDateString(),
            'status'         => $this->status,
            'amount_ex_vat'  => $this->amount_ex_vat,
            'vat_amount'     => $this->vat_amount,
            'total'          => $this->total,
            'total_formatted' => number_format($this->total / 100, 2, ',', ' ') . ' kr',
            'currency'       => $this->currency,
            'payment_method' => $this->payment_method,
            'ocr_number'     => $this->ocr_number,
            'notes'          => $this->notes,
            'paid_at'        => $this->paid_at?->toIso8601String(),
            'created_at'     => $this->created_at->toIso8601String(),

            $this->mergeWhen($this->relationLoaded('vendor'), [
                'vendor' => $this->vendor ? [
                    'id'   => $this->vendor->id,
                    'name' => $this->vendor->name,
                ] : null,
            ]),

            $this->mergeWhen($this->relationLoaded('customer'), [
                'customer' => $this->customer ? [
                    'id'   => $this->customer->id,
                    'name' => $this->customer->name,
                ] : null,
            ]),

            $this->mergeWhen($this->relationLoaded('lines'), [
                'lines' => $this->lines->map(fn ($l) => [
                    'id'          => $l->id,
                    'description' => $l->description,
                    'quantity'    => $l->quantity,
                    'unit_price'  => $l->unit_price,
                    'vat_rate'    => $l->vat_rate,
                    'total'       => $l->total,
                ]),
            ]),
        ];
    }
}
