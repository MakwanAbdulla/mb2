<?php

namespace App\Events;

use App\Models\Shop\Order;
use Illuminate\Foundation\Events\Dispatchable;

class OrderPaid
{
    use Dispatchable;

    public function __construct(public readonly Order $order) {}
}
