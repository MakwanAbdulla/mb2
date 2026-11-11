<?php

namespace App\Services;

use App\Models\InboxMailbox;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Webklex\PHPIMAP\ClientManager;

/**
 * Reads and triages a single inbox mailbox.
 *
 * Obtain an instance via the static factory methods:
 *   InboxReaderService::forPurpose('office')
 *   InboxReaderService::forOwner($section)
 *   InboxReaderService::forOwner($orgRole)
 *   InboxReaderService::forOwner($user)
 *   new InboxReaderService($mailboxRecord)
 */
class InboxReaderService
{
    public function __construct(private readonly InboxMailbox $mb) {}

    // ── Factory methods ───────────────────────────────────────────────────────

    public static function forPurpose(string $purpose): ?self
    {
        $mb = InboxMailbox::forPurpose($purpose);
        return $mb ? new self($mb) : null;
    }

    public static function forOwner(object $owner): ?self
    {
        $mb = InboxMailbox::forOwner($owner);
        return $mb ? new self($mb) : null;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    public function isConfigured(): bool
    {
        return match ($this->mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => app(GoogleWorkspaceService::class)->isConfigured() && $this->mb->mailbox,
            InboxMailbox::DRIVER_IMAP   => ! empty($this->mb->imap_host) && ! empty($this->mb->imap_username),
            default                     => false,
        };
    }

    public function mailbox(): string
    {
        return $this->mb->address();
    }

    public function record(): InboxMailbox
    {
        return $this->mb;
    }

    /**
     * @return array<int, array{id:string, from_email:string, from_name:string|null, subject:string|null, snippet:string, received_at:\Illuminate\Support\Carbon}>|null
     */
    public function listUnread(int $max = 50): ?array
    {
        return match ($this->mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => $this->gmailListUnread($max),
            InboxMailbox::DRIVER_IMAP   => $this->imapListUnread($max),
            default                     => null,
        };
    }

    public function getMessage(string $id): ?array
    {
        return match ($this->mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => $this->gmailGetMessage($id),
            InboxMailbox::DRIVER_IMAP   => $this->imapGetMessage($id),
            default                     => null,
        };
    }

    public function markRead(string $id): void
    {
        match ($this->mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => $this->gmailMarkRead($id),
            InboxMailbox::DRIVER_IMAP   => $this->imapMarkRead($id),
            default                     => null,
        };
    }

    public function trash(string $id): void
    {
        match ($this->mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => $this->gmailTrash($id),
            InboxMailbox::DRIVER_IMAP   => $this->imapTrash($id),
            default                     => null,
        };
    }

    public function reply(string $_id, string $threadId, string $to, string $subject, string $body, ?string $inReplyTo): bool
    {
        return match ($this->mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => $this->gmailReply($threadId, $to, $subject, $body, $inReplyTo),
            InboxMailbox::DRIVER_IMAP   => $this->imapReply($to, $subject, $body, $inReplyTo),
            default                     => false,
        };
    }

    /**
     * @return array{messages: array, nextPageToken: string|null}
     */
    public function listMessages(string $view = 'unread', int $max = 50, ?string $pageToken = null): array
    {
        return match ($this->mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => $this->gmailListMessages($view, $max, $pageToken),
            InboxMailbox::DRIVER_IMAP   => $this->imapListMessages($view, $max),
            default                     => ['messages' => [], 'nextPageToken' => null],
        };
    }

    /** @return array<int, array> */
    public function getThread(string $threadId): array
    {
        return match ($this->mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => app(GoogleWorkspaceService::class)->getInboxThread($this->mb->mailbox, $threadId) ?? [],
            default                     => [],
        };
    }

    /** @return array{messages: array, nextPageToken: string|null} */
    public function search(string $query, int $max = 50, ?string $pageToken = null): array
    {
        return match ($this->mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => app(GoogleWorkspaceService::class)->listInboxMessagesPaginated(
                $this->mb->mailbox, $query, $max, $pageToken
            ),
            InboxMailbox::DRIVER_IMAP => $this->imapListMessages('all', $max),
            default                   => ['messages' => [], 'nextPageToken' => null],
        };
    }

    public function markUnread(string $id): void
    {
        match ($this->mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => app(GoogleWorkspaceService::class)->markInboxMessageUnread($this->mb->mailbox, $id),
            InboxMailbox::DRIVER_IMAP   => $this->imapMarkUnread($id),
            default                     => null,
        };
    }

    public function sendNew(
        string  $to,
        ?string $cc,
        ?string $bcc,
        string  $subject,
        string  $body,
        bool    $isHtml,
        array   $attachmentFiles = [],
    ): bool {
        return match ($this->mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => app(GoogleWorkspaceService::class)->sendNewMessage(
                $this->mb->mailbox,
                $this->rewriteRecipients($to),
                $this->rewriteRecipients($cc),
                $this->rewriteRecipients($bcc),
                $subject, $body, $isHtml, $attachmentFiles
            ),
            InboxMailbox::DRIVER_IMAP => $this->imapSendNew($to, $cc, $subject, $body, $isHtml),
            default                   => false,
        };
    }

    public function replyExtended(
        string  $threadId,
        string  $to,
        ?string $cc,
        string  $subject,
        string  $body,
        bool    $isHtml,
        ?string $inReplyTo,
        array   $attachmentFiles = [],
    ): bool {
        return match ($this->mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => app(GoogleWorkspaceService::class)->sendReplyExtended(
                $this->mb->mailbox,
                $this->rewriteRecipients($to),
                $this->rewriteRecipients($cc),
                $subject, $body, $isHtml, $threadId, $inReplyTo, $attachmentFiles
            ),
            InboxMailbox::DRIVER_IMAP => $this->imapReply($to, $subject, $body, $inReplyTo),
            default                   => false,
        };
    }

    public function forward(string $id, string $to, ?string $cc, string $note): bool
    {
        return match ($this->mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => app(GoogleWorkspaceService::class)->forwardInboxMessage(
                $this->mb->mailbox, $id,
                $this->rewriteRecipients($to),
                $this->rewriteRecipients($cc),
                $note
            ),
            InboxMailbox::DRIVER_IMAP => false,
            default                   => false,
        };
    }

    /**
     * Rewrites @{from} → @{to} in a comma-separated address string.
     * Controlled by system setting 'mail.domain_rewrite' (JSON: {from, to}).
     * Remove the setting when MX records are pointed to Google Workspace.
     */
    private function rewriteRecipients(?string $addresses): ?string
    {
        if (! $addresses) {
            return $addresses;
        }

        $rule = SystemSetting::get('mail.domain_rewrite');
        if (! $rule || empty($rule['from']) || empty($rule['to'])) {
            return $addresses;
        }

        $from = preg_quote($rule['from'], '/');
        return preg_replace('/@' . $from . '\b/i', '@' . $rule['to'], $addresses);
    }

    /** @return array{filename: string, mime_type: string, data: string}|null */
    public function getAttachment(string $messageId, string $attachmentId): ?array
    {
        return match ($this->mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => app(GoogleWorkspaceService::class)->getAttachmentDownload(
                $this->mb->mailbox, $messageId, $attachmentId
            ),
            InboxMailbox::DRIVER_IMAP => $this->imapGetAttachment($messageId, $attachmentId),
            default                   => null,
        };
    }

    public function listDrafts(): array
    {
        return match ($this->mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => app(GoogleWorkspaceService::class)->listInboxDrafts($this->mb->mailbox),
            default                     => [],
        };
    }

    public function getDraft(string $draftId): ?array
    {
        return match ($this->mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => app(GoogleWorkspaceService::class)->getInboxDraft($this->mb->mailbox, $draftId),
            default                     => null,
        };
    }

    public function saveDraft(
        string  $to,
        ?string $cc,
        ?string $bcc,
        string  $subject,
        string  $body,
        bool    $isHtml,
        array   $attachmentFiles = [],
        ?string $existingDraftId = null,
        ?string $threadId = null,
        ?string $inReplyTo = null,
    ): ?string {
        return match ($this->mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => app(GoogleWorkspaceService::class)->saveInboxDraft(
                $this->mb->mailbox, $to, $cc, $bcc, $subject, $body, $isHtml,
                $attachmentFiles, $existingDraftId, $threadId, $inReplyTo
            ),
            default => null,
        };
    }

    public function sendDraft(string $draftId): bool
    {
        return match ($this->mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => app(GoogleWorkspaceService::class)->sendInboxDraft($this->mb->mailbox, $draftId),
            default                     => false,
        };
    }

    public function deleteDraft(string $draftId): bool
    {
        return match ($this->mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => app(GoogleWorkspaceService::class)->deleteInboxDraft($this->mb->mailbox, $draftId),
            default                     => false,
        };
    }

    public function addTriageLabel(string $messageId): void
    {
        if ($this->mb->driver !== InboxMailbox::DRIVER_GOOGLE || ! $this->mb->mailbox) {
            return;
        }
        app(GoogleWorkspaceService::class)->addGmailLabel($this->mb->mailbox, $messageId, 'Triagerat');
    }

    public function unreadCount(): int
    {
        $result = $this->listMessages('unread', 1);
        // For a proper count we need a separate call; this is a rough estimate
        return match ($this->mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => $this->gmailUnreadCount(),
            default                     => count($result['messages']),
        };
    }

    // ── Gmail backend ─────────────────────────────────────────────────────────

    private function gmailListMessages(string $view, int $max, ?string $pageToken): array
    {
        $query = match ($view) {
            'all'   => 'in:inbox',
            'sent'  => 'in:sent',
            'trash' => 'in:trash',
            default => 'is:unread in:inbox',
        };

        return app(GoogleWorkspaceService::class)->listInboxMessagesPaginated(
            $this->mb->mailbox, $query, $max, $pageToken
        );
    }

    private function gmailUnreadCount(): int
    {
        try {
            $ws       = app(GoogleWorkspaceService::class);
            $gmail    = $ws->getGmailRwClient($this->mb->mailbox);
            if ($gmail === null) {
                return 0;
            }
            $response = $gmail->users_labels->get($this->mb->mailbox, 'INBOX');
            return (int) ($response->getMessagesUnread() ?? 0);
        } catch (\Exception) {
            return 0;
        }
    }

    private function gmailListUnread(int $max): ?array
    {
        $ws  = app(GoogleWorkspaceService::class);
        $ids = $ws->listInboxMessages($this->mb->mailbox, 'is:unread', $max);

        if ($ids === null) {
            return null;
        }

        $results = [];
        foreach ($ids as $gmailId) {
            $msg = $ws->getInboxMessage($this->mb->mailbox, $gmailId);
            if ($msg) {
                $results[] = $msg;
            }
        }

        usort($results, fn ($a, $b) => $b['received_at']->timestamp <=> $a['received_at']->timestamp);

        return $results;
    }

    private function gmailGetMessage(string $id): ?array
    {
        return app(GoogleWorkspaceService::class)->getInboxMessage($this->mb->mailbox, $id);
    }

    private function gmailMarkRead(string $id): void
    {
        app(GoogleWorkspaceService::class)->markInboxMessageRead($this->mb->mailbox, $id);
    }

    private function gmailTrash(string $id): void
    {
        app(GoogleWorkspaceService::class)->trashInboxMessage($this->mb->mailbox, $id);
    }

    private function gmailReply(string $threadId, string $to, string $subject, string $body, ?string $inReplyTo): bool
    {
        return app(GoogleWorkspaceService::class)->sendReply(
            mailbox:   $this->mb->mailbox,
            to:        $to,
            subject:   $subject,
            body:      $body,
            threadId:  $threadId,
            inReplyTo: $inReplyTo,
        );
    }

    // ── IMAP backend ──────────────────────────────────────────────────────────

    private function imapListUnread(int $max): ?array
    {
        try {
            $client   = $this->imapConnect();
            $folder   = $client->getFolder($this->mb->imap_folder);
            $messages = $folder->messages()->unseen()->get();
            $results  = [];
            $count    = 0;

            foreach ($messages as $msg) {
                if ($count++ >= $max) {
                    break;
                }

                $from  = $msg->getFrom()->first();
                $results[] = [
                    'id'          => (string) $msg->getUid(),
                    'gmail_id'    => (string) $msg->getUid(),
                    'thread_id'   => (string) $msg->getUid(),
                    'message_id'  => $msg->getMessageId() ?: null,
                    'from_email'  => strtolower(trim($from?->mail ?? '')),
                    'from_name'   => $from?->personal ?: null,
                    'to'          => null,
                    'reply_to'    => null,
                    'subject'     => $msg->getSubject() ?: null,
                    'received_at' => \Illuminate\Support\Carbon::parse($msg->getDate()?->first()),
                    'labels'      => [],
                    'snippet'     => mb_substr(strip_tags($msg->getTextBody() ?? $msg->getHtmlBody() ?? ''), 0, 200),
                    'body_text'   => null,
                    'body_html'   => null,
                    'attachments' => [],
                ];
            }

            $client->disconnect();
            usort($results, fn ($a, $b) => $b['received_at']->timestamp <=> $a['received_at']->timestamp);

            return $results;
        } catch (\Exception $e) {
            Log::error('InboxReaderService IMAP listUnread failed', ['mailbox' => $this->mb->id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function imapGetMessage(string $uid): ?array
    {
        try {
            $client  = $this->imapConnect();
            $folder  = $client->getFolder($this->mb->imap_folder);
            $message = $folder->messages()->getByUid($uid)->first();

            if (! $message) {
                return null;
            }

            $from     = $message->getFrom()->first();
            $bodyHtml = $message->getHtmlBody() ?: null;
            $bodyText = $message->getTextBody() ?: null;

            $attachments = [];
            foreach ($message->getAttachments() as $att) {
                $attachments[] = [
                    'filename'      => $att->getName() ?? 'attachment',
                    'mime_type'     => $att->getMimeType() ?? 'application/octet-stream',
                    'attachment_id' => null,
                ];
            }

            $client->disconnect();

            return [
                'id'          => $uid,
                'gmail_id'    => $uid,
                'thread_id'   => $uid,
                'message_id'  => $message->getMessageId() ?: null,
                'from_email'  => strtolower(trim($from?->mail ?? '')),
                'from_name'   => $from?->personal ?: null,
                'to'          => null,
                'reply_to'    => null,
                'subject'     => $message->getSubject() ?: null,
                'received_at' => \Illuminate\Support\Carbon::parse($message->getDate()?->first()),
                'labels'      => [],
                'snippet'     => mb_substr(strip_tags($bodyText ?? strip_tags($bodyHtml ?? '')), 0, 200),
                'body_text'   => $bodyText,
                'body_html'   => $bodyHtml,
                'attachments' => $attachments,
            ];
        } catch (\Exception $e) {
            Log::error('InboxReaderService IMAP getMessage failed', ['mailbox' => $this->mb->id, 'uid' => $uid, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function imapMarkRead(string $uid): void
    {
        try {
            $client = $this->imapConnect();
            $folder = $client->getFolder($this->mb->imap_folder);
            $folder->messages()->getByUid($uid)->first()?->setFlag('Seen');
            $client->disconnect();
        } catch (\Exception $e) {
            Log::error('InboxReaderService IMAP markRead failed', ['mailbox' => $this->mb->id, 'uid' => $uid, 'error' => $e->getMessage()]);
        }
    }

    private function imapTrash(string $uid): void
    {
        try {
            $client = $this->imapConnect();
            $folder = $client->getFolder($this->mb->imap_folder);
            $folder->messages()->getByUid($uid)->first()?->delete(true);
            $client->disconnect();
        } catch (\Exception $e) {
            Log::error('InboxReaderService IMAP trash failed', ['mailbox' => $this->mb->id, 'uid' => $uid, 'error' => $e->getMessage()]);
        }
    }

    private function imapReply(string $to, string $subject, string $body, ?string $inReplyTo): bool
    {
        try {
            $from = $this->mb->imap_username;

            if ($this->mb->smtp_host) {
                $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
                    $this->mb->smtp_host,
                    $this->mb->smtp_port,
                );
                $transport->setUsername($this->mb->smtp_username ?? $from);
                $transport->setPassword($this->mb->smtp_password ?? '');

                $email = (new \Symfony\Component\Mime\Email())
                    ->from($from)
                    ->to($to)
                    ->subject($subject)
                    ->text($body);

                if ($inReplyTo) {
                    $email->getHeaders()->addTextHeader('In-Reply-To', $inReplyTo);
                    $email->getHeaders()->addTextHeader('References', $inReplyTo);
                }

                (new \Symfony\Component\Mailer\Mailer($transport))->send($email);
            } else {
                Mail::raw($body, function ($msg) use ($from, $to, $subject, $inReplyTo) {
                    $msg->from($from)->to($to)->subject($subject);
                    if ($inReplyTo) {
                        $msg->getHeaders()->addTextHeader('In-Reply-To', $inReplyTo);
                        $msg->getHeaders()->addTextHeader('References', $inReplyTo);
                    }
                });
            }

            return true;
        } catch (\Exception $e) {
            Log::error('InboxReaderService IMAP reply failed', ['mailbox' => $this->mb->id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function imapListMessages(string $view, int $max): array
    {
        try {
            $client = $this->imapConnect();
            $folder = $view === 'sent'
                ? ($client->getFolder('SENT') ?? $client->getFolder('Sent'))
                : $client->getFolder($this->mb->imap_folder);

            if (! $folder) {
                $client->disconnect();
                return ['messages' => [], 'nextPageToken' => null];
            }

            $query    = $view === 'unread' ? $folder->messages()->unseen() : $folder->messages()->all();
            $messages = $query->get();
            $results  = [];
            $count    = 0;

            foreach ($messages as $msg) {
                if ($count++ >= $max) {
                    break;
                }
                $from      = $msg->getFrom()->first();
                $results[] = [
                    'id'          => (string) $msg->getUid(),
                    'gmail_id'    => (string) $msg->getUid(),
                    'thread_id'   => (string) $msg->getUid(),
                    'message_id'  => $msg->getMessageId() ?: null,
                    'from_email'  => strtolower(trim($from?->mail ?? '')),
                    'from_name'   => $from?->personal ?: null,
                    'to'          => null,
                    'reply_to'    => null,
                    'subject'     => $msg->getSubject() ?: null,
                    'received_at' => \Illuminate\Support\Carbon::parse($msg->getDate()?->first()),
                    'labels'      => [],
                    'snippet'     => mb_substr(strip_tags($msg->getTextBody() ?? $msg->getHtmlBody() ?? ''), 0, 200),
                    'body_text'   => null,
                    'body_html'   => null,
                    'attachments' => [],
                ];
            }

            $client->disconnect();
            usort($results, fn ($a, $b) => $b['received_at']->timestamp <=> $a['received_at']->timestamp);

            return ['messages' => $results, 'nextPageToken' => null];
        } catch (\Exception $e) {
            Log::error('InboxReaderService IMAP listMessages failed', ['mailbox' => $this->mb->id, 'error' => $e->getMessage()]);
            return ['messages' => [], 'nextPageToken' => null];
        }
    }

    private function imapMarkUnread(string $uid): void
    {
        try {
            $client = $this->imapConnect();
            $folder = $client->getFolder($this->mb->imap_folder);
            $folder->messages()->getByUid($uid)->first()?->clearFlag('Seen');
            $client->disconnect();
        } catch (\Exception $e) {
            Log::error('InboxReaderService IMAP markUnread failed', ['mailbox' => $this->mb->id, 'uid' => $uid, 'error' => $e->getMessage()]);
        }
    }

    private function imapSendNew(string $to, ?string $cc, string $subject, string $body, bool $isHtml): bool
    {
        try {
            $from  = $this->mb->imap_username;
            $email = (new \Symfony\Component\Mime\Email())
                ->from($from)
                ->subject($subject);

            foreach (array_filter(array_map('trim', explode(',', $to))) as $addr) {
                $email->addTo($addr);
            }
            if ($cc) {
                foreach (array_filter(array_map('trim', explode(',', $cc))) as $addr) {
                    $email->addCc($addr);
                }
            }
            $isHtml ? $email->html($body)->text(strip_tags($body)) : $email->text($body);

            if ($this->mb->smtp_host) {
                $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
                    $this->mb->smtp_host,
                    $this->mb->smtp_port,
                );
                $transport->setUsername($this->mb->smtp_username ?? $from);
                $transport->setPassword($this->mb->smtp_password ?? '');
                (new \Symfony\Component\Mailer\Mailer($transport))->send($email);
            } else {
                Mail::send([], [], fn ($m) => $m
                    ->from($from)->to($to)->subject($subject)
                    ->setBody($body, $isHtml ? 'text/html' : 'text/plain'));
            }

            return true;
        } catch (\Exception $e) {
            Log::error('InboxReaderService IMAP sendNew failed', ['mailbox' => $this->mb->id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** For IMAP we re-fetch the message and return attachment by index encoded as "{uid}:{index}". */
    private function imapGetAttachment(string $uid, string $attachmentId): ?array
    {
        // IMAP attachment_id is always null (set in imapGetMessage), so nothing to download
        return null;
    }

    private function imapConnect(): \Webklex\PHPIMAP\Client
    {
        return (new ClientManager())->make([
            'host'          => $this->mb->imap_host,
            'port'          => $this->mb->imap_port,
            'encryption'    => $this->mb->imap_encryption,
            'validate_cert' => true,
            'username'      => $this->mb->imap_username,
            'password'      => $this->mb->imap_password,
            'protocol'      => 'imap',
        ]);
    }
}
