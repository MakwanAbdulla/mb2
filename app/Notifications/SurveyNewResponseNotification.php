<?php

namespace App\Notifications;

use App\Models\Form\FormSubmission;
use App\Models\Survey\Survey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SurveyNewResponseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Survey         $survey,
        public readonly FormSubmission $submission,
    ) {}

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
            ->subject('Nytt svar på enkäten "' . $this->survey->title . '"')
            ->line('Ett nytt svar har inkommit på enkäten "' . $this->survey->title . '".')
            ->action('Se svar', route('admin.surveys.responses', $this->survey));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => 'Nytt svar på enkäten "' . $this->survey->title . '".',
            'url'     => route('admin.surveys.responses', $this->survey),
            'icon'    => 'clipboard-check',
        ];
    }
}
