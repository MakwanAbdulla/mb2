<?php

namespace App\Notifications;

use App\Mail\SurveyInvitationMail;
use App\Models\Survey\SurveyInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SurveyInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly SurveyInvitation $invitation) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        // Sent via Notification::route('mail', $invitation->email) — an anonymous
        // route with no user account, so mail is the only channel available.
        return ['mail'];
    }

    public function toMail(object $notifiable): SurveyInvitationMail
    {
        return new SurveyInvitationMail($this->invitation);
    }
}
