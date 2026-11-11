<?php

namespace App\Notifications;

use App\Mail\EventInvitationMail;
use App\Models\Events\EventInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class EventInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly EventInvitation $invitation) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->wantsEmailForNotifications()) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    public function toMail(object $notifiable): EventInvitationMail
    {
        return new EventInvitationMail($this->invitation);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => 'Du är inbjuden till ' . $this->invitation->event->title . '.',
            'url'     => route('events.show', $this->invitation->event->slug),
            'icon'    => 'calendar-event',
        ];
    }
}
