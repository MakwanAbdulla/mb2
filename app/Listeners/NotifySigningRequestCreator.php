<?php

namespace App\Listeners;

use App\Events\SigningRequestCompleted;
use App\Notifications\SigningRequestCompletedNotification;

class NotifySigningRequestCreator
{
    public function handle(SigningRequestCompleted $event): void
    {
        $event->signingRequest->creator?->notify(new SigningRequestCompletedNotification($event->signingRequest));
    }
}
