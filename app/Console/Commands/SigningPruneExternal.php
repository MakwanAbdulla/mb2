<?php

namespace App\Console\Commands;

use App\Enums\SigningStatus;
use App\Models\Signing\SigningSigner;
use App\Services\Signing\SigningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Anonymizes external signers (no user account) once all their signing requests
 * have been closed for DOCSIGN_EXTERNAL_RETENTION_YEARS years. Disabled until that
 * is set. The signed PDFs are kept as the archive. Pruned signers are then deleted
 * at DocSign; failures are retried on the next run and listed on the signing page.
 */
class SigningPruneExternal extends Command
{
    protected $signature = 'signing:prune-external {--dry-run : Visa vilka som skulle anonymiseras, utan att ändra något}';

    protected $description = 'Anonymisera externa undertecknare efter den bestämda tiden';

    public function handle(): int
    {
        $years = config('services.docsign.external_retention_years');

        if (! $years) {
            $this->line('Gallring är avstängd (DOCSIGN_EXTERNAL_RETENTION_YEARS saknas).');

            return self::SUCCESS;
        }

        $cutoff = now()->subYears((int) $years);
        $open = [SigningStatus::Draft, SigningStatus::Pending, SigningStatus::Paused];

        $signers = SigningSigner::query()
            ->whereNull('user_id')
            ->whereNull('anonymized_at')
            ->where('created_at', '<', $cutoff)
            // No request that is still open, or closed after the cutoff
            // (grouped, so the OR stays inside the relation's own condition)
            ->whereDoesntHave('requestSigners.signingRequest', fn ($q) => $q->where(fn ($q) => $q
                ->whereIn('status', $open)
                ->orWhere('updated_at', '>=', $cutoff)))
            ->get();

        foreach ($signers as $signer) {
            $this->line(($this->option('dry-run') ? 'Skulle anonymisera' : 'Anonymiserar')." undertecknare {$signer->id}");

            if (! $this->option('dry-run')) {
                $signer->anonymize();
            }
        }

        $this->info(($this->option('dry-run') ? 'Skulle anonymisera ' : 'Anonymiserade ').$signers->count().' externa undertecknare.');

        if (! $this->option('dry-run')) {
            $this->deleteAtDocsign();
        }

        return self::SUCCESS;
    }

    /** Also retries signers whose deletion failed on an earlier run. */
    private function deleteAtDocsign(): void
    {
        $pending = SigningSigner::whereNotNull('anonymized_at')->whereNotNull('provider_party_id')->get();

        if ($pending->isEmpty()) {
            return;
        }

        $failed = 0;
        foreach ($pending as $signer) {
            try {
                app(SigningService::class)->deleteSignerAtDocsign($signer);
            } catch (Throwable $e) {
                $failed++;
                Log::warning('Could not delete signer at DocSign', ['signing_signer_id' => $signer->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info('Raderade '.($pending->count() - $failed).' undertecknare hos DocSign.');

        if ($failed > 0) {
            $this->warn("{$failed} kunde inte raderas hos DocSign. Det görs ett nytt försök nästa gång, och de listas på signeringssidan i Membrum.");
        }
    }
}
