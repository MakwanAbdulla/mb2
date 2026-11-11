<?php

namespace App\Console\Commands;

use App\Mail\MailingMail;
use App\Models\AuditLog;
use App\Models\Content\Mailing;
use App\Models\Content\MailingRecipient;
use App\Models\Content\MailingCategoryOptout;
use App\Models\Content\MailingUnsubscribe;
use App\Models\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class ProcessMailings extends Command
{
    protected $signature   = 'mailings:process';
    protected $description = 'Activates due scheduled mailings and sends the next chunk for any "sending" mailing';

    public function handle(): int
    {
        $chunkSize = (int) SystemSetting::get('mailings.chunk_size', 50);

        $this->activateScheduled();

        $mailings = Mailing::where('status', Mailing::STATUS_SENDING)->get();

        foreach ($mailings as $mailing) {
            $this->processChunk($mailing, $chunkSize);
        }

        return self::SUCCESS;
    }

    private function activateScheduled(): void
    {
        $due = Mailing::where('status', Mailing::STATUS_SCHEDULED)
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($due as $mailing) {
            $mailing->update(['status' => Mailing::STATUS_SENDING]);
            $this->line("Mailing #{$mailing->id} \"{$mailing->title}\": activated from scheduled.");
        }
    }

    private function processChunk(Mailing $mailing, int $chunkSize): void
    {
        // Fetch unsubscribed emails once for this chunk pass
        $unsubscribed = MailingUnsubscribe::pluck('email')->map(fn ($e) => strtolower($e))->flip();

        $pending = MailingRecipient::where('mailing_id', $mailing->id)
            ->whereNull('sent_at')
            ->with('member')
            ->limit($chunkSize)
            ->get();

        if ($pending->isEmpty()) {
            $this->finalize($mailing);
            return;
        }

        $sent = 0;
        foreach ($pending as $recipient) {
            $emailLower = strtolower($recipient->email);

            $skipped = false;

            if ($unsubscribed->has($emailLower)) {
                $skipped = true;
            } elseif ($mailing->category && MailingCategoryOptout::hasOptedOut($emailLower, $mailing->category)) {
                $skipped = true;
            }

            if ($skipped) {
                $this->line("Skipping opted-out: {$recipient->email}");
                MailingRecipient::where('mailing_id', $mailing->id)
                    ->where('email', $recipient->email)
                    ->update(['sent_at' => now()]);
                continue;
            }

            try {
                Mail::to($recipient->email)->send(new MailingMail($mailing, $recipient));
            } catch (\Throwable $e) {
                // LogFailedMail listener records this; continue with next recipient
                $this->warn("Failed to send to {$recipient->email}: {$e->getMessage()}");
            }

            // Mark as attempted regardless — composite PK requires query builder
            MailingRecipient::where('mailing_id', $mailing->id)
                ->where('email', $recipient->email)
                ->update(['sent_at' => now()]);
            $sent++;
        }

        $this->line("Mailing #{$mailing->id} \"{$mailing->title}\": sent {$sent} in this chunk.");

        // Check if all done after this chunk
        $remaining = MailingRecipient::where('mailing_id', $mailing->id)
            ->whereNull('sent_at')
            ->exists();

        if (! $remaining) {
            $this->finalize($mailing);
        }
    }

    private function finalize(Mailing $mailing): void
    {
        $total = MailingRecipient::where('mailing_id', $mailing->id)->count();

        $mailing->update([
            'status'           => Mailing::STATUS_SENT,
            'sent_at'          => now(),
            'recipients_count' => $total,
        ]);

        AuditLog::record(
            action:    'mailing_sent',
            area:      'mailings',
            subject:   "{$mailing->title} ({$total} mottagare)",
            auditable: $mailing,
        );

        $this->line("Mailing #{$mailing->id} finalized. Total: {$total}.");
    }
}
