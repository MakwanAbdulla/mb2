<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'option_value' => $this->option_value,
            'sku'          => $this->sku,
            'stock'        => $this->whenNotNull($this->stock),
            'in_stock'     => $this->isInStock(),
            'sort_order'   => $this->sort_order,
        ];
    }
}
