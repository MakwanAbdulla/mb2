<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
    protected function buildMailMessage($url): MailMessage
    {
        $expire = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Återställ ditt lösenord')
            ->line('Du får detta e-postmeddelande eftersom vi har tagit emot en begäran om att återställa lösenordet för ditt konto.')
            ->action('Återställ lösenord', $url)
            ->line("Länken för lösenordsåterställning upphör att gälla om {$expire} minuter.")
            ->line('Om du inte begärt någon lösenordsåterställning behöver du inte göra något.');
    }
}
