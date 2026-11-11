<?php

namespace App\Console\Commands;

use App\Enums\SigningStatus;
use App\Models\Signing\SigningRequest;
use App\Services\Signing\SigningService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fallback for missed DocSign callbacks: syncs open requests (and completed ones
 * whose signed PDF hasn't been fetched yet), updates signers changed in DocSign's
 * web UI, and refreshes the account balance. Scheduled every 15 minutes.
 */
class SigningSync extends Command
{
    protected $signature = 'signing:sync {--all : Synka alla öppna ärenden, även nyligen synkade}';

    protected $description = 'Hämta status för signeringsärenden från DocSign (reserv om callbacks uteblir)';

    public function handle(): int
    {
        if (! config('services.docsign.api_key')) {
            $this->line('DocSign är inte konfigurerat (DOCSIGN_API_KEY saknas). Inget att göra.');

            return self::SUCCESS;
        }

        // Resolved after the check: building the client requires the key.
        $service = app(SigningService::class);

        $requests = SigningRequest::query()
            ->whereNotNull('provider_document_id')
            ->where(fn ($q) => $q->open()
                ->orWhere(fn ($q) => $q->where('status', SigningStatus::Completed)->whereNull('signed_path')))
            ->when(! $this->option('all'), fn ($q) => $q->where(fn ($q) => $q
                ->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<', now()->subMinutes(10))))
            ->get();

        $failed = 0;
        foreach ($requests as $request) {
            try {
                $service->sync($request);
            } catch (Throwable $e) {
                $failed++;
                $this->warn("Ärende {$request->id}: {$e->getMessage()}");
            }
        }
        $this->line("Synkade {$requests->count()} ärenden".($failed ? ", {$failed} misslyckades" : '').'.');

        try {
            $this->line('Uppdaterade undertecknare: '.$service->syncSigners().'.');
            $this->line('Saldo: '.format_ore($service->refreshBalance()).'.');
        } catch (Throwable $e) {
            $this->warn('Kunde inte synka undertecknare eller saldo: '.$e->getMessage());

            return self::FAILURE;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
