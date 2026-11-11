<?php

namespace App\Services\Accounting;

use App\Models\Accounting\AccountingInboxEmail;
use App\Models\Accounting\AccountingInboxItem;
use App\Models\Accounting\AccountingInboxSender;
use App\Models\InboxMailbox;
use App\Services\GoogleWorkspaceService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * Fetches unseen messages from the accounting inbox and imports attachments.
 *
 * Backend is selected automatically:
 *   • Gmail API  — when ACCOUNTING_INBOX_MAILBOX is set and Google Workspace is configured
 *   • IMAP       — when ACCOUNTING_INBOX_HOST + ACCOUNTING_INBOX_USERNAME are set
 *
 * Deduplication is done via SHA-256 hash of attachment content.
 */
class InboxMailFetcher
{
    private const ACCEPTED_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/tiff',
    ];

    /**
     * @return array{imported: int, needs_approval: int, skipped_duplicate: int, skipped_blocked: int, messages: int, emails_recorded: int}
     * @throws \RuntimeException on connection failure
     */
    private static function mailboxRecord(): ?InboxMailbox
    {
        return InboxMailbox::forPurpose(InboxMailbox::PURPOSE_ACCOUNTING);
    }

    public function fetch(): array
    {
        $mb = static::mailboxRecord();

        if (! $mb) {
            throw new \RuntimeException('No accounting inbox configured in inbox_mailboxes.');
        }

        return match ($mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => $this->fetchViaGmail($mb),
            default                     => $this->fetchViaImap($mb),
        };
    }

    public static function isConfigured(): bool
    {
        $mb = static::mailboxRecord();
        if (! $mb) {
            return false;
        }

        return match ($mb->driver) {
            InboxMailbox::DRIVER_GOOGLE => app(GoogleWorkspaceService::class)->isConfigured() && $mb->mailbox,
            InboxMailbox::DRIVER_IMAP   => ! empty($mb->imap_host) && ! empty($mb->imap_username),
            default                     => false,
        };
    }

    // ── Gmail backend ─────────────────────────────────────────────────────────

    private function fetchViaGmail(InboxMailbox $mb): array
    {
        $mailbox = $mb->mailbox;
        $ws      = app(GoogleWorkspaceService::class);

        $gmailIds = $ws->listInboxMessages($mailbox, 'is:unread', 200);

        if ($gmailIds === null) {
            throw new \RuntimeException("Failed to connect to Gmail API for mailbox {$mailbox}");
        }

        $imported       = 0;
        $needsApproval  = 0;
        $skippedDup     = 0;
        $skippedBlocked = 0;
        $emailsRecorded = 0;

        $activeSenders = AccountingInboxSender::active()->get();

        foreach ($gmailIds as $gmailId) {
            $msg = $ws->getInboxMessage($mailbox, $gmailId);
            if ($msg === null) {
                continue;
            }

            $senderEmail = $msg['from_email'];
            $senderName  = $msg['from_name'];
            $subject     = $msg['subject'];
            $receivedAt  = $msg['received_at'];
            $messageId   = $msg['message_id'];
            $bodyHtml    = $msg['body_html'];
            $bodyText    = $msg['body_text'];
            $attachments = $msg['attachments'];

            $senderRecord   = $activeSenders->first(fn ($s) => $s->matches($senderEmail));
            $classification = $senderRecord ? $senderRecord->type : 'unknown';

            $originalFromEmail = $this->extractOriginalSender($bodyHtml, $bodyText);
            $providerId        = null;

            if ($originalFromEmail) {
                $originalRecord = $activeSenders->first(fn ($s) => $s->matches($originalFromEmail));
                $providerId     = $originalRecord?->payment_provider_id;
            }

            $providerId ??= $senderRecord?->payment_provider_id;

            $emailRecord = $this->recordEmail(
                messageId:         $messageId,
                fromEmail:         $senderEmail,
                fromName:          $senderName,
                originalFromEmail: $originalFromEmail,
                subject:           $subject,
                receivedAt:        $receivedAt,
                classification:    $classification,
                bodyText:          $bodyText,
                bodyHtml:          $bodyHtml,
                hasAttachments:    ! empty($attachments),
            );

            if ($emailRecord !== null) {
                $emailsRecorded++;
            }

            if ($classification === AccountingInboxSender::TYPE_BLOCKED) {
                $ws->markInboxMessageRead($mailbox, $gmailId);
                $skippedBlocked++;
                continue;
            }

            if (empty($attachments)) {
                $ws->markInboxMessageRead($mailbox, $gmailId);
                continue;
            }

            $itemStatus = $classification === AccountingInboxSender::TYPE_ALLOWED
                ? AccountingInboxItem::STATUS_PENDING
                : AccountingInboxItem::STATUS_NEEDS_APPROVAL;

            foreach ($attachments as $attachment) {
                $mime = strtolower($attachment['mime_type']);

                if ($mime === 'application/octet-stream') {
                    $mime = $this->guessMimeFromFilename($attachment['filename']);
                }

                if (! \in_array($mime, self::ACCEPTED_TYPES, true)) {
                    continue;
                }

                $stored = $this->storeContent(
                    data:              $attachment['data'],
                    filename:          $attachment['filename'],
                    mime:              $mime,
                    status:            $itemStatus,
                    emailRecord:       $emailRecord,
                    fromEmail:         $senderEmail,
                    fromName:          $senderName,
                    subject:           $subject,
                    receivedAt:        $receivedAt,
                    emailMessageId:    $messageId,
                    providerId:        $providerId,
                    originalFromEmail: $originalFromEmail,
                );

                if ($stored) {
                    $needsApproval += ($itemStatus === AccountingInboxItem::STATUS_NEEDS_APPROVAL) ? 1 : 0;
                    $imported      += ($itemStatus === AccountingInboxItem::STATUS_PENDING) ? 1 : 0;
                } else {
                    $skippedDup++;
                }
            }

            $ws->markInboxMessageRead($mailbox, $gmailId);
        }

        return [
            'imported'          => $imported,
            'needs_approval'    => $needsApproval,
            'skipped_duplicate' => $skippedDup,
            'skipped_blocked'   => $skippedBlocked,
            'messages'          => \count($gmailIds),
            'emails_recorded'   => $emailsRecorded,
        ];
    }

    // ── IMAP backend ──────────────────────────────────────────────────────────

    private function fetchViaImap(InboxMailbox $mb): array
    {
        $client = $this->connectImap($mb);

        $folder   = $client->getFolder($mb->imap_folder);
        $messages = $folder->messages()->unseen()->get();

        $imported       = 0;
        $needsApproval  = 0;
        $skippedDup     = 0;
        $skippedBlocked = 0;
        $emailsRecorded = 0;

        $activeSenders = AccountingInboxSender::active()->get();

        foreach ($messages as $message) {
            $from        = $message->getFrom();
            $addr        = $from->first();
            $senderEmail = strtolower(trim($addr?->mail ?? ''));
            $senderName  = $addr?->personal ?: null;
            $subject     = $message->getSubject() ?: null;
            $receivedAt  = $message->getDate()?->first();
            $messageId   = $message->getMessageId() ?: null;
            $attachments = $message->getAttachments();
            $bodyHtml    = $message->getHtmlBody() ?: null;
            $bodyText    = $message->getTextBody() ?: null;

            if (! $bodyHtml && ! $bodyText) {
                $bodyHtml = $this->fetchImapBodyFallback($message) ?: null;
            }

            $senderRecord   = $activeSenders->first(fn ($s) => $s->matches($senderEmail));
            $classification = $senderRecord ? $senderRecord->type : 'unknown';

            $originalFromEmail = $this->extractOriginalSender($bodyHtml, $bodyText);
            $providerId        = null;

            if ($originalFromEmail) {
                $originalRecord = $activeSenders->first(fn ($s) => $s->matches($originalFromEmail));
                $providerId     = $originalRecord?->payment_provider_id;
            }

            $providerId ??= $senderRecord?->payment_provider_id;

            $emailRecord = $this->recordEmail(
                messageId:         $messageId,
                fromEmail:         $senderEmail,
                fromName:          $senderName,
                originalFromEmail: $originalFromEmail,
                subject:           $subject,
                receivedAt:        $receivedAt,
                classification:    $classification,
                bodyText:          $bodyText,
                bodyHtml:          $bodyHtml,
                hasAttachments:    ! $attachments->isEmpty(),
            );

            if ($emailRecord !== null) {
                $emailsRecorded++;
            }

            if ($classification === AccountingInboxSender::TYPE_BLOCKED) {
                $this->markImapProcessed($message);
                $skippedBlocked++;
                continue;
            }

            if ($attachments->isEmpty()) {
                $this->markImapProcessed($message);
                continue;
            }

            $itemStatus = $classification === AccountingInboxSender::TYPE_ALLOWED
                ? AccountingInboxItem::STATUS_PENDING
                : AccountingInboxItem::STATUS_NEEDS_APPROVAL;

            foreach ($attachments as $attachment) {
                $mime = $this->resolveImapMime($attachment);

                if (! \in_array($mime, self::ACCEPTED_TYPES, true)) {
                    continue;
                }

                $content = $attachment->getContent();
                $stored  = $this->storeContent(
                    data:              $content,
                    filename:          $attachment->getName() ?? 'document',
                    mime:              $mime,
                    status:            $itemStatus,
                    emailRecord:       $emailRecord,
                    fromEmail:         $senderEmail,
                    fromName:          $senderName,
                    subject:           $subject,
                    receivedAt:        $receivedAt,
                    emailMessageId:    $messageId,
                    providerId:        $providerId,
                    originalFromEmail: $originalFromEmail,
                );

                if ($stored) {
                    $needsApproval += ($itemStatus === AccountingInboxItem::STATUS_NEEDS_APPROVAL) ? 1 : 0;
                    $imported      += ($itemStatus === AccountingInboxItem::STATUS_PENDING) ? 1 : 0;
                } else {
                    $skippedDup++;
                }
            }

            $this->markImapProcessed($message);
        }

        $client->disconnect();

        return [
            'imported'          => $imported,
            'needs_approval'    => $needsApproval,
            'skipped_duplicate' => $skippedDup,
            'skipped_blocked'   => $skippedBlocked,
            'messages'          => $messages->count(),
            'emails_recorded'   => $emailsRecorded,
        ];
    }

    private function connectImap(InboxMailbox $mb): \Webklex\PHPIMAP\Client
    {
        return (new ClientManager())->make([
            'host'          => $mb->imap_host,
            'port'          => $mb->imap_port,
            'encryption'    => $mb->imap_encryption,
            'validate_cert' => true,
            'username'      => $mb->imap_username,
            'password'      => $mb->imap_password,
            'protocol'      => 'imap',
        ]);
    }

    private function markImapProcessed(Message $message): void
    {
        $message->setFlag('Seen');
    }

    private function resolveImapMime(Attachment $attachment): string
    {
        $mime = $attachment->getMimeType();

        if ($mime && $mime !== 'application/octet-stream') {
            return strtolower($mime);
        }

        return $this->guessMimeFromFilename($attachment->getName() ?? '');
    }

    private function fetchImapBodyFallback(Message $message): string
    {
        try {
            $raw = $message->getRawBody();
            if (! $raw) {
                return '';
            }

            if (str_contains($raw, '=3D') || str_contains($raw, '=\r\n') || str_contains($raw, '=\n')) {
                $raw = quoted_printable_decode($raw);
            }

            return $raw;
        } catch (\Throwable) {
            return '';
        }
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    private function recordEmail(
        ?string $messageId,
        string  $fromEmail,
        ?string $fromName,
        ?string $originalFromEmail,
        ?string $subject,
        mixed   $receivedAt,
        string  $classification,
        ?string $bodyText,
        ?string $bodyHtml,
        bool    $hasAttachments,
    ): ?AccountingInboxEmail {
        if ($messageId && AccountingInboxEmail::where('message_id', $messageId)->exists()) {
            return AccountingInboxEmail::where('message_id', $messageId)->first();
        }

        return AccountingInboxEmail::create([
            'message_id'            => $messageId,
            'from_email'            => $fromEmail,
            'from_name'             => $fromName,
            'original_from_email'   => $originalFromEmail,
            'subject'               => $subject,
            'body_text'             => $bodyText,
            'body_html'             => $bodyHtml,
            'received_at'           => $receivedAt,
            'sender_classification' => $classification,
            'has_attachments'       => $hasAttachments,
        ]);
    }

    private function storeContent(
        string               $data,
        string               $filename,
        string               $mime,
        string               $status,
        ?AccountingInboxEmail $emailRecord,
        string               $fromEmail,
        ?string              $fromName,
        ?string              $subject,
        mixed                $receivedAt,
        ?string              $emailMessageId,
        ?int                 $providerId,
        ?string              $originalFromEmail,
    ): bool {
        $hash = hash('sha256', $data);

        if (AccountingInboxItem::where('content_hash', $hash)->exists()) {
            return false;
        }

        $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) ?: 'bin';
        $path = 'accounting/inbox/' . now()->year . '/' . Str::uuid() . '.' . $ext;

        Storage::makeDirectory(dirname($path));
        Storage::put($path, $data);

        $extracted = [];
        if ($mime === 'application/pdf') {
            $extracted = (new PdfDataExtractor())->extract($path, $filename);
        }

        AccountingInboxItem::create([
            'accounting_inbox_email_id' => $emailRecord?->id,
            'from_email'                => $fromEmail,
            'from_name'                 => $fromName,
            'original_from_email'       => $originalFromEmail,
            'subject'                   => $subject,
            'received_at'               => $receivedAt,
            'email_message_id'          => $emailMessageId,
            'filename'                  => $filename,
            'path'                      => $path,
            'mime_type'                 => $mime,
            'size_bytes'                => \strlen($data),
            'content_hash'              => $hash,
            'status'                    => $status,
            'payment_provider_id'       => $providerId,
            ...$extracted,
        ]);

        return true;
    }

    private function guessMimeFromFilename(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf'         => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'tif', 'tiff' => 'image/tiff',
            default       => 'application/octet-stream',
        };
    }

    private function extractOriginalSender(?string $htmlBody, ?string $textBody): ?string
    {
        $candidates = [];

        if ($textBody) {
            $candidates[] = preg_replace('/\s+/', ' ', $textBody);
        }

        if ($htmlBody) {
            $candidates[] = preg_replace(
                '/\s+/', ' ',
                html_entity_decode(strip_tags($htmlBody), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            );
        }

        if ($htmlBody && str_contains($htmlBody, '=3D')) {
            $candidates[] = preg_replace(
                '/\s+/', ' ',
                html_entity_decode(strip_tags(quoted_printable_decode($htmlBody)), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            );
        }

        $separatorPattern = 'Ursprungligt\s+meddelande|Forwarded\s+message|Original\s+Message|Vidarebefordrat\s+meddelande';
        $emailPattern     = '[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}';

        $fullPattern = '/(?:' . $separatorPattern . ')'
            . '[\s\S]{0,600}?'
            . '(?:Fr[åa]n|From)\s*:\s*'
            . '(?:[^<@]{0,80}?<)?'
            . '(' . $emailPattern . ')'
            . '>?/iu';

        foreach ($candidates as $text) {
            if ($text && preg_match($fullPattern, $text, $matches)) {
                return strtolower(trim($matches[1]));
            }
        }

        if ($htmlBody && preg_match(
            '/(?:' . $separatorPattern . ')[\s\S]{0,600}?&lt;(' . $emailPattern . ')&gt;/iu',
            $htmlBody,
            $matches
        )) {
            return strtolower(trim($matches[1]));
        }

        return null;
    }
}
