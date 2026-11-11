<?php

namespace App\Http\Resources;

use App\Models\Shop\CartItem;
use App\Models\Events\EventPriceTier;
use App\Models\Shop\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cartable = $this->cartable;

        $cartableData = null;

        if ($cartable instanceof ProductVariant) {
            $cartableData = [
                'type'         => 'product_variant',
                'id'           => $cartable->id,
                'product_name' => $cartable->product?->name,
                'product_slug' => $cartable->product?->slug,
                'option_value' => $cartable->option_value,
            ];
        } elseif ($cartable instanceof EventPriceTier) {
            $cartableData = [
                'type'       => 'event_price_tier',
                'id'         => $cartable->id,
                'event_id'   => $cartable->event_id,
                'tier_label' => $cartable->label,
            ];
        }

        return [
            'id'                  => $this->id,
            'cartable_type'       => $this->cartable_type,
            'cartable_id'         => $this->cartable_id,
            'cartable'            => $cartableData,
            'label'               => $this->displayLabel(),
            'quantity'            => $this->quantity,
            'unit_price_ore'      => $this->unit_price_ore,
            'unit_price_formatted'=> number_format($this->unit_price_ore / 100, 2, ',', ' ') . ' kr',
            'line_total_ore'      => $this->lineTotalOre(),
            'line_total_formatted'=> number_format($this->lineTotalOre() / 100, 2, ',', ' ') . ' kr',
            'meta'                => $this->meta,
        ];
    }
}
