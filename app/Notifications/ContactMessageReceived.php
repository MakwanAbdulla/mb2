<?php

namespace App\Notifications;

use App\Models\ContactMessage;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContactMessageReceived extends Notification
{
    public function __construct(public ContactMessage $message) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        // The contact page sends to Notification::route('mail', …) — an anonymous
        // route with no user account, so mail is the only channel available.
        if ($notifiable instanceof AnonymousNotifiable) {
            return ['mail'];
        }

        $channels = ['database'];
        if ($notifiable->wantsEmailForNotifications()) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('[Armagedon] '.$this->message->subject)
            ->replyTo($this->message->email, $this->message->name)
            ->line("**Från:** {$this->message->name} ({$this->message->email})");

        if ($this->message->phone) {
            $mail->line("**Telefon:** {$this->message->phone}");
        }

        return $mail
            ->line("**Ämne:** {$this->message->subject}")
            ->line('---')
            ->line($this->message->message)
            ->action('Hantera meddelande', route('admin.contact.show', $this->message));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => 'Nytt kontaktmeddelande från '.$this->message->name.': '.$this->message->subject,
            'url' => route('admin.contact.show', $this->message),
            'icon' => 'envelope',
        ];
    }
}
