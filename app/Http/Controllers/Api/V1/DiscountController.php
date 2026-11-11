<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shop\Discount;
use App\Models\Shop\DiscountCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    /**
     * POST /api/v1/discounts/validate
     *
     * Validate a discount code and return calculated discount for a given subtotal.
     *
     * Request body:
     *   code        string  required
     *   subtotal    int     required  (in öre)
     *   applies_to  string  optional  (membership|event|shop|all)
     */
    public function validate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'       => 'required|string|max:100',
            'subtotal'   => 'required|integer|min:0',
            'applies_to' => 'nullable|string|in:membership,event,shop,all',
        ]);

        $discountCode = DiscountCode::with('discount')
            ->where('code', $data['code'])
            ->first();

        if (! $discountCode) {
            return response()->json(['message' => 'Ogiltig rabattkod.'], 422);
        }

        $discount = $discountCode->discount;

        if (! $discount->isValid()) {
            return response()->json(['message' => 'Rabattkoden är inte längre giltig.'], 422);
        }

        if ($data['applies_to'] ?? null) {
            $appliesTo = $discount->applies_to;
            if ($appliesTo !== Discount::APPLIES_ALL && $appliesTo !== $data['applies_to']) {
                return response()->json(['message' => 'Rabattkoden gäller inte för denna kategori.'], 422);
            }
        }

        $member   = auth()->user()?->member;
        if (! $discount->eligibleFor($member)) {
            return response()->json(['message' => 'Du uppfyller inte villkoren för denna rabattkod.'], 422);
        }

        $discountOre = $discount->calculate($data['subtotal']);

        return response()->json([
            'code'               => $discountCode->code,
            'discount_id'        => $discount->id,
            'type'               => $discount->type,
            'value'              => $discount->value,
            'applies_to'         => $discount->applies_to,
            'discount_ore'       => $discountOre,
            'discount_formatted' => number_format($discountOre / 100, 2, ',', ' ') . ' kr',
        ]);
    }
}
