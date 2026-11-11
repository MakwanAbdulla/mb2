<?php

use App\Enums\SigningEventType;
use App\Enums\SigningStatus;
use App\Models\Signing\SigningRequest;
use App\Models\Signing\SigningRequestEvent;
use App\Models\Signing\SigningSigner;
use App\Services\Signing\DocsignException;
use App\Services\Signing\SigningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Never reach the real DocSign from tests.
    Http::preventStrayRequests();
    // Test requests come from 127.0.0.1, not Inleed.
    config(['services.docsign.callback_ips' => ['127.0.0.1']]);
    config(['services.docsign.api_key' => 'test-key', 'services.docsign.external_id_prefix' => 'test-install']);
    Storage::fake('local');
});

function draftRequestWithSigner(?SigningSigner $signer = null): SigningRequest
{
    $request = SigningRequest::factory()->create(['created_by' => null]);
    Storage::disk('local')->put("signing/{$request->id}/avtal.pdf", '%PDF-1.7 avtal');
    $request->files()->create(['name' => 'avtal.pdf', 'disk' => 'local', 'path' => "signing/{$request->id}/avtal.pdf"]);
    $request->signers()->create(['signing_signer_id' => ($signer ?? SigningSigner::factory()->create())->id]);

    return $request;
}

// ── Send ──────────────────────────────────────────────────────────────────────

it('registers the signer with our id as external_id and sends the document with per-request callbacks', function () {
    Http::fake([
        'docsign.se/api/parties*' => Http::response(['success' => true, 'message' => 'Party created', 'party_id' => '501']),
        'docsign.se/api/documents*' => Http::response(['success' => true, 'message' => 'Document created', 'document_id' => 9001]),
    ]);
    $signer = SigningSigner::factory()->create();
    $request = draftRequestWithSigner($signer);

    app(SigningService::class)->send($request);

    $request->refresh();
    expect($request->status)->toBe(SigningStatus::Pending)
        ->and($request->provider_document_id)->toBe('9001')
        ->and($request->sent_at)->not->toBeNull()
        ->and($signer->fresh()->provider_party_id)->toBe('501');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/parties') && $r['external_id'] === "test-install:{$signer->id}");
    Http::assertSent(function (Request $r) use ($request) {
        if (! str_ends_with($r->url(), '/documents')) {
            return false;
        }

        return $r['parties'] === [501]
            && $r['send_notifications'] === false
            && $r['attachments'][0]['base64_content'] === base64_encode('%PDF-1.7 avtal')
            && str_contains($r['callback_url'], "/docsign/callback/{$request->id}/")
            && str_ends_with($r['callback_url'], '/completed')
            && str_ends_with($r['callback_sign_url'], '/signed');
    });
});

it('reuses a signer that is already registered', function () {
    Http::fake(['docsign.se/api/documents*' => Http::response(['success' => true, 'document_id' => '1'])]);

    app(SigningService::class)->send(draftRequestWithSigner(SigningSigner::factory()->registered()->create()));

    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/parties'));
});

it('recovers a party by external_id when creating it is refused on retry', function () {
    $signer = SigningSigner::factory()->create();
    Http::fake([
        'docsign.se/api/parties*' => Http::sequence()
            ->push(['success' => false, 'message' => 'Party already exists'])
            ->push([
                ['id' => 776, 'name' => 'Y', 'external_id' => "other-install:{$signer->id}", 'sign_method' => 'bankid'],
                ['id' => 777, 'name' => 'X', 'external_id' => "test-install:{$signer->id}", 'sign_method' => 'bankid'],
            ]),
    ]);

    expect(app(SigningService::class)->registerSigner($signer))->toBe('777')
        ->and($signer->fresh()->provider_party_id)->toBe('777');
});

it('marks the request as failed and logs the error when DocSign refuses it', function () {
    Http::fake([
        'docsign.se/api/parties*' => Http::response(['success' => true, 'party_id' => '1']),
        'docsign.se/api/documents*' => Http::response(['success' => false, 'message' => 'validation_errors', 'data' => ['attachments' => ['Fältet är obligatoriskt.']]]),
    ]);
    $request = draftRequestWithSigner();

    expect(fn () => app(SigningService::class)->send($request))->toThrow(DocsignException::class);

    expect($request->fresh()->status)->toBe(SigningStatus::Failed)
        ->and($request->fresh()->last_error)->toBe('attachments: Fältet är obligatoriskt.')
        ->and($request->events()->where('type', SigningEventType::Error)->exists())->toBeTrue();
});

it('never logs the api key, attachment content or callback token', function () {
    Http::fake([
        'docsign.se/api/parties*' => Http::response(['success' => true, 'party_id' => '1']),
        'docsign.se/api/documents*' => Http::response(['success' => true, 'document_id' => '1']),
    ]);
    $request = draftRequestWithSigner();

    app(SigningService::class)->send($request);

    $logged = DB::table('signing_request_events')->where('signing_request_id', $request->id)->pluck('payload')->implode(' ');
    expect($logged)->not->toContain('test-key')
        ->not->toContain(base64_encode('%PDF-1.7 avtal'))
        ->not->toContain('/docsign/callback/');
});

// ── Callbacks ─────────────────────────────────────────────────────────────────

it('logs a callback with a valid token, for both GET and POST', function () {
    $request = SigningRequest::factory()->sent()->create();
    $token = $request->issueCallbackToken();

    $this->get("/docsign/callback/{$request->id}/{$token}/signed?document_id=9001&party_id=501")->assertOk();
    $this->post("/docsign/callback/{$request->id}/{$token}/completed", ['document_id' => '9001'])->assertOk();

    $events = SigningRequestEvent::where('type', SigningEventType::CallbackReceived)->oldest('id')->get();
    expect($events)->toHaveCount(2)
        ->and($events[0]->payload)->toMatchArray(['event' => 'signed', 'method' => 'GET'])
        ->and($events[0]->payload['query'])->toBe(['document_id' => '9001', 'party_id' => '501'])
        ->and($events[1]->payload)->toMatchArray(['event' => 'completed', 'method' => 'POST'])
        ->and($events[1]->payload['body'])->toBe(['document_id' => '9001']);
});

it('rejects a callback with the wrong token and logs nothing on the request', function () {
    $request = SigningRequest::factory()->sent()->create();
    $request->issueCallbackToken();

    $this->get("/docsign/callback/{$request->id}/fel-token/signed")->assertNotFound();

    expect(SigningRequestEvent::count())->toBe(0);
});

it('only accepts the signed and completed events', function () {
    $request = SigningRequest::factory()->sent()->create();
    $token = $request->issueCallbackToken();

    $this->get("/docsign/callback/{$request->id}/{$token}/hacked")->assertNotFound();
});

it('only accepts callbacks from DocSign addresses', function () {
    $request = SigningRequest::factory()->sent()->create();
    $token = $request->issueCallbackToken();

    config(['services.docsign.callback_ips' => ['194.68.59.169', '194.68.59.115']]);
    $this->get("/docsign/callback/{$request->id}/{$token}/signed?document_id=9001")->assertNotFound();
    $this->withServerVariables(['REMOTE_ADDR' => '194.68.59.115'])
        ->get("/docsign/callback/{$request->id}/{$token}/signed?document_id=9001")->assertOk();

    config(['services.docsign.callback_ips' => []]);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->get("/docsign/callback/{$request->id}/{$token}/signed?document_id=9001")->assertOk();

    expect(SigningRequestEvent::where('type', SigningEventType::CallbackReceived)->count())->toBe(2);
});

it('scrubs personnummer from callback payloads, also in an unparsed raw body', function () {
    $request = SigningRequest::factory()->sent()->create();
    $token = $request->issueCallbackToken();

    $this->post("/docsign/callback/{$request->id}/{$token}/signed", ['signed_by_id' => '19900101-1234', 'party_id' => '5'])->assertOk();
    $this->call('POST', "/docsign/callback/{$request->id}/{$token}/signed", [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'signerat av 199001011234')->assertOk();

    $logged = DB::table('signing_request_events')->pluck('payload')->implode(' ');
    expect($logged)->not->toContain('19900101-1234')
        ->not->toContain('199001011234')
        ->toContain('[personnummer]');
});
