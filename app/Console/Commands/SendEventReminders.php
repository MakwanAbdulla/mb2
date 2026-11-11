<?php

namespace App\Console\Commands;

use App\Mail\EventRegistrationMail;
use App\Models\Events\Event;
use App\Models\Events\EventOccurrence;
use App\Models\Events\EventRegistration;
use App\Models\Content\NotificationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendEventReminders extends Command
{
    protected $signature   = 'events:send-reminders {--dry-run : List would-be recipients without sending}';
    protected $description = 'Send reminder emails for confirmed event registrations before each occurrence';

    /**
     * Half-width of the matching window in minutes.
     * The scheduler entry must run at least this often (hourly recommended).
     */
    private const WINDOW_MINUTES = 30;

    public function handle(): int
    {
        $sent = 0;

        Event::where('status', Event::STATUS_PUBLISHED)
            ->whereNotNull('reminder_hours_before')
            ->with('occurrences')
            ->each(function (Event $event) use (&$sent) {
                $hours  = (int) $event->reminder_hours_before;
                $target = now()->addHours($hours);
                $from   = $target->copy()->subMinutes(self::WINDOW_MINUTES);
                $to     = $target->copy()->addMinutes(self::WINDOW_MINUTES);

                $event->occurrences
                    ->filter(fn (EventOccurrence $o) => $o->starts_at->between($from, $to))
                    ->each(function (EventOccurrence $occurrence) use ($event, &$sent) {
                        $this->sendForOccurrence($event, $occurrence, $sent);
                    });
            });

        $this->info("Klart – skickade {$sent} påminnelse(r).");

        return self::SUCCESS;
    }

    private function sendForOccurrence(Event $event, EventOccurrence $occurrence, int &$sent): void
    {
        $notifType = 'event_reminder_occ_' . $occurrence->id;

        EventRegistration::where('event_id', $event->id)
            ->where('status', EventRegistration::STATUS_CONFIRMED)
            ->with(['event.occurrences', 'priceTier', 'user'])
            ->each(function (EventRegistration $reg) use ($notifType, $occurrence, &$sent) {
                $email = $reg->user?->email ?? $reg->guest_email;

                if (! $email) {
                    return;
                }

                if (NotificationLog::alreadySent($reg, $notifType)) {
                    $this->line("  Hoppar över #{$reg->id} – påminnelse redan skickad");
                    return;
                }

                if ($this->option('dry-run')) {
                    $this->line("  [dry-run] Skulle skicka påminnelse till {$email}"
                        . " för {$reg->event->title} ({$occurrence->starts_at->format('d/m Y H:i')})");
                    return;
                }

                Mail::to($email)->queue(new EventRegistrationMail($reg, 'event_reminder'));
                NotificationLog::record($reg, $notifType, $email);

                $this->line("  Skickade påminnelse till {$email} för {$reg->event->title}");
                $sent++;
            });
    }
}
