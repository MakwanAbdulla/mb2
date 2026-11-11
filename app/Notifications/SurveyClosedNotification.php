<?php

namespace App\Notifications;

use App\Models\Survey\Survey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SurveyClosedNotification extends Notification implements ShouldQueue
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
        $count = $this->survey->submissions()->count();

        return (new MailMessage)
            ->subject('Enkäten "' . $this->survey->title . '" är nu stängd')
            ->line('Din enkät "' . $this->survey->title . '" har stängts.')
            ->line($count > 0 ? "Totalt inkom {$count} svar." : 'Inga svar inkom.')
            ->action('Se svar', route('admin.surveys.responses', $this->survey));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => 'Enkäten "' . $this->survey->title . '" har stängts.',
            'url'     => route('admin.surveys.responses', $this->survey),
            'icon'    => 'clipboard-check',
        ];
    }
}
