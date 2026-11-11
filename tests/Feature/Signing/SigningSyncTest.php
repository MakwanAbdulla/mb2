<?php

use App\Enums\SigningEventType;
use App\Enums\SigningStatus;
use App\Events\SigningRequestCompleted;
use App\Models\Signing\SigningRequest;
use App\Models\Signing\SigningRequestSigner;
use App\Models\Signing\SigningSigner;
use App\Models\SystemSetting;
use App\Services\Signing\SigningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    // Test requests come from 127.0.0.1, not Inleed.
    config(['services.docsign.callback_ips' => ['127.0.0.1']]);
    config(['services.docsign.api_key' => 'test-key', 'services.docsign.external_id_prefix' => 'test-install']);
    Storage::fake('local');
});

/** A sent request with one registered signer (party 501), as DocSign doc 9001. */
function sentRequest(): array
{
    $request = SigningRequest::factory()->sent()->create(['provider_document_id' => '9001', 'created_by' => null]);
    $signer = SigningSigner::factory()->create(['provider_party_id' => '501']);
    $requestSigner = SigningRequestSigner::factory()->create([
        'signing_request_id' => $request->id, 'signing_signer_id' => $signer->id, 'sign_url' => null,
    ]);
    $token = $request->issueCallbackToken();

    return [$request, $requestSigner, $token];
}

/** GET /documents as observed on staging: integer ids, "" until signed, UTC signed_at. */
function docsignDocument(string $state, ?string $signedAt = null, string $signedPdf = ''): array
{
    return [[
        'id' => 9001, 'name' => '[DEMO] Test', 'state' => $state, 'comment' => null,
        'created_at' => '2026-10-23 23:31:49',
        'original_pdf_url' => 'https://docsign.se/stream-file/original',
        'signed_pdf_url' => $signedPdf,
        'parties' => [[
            'id' => 501, 'name' => 'Anna', 'email' => 'anna@example.com', 'sign_method' => 'bankid',
            'signed_at' => $signedAt, 'signed_by_name' => $signedAt ? 'Anna Maria Andersson' : null,
            'signed_by_id' => $signedAt ? '19900101-1234' : null,
            'sign_url' => 'https://docsign.se/sign/abc',
        ]],
    ]];
}

it('updates the signer from the API when the signed callback arrives', function () {
    [$request, $requestSigner, $token] = sentRequest();
    Http::fake(['docsign.se/api/documents*' => Http::response(docsignDocument('pending', '2026-10-23T21:32:27.000000Z'))]);

    $this->get("/docsign/callback/{$request->id}/{$token}/signed?document_id=9001&party_id=501")->assertOk();

    $requestSigner->refresh();
    expect($requestSigner->signed_at->utc()->toIso8601String())->toBe('2026-10-23T21:32:27+00:00')
        ->and($requestSigner->signed_by_name)->toBe('Anna Maria Andersson')
        ->and($requestSigner->sign_url)->toBe('https://docsign.se/sign/abc')
        ->and($request->fresh()->status)->toBe(SigningStatus::Pending)
        ->and($request->events()->where('type', SigningEventType::SignerSigned)->count())->toBe(1);
});

it('completes the request, stores the signed PDF with its hash and dispatches the event once', function () {
    Event::fake([SigningRequestCompleted::class]);
    [$request, , $token] = sentRequest();
    Http::fake([
        'docsign.se/api/documents*' => Http::response(docsignDocument('completed', '2026-10-23T21:32:27.000000Z', 'https://docsign.se/stream-file/signed')),
        'docsign.se/stream-file/signed' => Http::response('%PDF-1.7 signerat', 200, ['Content-Type' => 'application/pdf']),
    ]);

    $this->get("/docsign/callback/{$request->id}/{$token}/signed?document_id=9001&party_id=501")->assertOk();
    $this->get("/docsign/callback/{$request->id}/{$token}/completed?document_id=9001")->assertOk();
    app(SigningService::class)->sync($request); // e.g. the scheduled sync right after

    $request->refresh();
    expect($request->status)->toBe(SigningStatus::Completed)
        ->and($request->completed_at)->not->toBeNull()
        ->and($request->signed_sha256)->toBe(hash('sha256', '%PDF-1.7 signerat'))
        ->and($request->signedPdfIsIntact())->toBeTrue()
        ->and($request->events()->where('type', SigningEventType::Completed)->count())->toBe(1)
        ->and($request->events()->where('type', SigningEventType::SignerSigned)->count())->toBe(1);

    Event::assertDispatchedTimes(SigningRequestCompleted::class, 1);
    Http::assertSentCount(4); // 3 × GET /documents + 1 PDF download
});

it('retries the PDF download on a later sync if it failed', function () {
    Event::fake([SigningRequestCompleted::class]);
    [$request] = sentRequest();
    Http::fake([
        'docsign.se/api/documents*' => Http::response(docsignDocument('completed', '2026-10-23T21:32:27.000000Z', 'https://docsign.se/stream-file/signed')),
        'docsign.se/stream-file/signed' => Http::sequence()->push('Not found', 404)->push('%PDF-1.7 signerat'),
    ]);

    app(SigningService::class)->sync($request);
    expect($request->fresh()->signed_path)->toBeNull()
        ->and($request->fresh()->status)->toBe(SigningStatus::Completed)
        ->and($request->events()->where('type', SigningEventType::Error)->exists())->toBeTrue();
    Event::assertNotDispatched(SigningRequestCompleted::class);

    app(SigningService::class)->sync($request);
    expect($request->fresh()->signed_path)->not->toBeNull();
    Event::assertDispatchedTimes(SigningRequestCompleted::class, 1);
});

it('never lets a callback fail even if syncing does', function () {
    [$request, , $token] = sentRequest();
    Http::fake(['docsign.se/api/documents*' => Http::response(['message' => 'Server Error'], 500)]);

    $this->get("/docsign/callback/{$request->id}/{$token}/signed?document_id=9001&party_id=501")->assertOk();

    expect($request->events()->where('type', SigningEventType::CallbackReceived)->count())->toBe(1)
        ->and($request->events()->where('type', SigningEventType::Error)->first()->payload['stage'])->toBe('callback_sync');
});

it('records a paused document and an error when the document is gone', function () {
    [$request] = sentRequest();
    Http::fake(['docsign.se/api/documents*' => Http::sequence()
        ->push(docsignDocument('paused'))
        ->push([])]);

    app(SigningService::class)->sync($request);
    expect($request->fresh()->status)->toBe(SigningStatus::Paused)
        ->and($request->events()->where('type', SigningEventType::Paused)->exists())->toBeTrue();

    app(SigningService::class)->sync($request);
    expect($request->fresh()->last_error)->toBe('Dokumentet finns inte längre hos DocSign.');
});

it('never stores the personnummer DocSign returns', function () {
    [$request, $requestSigner] = sentRequest();
    Http::fake(['docsign.se/api/documents*' => Http::response(docsignDocument('pending', '2026-10-23T21:32:27.000000Z'))]);

    app(SigningService::class)->sync($request);

    $everything = json_encode(DB::table('signing_request_events')->get()).json_encode(DB::table('signing_request_signers')->get());
    expect($everything)->not->toContain('19900101-1234');
});

it('parses both of DocSign’s time formats', function () {
    expect(SigningService::parseProviderTime('2026-10-23T20:39:17.000000Z')->utc()->toIso8601String())->toBe('2026-10-23T20:39:17+00:00')
        ->and(SigningService::parseProviderTime('2026-10-23 22:31:01')->utc()->toIso8601String())->toBe('2026-10-23T20:31:01+00:00');
});

it('stores signing times so they read back as the same instant', function () {
    [$request, $requestSigner] = sentRequest();
    Http::fake(['docsign.se/api/documents*' => Http::response(docsignDocument('pending', '2026-10-23T20:39:17.000000Z'))]);

    app(SigningService::class)->sync($request);

    // 20:39 UTC is 22:39 in Sweden (CEST) — stored and read back without drifting.
    expect($requestSigner->fresh()->signed_at->utc()->format('H:i'))->toBe('20:39')
        ->and($requestSigner->fresh()->signed_at->timezone('Europe/Stockholm')->format('H:i'))->toBe('22:39');
});

// ── Send: re-registration ─────────────────────────────────────────────────────

it('re-registers a signer that was deleted at DocSign and retries the send once', function () {
    $signer = SigningSigner::factory()->create(['provider_party_id' => '501']);
    $request = SigningRequest::factory()->create(['created_by' => null]);
    Storage::disk('local')->put('a.pdf', '%PDF-1.7');
    $request->files()->create(['name' => 'a.pdf', 'disk' => 'local', 'path' => 'a.pdf']);
    $request->signers()->create(['signing_signer_id' => $signer->id]);

    Http::fake([
        'docsign.se/api/parties*' => Http::response(['success' => true, 'party_id' => '777']),
        'docsign.se/api/documents*' => Http::sequence()
            ->push(['success' => false, 'message' => 'validation_errors', 'data' => ['parties.0' => ['Parties.0 är ogiltigt.']]])
            ->push(['success' => true, 'document_id' => 9002]),
    ]);

    app(SigningService::class)->send($request);

    expect($signer->fresh()->provider_party_id)->toBe('777')
        ->and($request->fresh()->provider_document_id)->toBe('9002');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/documents') && $r['parties'] === [777]);
});

// ── signing:sync ──────────────────────────────────────────────────────────────

it('does nothing without an api key', function () {
    config(['services.docsign.api_key' => null]);

    $this->artisan('signing:sync')->expectsOutputToContain('DOCSIGN_API_KEY saknas')->assertSuccessful();
    Http::assertNothingSent();
});

it('syncs stale open requests, updates changed signers and stores the balance', function () {
    [$stale] = sentRequest();
    $recentlySynced = now()->subMinutes(2)->startOfSecond();
    $recent = SigningRequest::factory()->sent()->create(['provider_document_id' => '9999', 'last_synced_at' => $recentlySynced, 'created_by' => null]);
    $changed = SigningSigner::factory()->create(['provider_party_id' => '600', 'email' => 'gammal@example.com']);
    $missing = SigningSigner::factory()->create(['provider_party_id' => '601']);

    Http::fake([
        'docsign.se/api/documents*' => Http::response(docsignDocument('pending')),
        'docsign.se/api/parties*' => Http::response([
            ['id' => 600, 'name' => $changed->name, 'email' => 'ny@example.com', 'sign_method' => 'bankid', 'external_id' => $changed->externalId()],
            ['id' => 2135751113, 'name' => 'Kontoägare', 'email' => 'konto@example.com', 'sign_method' => 'bankid', 'external_id' => null],
        ]),
        'docsign.se/api/account*' => Http::response(['email' => 'konto@example.com', 'balance' => 9750]),
    ]);

    $this->artisan('signing:sync')
        ->expectsOutputToContain('Synkade 1 ärenden')
        ->expectsOutputToContain('Uppdaterade undertecknare: 1')
        ->expectsOutputToContain('97,50 kr')
        ->assertSuccessful();

    expect($stale->fresh()->last_synced_at)->not->toBeNull()
        ->and($changed->fresh()->email)->toBe('ny@example.com')
        ->and($missing->fresh()->provider_party_id)->toBe('601') // absence ≠ deletion
        ->and(SystemSetting::get(SigningService::BALANCE_SETTING))->toBe(9750);

    // Synced 2 minutes ago, so skipped this round (only stale ones are polled).
    expect($recent->fresh()->last_synced_at->equalTo($recentlySynced))->toBeTrue();
});
