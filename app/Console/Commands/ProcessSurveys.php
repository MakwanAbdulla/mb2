<?php

namespace App\Console\Commands;

use App\Enums\SurveyStatus;
use App\Models\Survey\Survey;
use App\Notifications\SurveyClosedNotification;
use App\Notifications\SurveyInvitationNotification;
use App\Notifications\SurveyOpenedNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class ProcessSurveys extends Command
{
    protected $signature   = 'surveys:process';
    protected $description = 'Activates and closes scheduled surveys, and sends pending invitations';
    public function handle(): int
    {
        $this->activate();
        $this->close();
        $this->sendInvitations();

        return self::SUCCESS;
    }

    private function activate(): void
    {
        $surveys = Survey::where('status', SurveyStatus::Draft)
            ->whereNotNull('opens_at')
            ->where('opens_at', '<=', now())
            ->get();

        foreach ($surveys as $survey) {
            $survey->update(['status' => SurveyStatus::Active]);
            $survey->creator?->notify(new SurveyOpenedNotification($survey));
            $this->line("Activated survey: {$survey->title}");
        }
    }

    private function close(): void
    {
        $surveys = Survey::where('status', SurveyStatus::Active)
            ->whereNotNull('closes_at')
            ->where('closes_at', '<=', now())
            ->get();

        foreach ($surveys as $survey) {
            $survey->update(['status' => SurveyStatus::Closed]);
            $survey->creator?->notify(new SurveyClosedNotification($survey));
            $this->line("Closed survey: {$survey->title}");
        }
    }

    private function sendInvitations(): void
    {
        $surveys = Survey::where('status', SurveyStatus::Active)
            ->whereNotNull('send_invitations_at')
            ->where('send_invitations_at', '<=', now())
            ->get();

        foreach ($surveys as $survey) {
            $pending = $survey->invitations()->whereNull('sent_at')->get();

            foreach ($pending as $invitation) {
                Notification::route('mail', $invitation->email)
                    ->notify(new SurveyInvitationNotification($invitation));

                $invitation->update(['sent_at' => now()]);
            }

            if ($pending->isNotEmpty()) {
                $this->line("Sent {$pending->count()} invitations for: {$survey->title}");
            }
        }
    }
}
