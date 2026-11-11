<?php

namespace App\Console\Commands;

use App\Models\ImapImportJob;
use App\Services\ImapImportService;
use Illuminate\Console\Command;

class ImportImapMail extends Command
{
    protected $signature   = 'mail:import-imap {--job= : Kör ett specifikt jobb (ID)}';
    protected $description = 'Importerar e-post från IMAP till Google Workspace (körs av cron var 5:e minut)';

    public function handle(ImapImportService $service): int
    {
        if ($jobId = $this->option('job')) {
            $job = ImapImportJob::find($jobId);
            if (! $job) {
                $this->error("Jobb #{$jobId} hittades inte.");
                return self::FAILURE;
            }
            return $this->runJob($job, $service);
        }

        // Reset stuck jobs
        ImapImportJob::where('status', ImapImportJob::STATUS_RUNNING)
            ->where('started_at', '<', now()->subHours(2))
            ->update(['status' => ImapImportJob::STATUS_PENDING, 'started_at' => null]);

        // Skip if another job is currently running
        if (ImapImportJob::where('status', ImapImportJob::STATUS_RUNNING)->exists()) {
            $this->info('Ett importjobb körs redan — hoppar över.');
            return self::SUCCESS;
        }

        $job = ImapImportJob::where('status', ImapImportJob::STATUS_PENDING)->oldest()->first();

        if (! $job) {
            return self::SUCCESS;
        }

        return $this->runJob($job, $service);
    }

    private function runJob(ImapImportJob $job, ImapImportService $service): int
    {
        $this->info("Startar importjobb #{$job->id} för user #{$job->user_id}...");

        $service->import($job);

        $job->refresh();

        if ($job->isCompleted()) {
            $this->info("Klart! {$job->messages_imported} meddelanden importerade.");
            return self::SUCCESS;
        }

        $this->error("Misslyckades: {$job->error}");
        return self::FAILURE;
    }
}
