<?php

namespace App\Console\Commands;

use App\Enums\SigningEventType;
use App\Enums\SignMethod;
use App\Models\Signing\SigningRequest;
use App\Models\Signing\SigningSigner;
use App\Models\User;
use App\Services\Signing\DocsignClient;
use App\Services\Signing\SigningService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends a test document via DocSign, to see the whole flow — including what
 * DocSign actually sends to our callback URLs (read it with `signing:show`).
 * Always a free demo document unless --real is given.
 */
class SigningTestSend extends Command
{
    protected $signature = 'signing:test-send
        {email : E-postadress till undertecknaren}
        {--name= : Undertecknarens namn (standard: användarens namn, annars e-postadressen)}
        {--real : Skicka ett riktigt dokument (kostar 1,25 kr per signatur) i stället för ett demodokument}';

    protected $description = 'Skicka ett testdokument för signering via DocSign (demo om inte --real anges)';

    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));
        $user = User::where('email', $email)->first();
        $name = $this->option('name') ?: ($user?->name ?? $email);
        $real = (bool) $this->option('real');

        if ($real && ! $this->confirm('Ett riktigt dokument kostar 1,25 kr per signatur. Fortsätta?')) {
            return self::FAILURE;
        }

        $client = DocsignClient::fromConfig();
        $this->line('Saldo hos DocSign: '.format_ore($client->account()['balance_ore']));

        $signer = SigningSigner::matching($user?->id, $email, null, $name, SignMethod::BankId)->first()
            ?? SigningSigner::create([
                'user_id' => $user?->id,
                'name' => $name,
                'email' => $email,
                'sign_method' => SignMethod::BankId,
            ]);

        $request = SigningRequest::create([
            'name' => 'Membrum testdokument '.now()->format('Y-m-d H:i'),
            'comment' => 'Test av e-signering från Membrum.',
            'is_demo' => ! $real,
            'send_reminders' => false,
            'send_receipt' => true,
        ]);
        $request->recordEvent(SigningEventType::Created, ['source' => 'signing:test-send']);

        $path = "signing/{$request->id}/testdokument.pdf";
        Storage::disk('local')->put($path, Pdf::loadHTML(
            '<h1>Membrum testdokument</h1><p>Test av e-signering via DocSign, skapat '.e(now()->format('d/m Y H:i')).'.</p>'
        )->output());
        $request->files()->create(['name' => 'membrum-testdokument.pdf', 'disk' => 'local', 'path' => $path]);
        $request->signers()->create(['signing_signer_id' => $signer->id, 'role_label' => 'Testare']);

        try {
            app(SigningService::class)->send($request);
        } catch (Throwable $e) {
            $this->error('Kunde inte skicka: '.$e->getMessage());
            $this->line("Se händelserna med: php artisan signing:show {$request->id}");

            return self::FAILURE;
        }

        $this->info("Skickat. Ärende {$request->id}, DocSign-dokument {$request->provider_document_id}".($real ? '' : ' (demo)').'.');
        $base = Str::beforeLast(route('docsign.callback', ['signingRequest' => $request->id, 'token' => 'TOKEN', 'event' => 'signed']), '/signed');
        $this->line("Callbacks går till {$base}/signed och …/completed (TOKEN = ärendets hemliga token)");
        $this->line("Signera via mejlet från DocSign, och läs sedan vad som kom in med: php artisan signing:show {$request->id}");

        return self::SUCCESS;
    }
}
