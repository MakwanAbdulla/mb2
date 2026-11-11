<?php

namespace App\Events;

use App\Models\Signing\SigningRequest;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * All signers have signed and the signed PDF is stored. Modules that own the
 * request (meetings, agreements …) listen for this to act on their signable.
 */
class SigningRequestCompleted
{
    use Dispatchable;

    public function __construct(public readonly SigningRequest $signingRequest) {}
}
