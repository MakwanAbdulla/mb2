<?php

namespace App\Console\Commands;

use App\Models\Signing\SigningRequest;
use Illuminate\Console\Command;

/**
 * Shows a signing request and its event log — e.g. to read what DocSign sent to
 * the callback URLs on an environment without database access.
 */
class SigningShow extends Command
{
    protected $signature = 'signing:show {id? : Ärendets id (standard: det senaste)}';

    protected $description = 'Visa ett signeringsärende och dess händelselogg';

    public function handle(): int
    {
        $request = $this->argument('id')
            ? SigningRequest::find($this->argument('id'))
            : SigningRequest::latest('id')->first();

        if (! $request) {
            $this->error('Hittade inget signeringsärende.');

            return self::FAILURE;
        }

        $request->load(['signers.signer', 'events']);

        $this->info("Ärende {$request->id}: {$request->name}");
        $this->line("Status: {$request->status->label()} · DocSign-dokument: ".($request->provider_document_id ?? '–').($request->is_demo ? ' (demo)' : ''));
        if ($request->last_error) {
            $this->warn("Senaste fel: {$request->last_error}");
        }

        $this->table(['Undertecknare', 'Metod', 'Signerat'], $request->signers->map(fn ($s) => [
            $s->signer->name,
            $s->signer->sign_method->label(),
            $s->signed_at?->timezone('Europe/Stockholm')->format('d/m Y H:i') ?? '–',
        ]));

        $this->newLine();
        $this->line('Händelser (äldst först):');
        foreach ($request->events->reverse() as $event) {
            $this->line("<comment>{$event->created_at->timezone('Europe/Stockholm')->format('d/m Y H:i:s')}</comment> {$event->type->label()}");
            if ($event->payload) {
                $this->line(json_encode($event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        return self::SUCCESS;
    }
}
