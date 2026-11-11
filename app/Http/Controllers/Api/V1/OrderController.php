<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Shop\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class OrderController extends Controller
{
    /**
     * List own orders (paginated).
     */
    public function index(Request $request): ResourceCollection
    {
        $orders = Order::where('user_id', $request->user()->id)
            ->latest()
            ->paginate($request->integer('per_page', 25));

        return OrderResource::collection($orders);
    }

    /**
     * Show a single order with its lines.
     * Members can only view their own; admins can view any.
     */
    public function show(Request $request, Order $order): OrderResource
    {
        if (! $request->user()->hasPermissionTo('members.manage') && $order->user_id !== $request->user()->id) {
            abort(403);
        }

        $order->load('lines');

        return new OrderResource($order);
    }
}
