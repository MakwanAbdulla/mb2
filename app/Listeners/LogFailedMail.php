<?php

namespace App\Listeners;

use App\Models\Content\MailLog;
use App\Models\Membership\Member;
use App\Models\User;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mime\Address;

class LogFailedMail
{
    public function handle(FailedMessageEvent $event): void
    {
        $message = $event->getMessage();
        $error   = $event->getError()->getMessage();

        if (! method_exists($message, 'getTo')) {
            return;
        }

        $toAddresses = $message->getTo();
        if (empty($toAddresses)) {
            return;
        }

        foreach ($toAddresses as $address) {
            /** @var Address $address */
            $email = $address->getAddress();
            $name  = $address->getName() ?: null;

            $user     = User::where('email', $email)->first();
            $userId   = $user?->id;
            $memberId = $user
                ? Member::where('user_id', $user->id)->value('id')
                : Member::where('email', $email)->value('id');

            MailLog::create([
                'recipient_email' => $email,
                'recipient_name'  => $name,
                'subject'         => method_exists($message, 'getSubject') ? ($message->getSubject() ?? '(ingen ämnesrad)') : '(okänt)',
                'user_id'         => $userId,
                'member_id'       => $memberId,
                'delivery_status' => MailLog::STATUS_FAILED,
                'delivery_error'  => $error,
            ]);
        }
    }
}
