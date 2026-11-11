<?php

namespace App\Events;

use App\Models\Shop\Order;
use Illuminate\Foundation\Events\Dispatchable;

class OrderPaymentFailed
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
        public readonly string $reason,
    ) {}
}
