<?php

namespace App\Mail\Concerns;

use Illuminate\Support\Str;

/**
 * Generates a unique tracking token for each sent email.
 * The token is embedded as a 1×1 pixel in the template and later used
 * to record opens in the mail_log table.
 *
 * Usage: add `use WithMailTracking;` to a Mailable and call
 *        `$this->initTracking()` in the constructor.
 */
trait WithMailTracking
{
    public string $trackingToken;

    protected function initTracking(): void
    {
        $this->trackingToken = Str::uuid()->toString();
    }
}
