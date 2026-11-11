<?php

namespace App\Mail;

use Google\Client;
use Google\Service\Gmail;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class GmailApiTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        $email = \Symfony\Component\Mime\MessageConverter::toEmail($message->getOriginalMessage());

        $fromAddresses = $email->getFrom();
        if (empty($fromAddresses)) {
            throw new \RuntimeException('GmailApiTransport: no From address on message.');
        }

        $mailbox = $fromAddresses[0]->getAddress();
        $gmail   = $this->buildClient($mailbox);

        if ($gmail === null) {
            throw new \RuntimeException("GmailApiTransport: could not build Gmail client for {$mailbox}.");
        }

        // Gmail API requires base64url (RFC 4648 §5), no padding.
        $raw = rtrim(strtr(base64_encode($message->toString()), '+/', '-_'), '=');

        $gmail->users_messages->send($mailbox, new \Google\Service\Gmail\Message(['raw' => $raw]));
    }

    private function buildClient(string $mailbox): ?Gmail
    {
        $credentials = config('services.google_workspace.credentials');

        if (empty($credentials)) {
            return null;
        }

        try {
            $credentialsArray = is_file($credentials)
                ? json_decode(file_get_contents($credentials), true)
                : json_decode($credentials, true);

            if (! is_array($credentialsArray)) {
                return null;
            }

            $client = new Client();
            $client->setAuthConfig($credentialsArray);
            $client->setSubject($mailbox);
            $client->addScope(Gmail::GMAIL_SEND);

            return new Gmail($client);
        } catch (\Exception $e) {
            Log::error('GmailApiTransport: client init failed', [
                'mailbox' => $mailbox,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function __toString(): string
    {
        return 'gmail-api://';
    }
}
