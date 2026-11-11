<?php

namespace App\Notifications;

use App\Models\Survey\Survey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SurveyOpenedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Survey $survey) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->wantsEmailForNotifications()) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Enkäten "' . $this->survey->title . '" är nu öppen')
            ->line('Din enkät "' . $this->survey->title . '" har öppnats och tar nu emot svar.')
            ->action('Se inbjudningar', route('admin.surveys.invitations', $this->survey));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => 'Enkäten "' . $this->survey->title . '" är nu öppen för svar.',
            'url'     => route('admin.surveys.invitations', $this->survey),
            'icon'    => 'clipboard-check',
        ];
    }
}
