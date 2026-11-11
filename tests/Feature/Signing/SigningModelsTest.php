<?php

use App\Enums\SigningEventType;
use App\Enums\SigningStatus;
use App\Enums\SignMethod;
use App\Models\Membership\Member;
use App\Models\Signing\SigningRequest;
use App\Models\Signing\SigningRequestEvent;
use App\Models\Signing\SigningRequestFile;
use App\Models\Signing\SigningRequestSigner;
use App\Models\Signing\SigningSigner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// ── Signers ───────────────────────────────────────────────────────────────────

it('normalizes phone numbers to international format', function (?string $input, ?string $expected) {
    expect(SigningSigner::normalizePhone($input))->toBe($expected);
})->with([
    'swedish mobile' => ['0728-57 20 55', '+46728572055'],
    'already intl' => ['+46728572055', '+46728572055'],
    '00 prefix' => ['0046 728 57 20 55', '+46728572055'],
    'empty' => ['  ', null],
    'null' => [null, null],
]);

it('normalizes the phone number when saving a signer', function () {
    $signer = SigningSigner::factory()->create(['phone_number' => '070-123 45 67']);

    expect($signer->fresh()->phone_number)->toBe('+46701234567');
});

it('finds a reusable signer by user, or by e-mail for externals, per sign method', function () {
    $user = User::factory()->create();
    $userSigner = SigningSigner::factory()->forUser($user)->create();
    $external = SigningSigner::factory()->create(['email' => 'sponsor@example.com', 'name' => 'Sara Sponsor']);

    expect(SigningSigner::matching($user->id, 'other@example.com', null, 'Vem som helst', SignMethod::BankId)->first()?->id)->toBe($userSigner->id)
        ->and(SigningSigner::matching(null, 'sponsor@example.com', null, 'Sara Sponsor', SignMethod::BankId)->first()?->id)->toBe($external->id)
        ->and(SigningSigner::matching(null, 'sponsor@example.com', null, 'Sara Sponsor', SignMethod::Email)->first())->toBeNull()
        ->and(SigningSigner::matching($user->id, 'x@example.com', null, 'X', SignMethod::Email)->first())->toBeNull();
});

it('does not reuse a user signer for an external person with the same e-mail', function () {
    $user = User::factory()->create(['email' => 'delad@example.com', 'name' => 'Förälder Familjsson']);
    SigningSigner::factory()->forUser($user)->create();

    expect(SigningSigner::matching(null, 'delad@example.com', null, 'Förälder Familjsson', SignMethod::BankId)->first())->toBeNull();
});

it('keeps external family members who share an e-mail as separate signers', function () {
    $parent = SigningSigner::factory()->create(['email' => 'familjen@example.com', 'name' => 'Anna Familjsson']);

    expect(SigningSigner::matching(null, 'familjen@example.com', null, 'Anna Familjsson', SignMethod::BankId)->first()?->id)->toBe($parent->id)
        ->and(SigningSigner::matching(null, 'familjen@example.com', null, 'Erik Familjsson', SignMethod::BankId)->first())->toBeNull();
});

it('keeps an applicant signer linked through the user when they become a member', function () {
    $applicant = User::factory()->create();
    $signer = SigningSigner::factory()->forUser($applicant)->create();

    Member::factory()->create(['user_id' => $applicant->id]);

    expect($signer->fresh()->user->member)->not->toBeNull()
        ->and($signer->fresh()->isExternal())->toBeFalse()
        ->and(SigningSigner::factory()->create()->isExternal())->toBeTrue();
});

it('requires an e-mail address or a phone number, like DocSign', function () {
    expect(fn () => SigningSigner::factory()->create(['email' => null, 'phone_number' => null]))
        ->toThrow(InvalidArgumentException::class);

    expect(SigningSigner::factory()->create(['email' => null, 'phone_number' => '0701234567'])->exists)->toBeTrue()
        ->and(SigningSigner::factory()->create(['email' => 'a@example.com', 'phone_number' => null])->exists)->toBeTrue();
});

it('normalizes e-mail addresses and treats blank as missing', function () {
    $signer = SigningSigner::factory()->create(['email' => '  Anna@Example.COM ']);

    expect($signer->fresh()->email)->toBe('anna@example.com')
        ->and(SigningSigner::factory()->create(['email' => '  ', 'phone_number' => '0701234567'])->fresh()->email)->toBeNull();
});

it('matches an external signer on name plus e-mail or phone', function () {
    $phoneOnly = SigningSigner::factory()->create(['name' => 'Hyresvärd Hansson', 'email' => null, 'phone_number' => '070-111 22 33']);

    expect(SigningSigner::matching(null, null, '+46701112233', 'Hyresvärd Hansson', SignMethod::BankId)->first()?->id)->toBe($phoneOnly->id)
        ->and(SigningSigner::matching(null, 'ny@example.com', '0701112233', 'Hyresvärd Hansson', SignMethod::BankId)->first()?->id)->toBe($phoneOnly->id)
        ->and(SigningSigner::matching(null, null, '0701112233', 'Någon Annan', SignMethod::BankId)->first())->toBeNull()
        ->and(SigningSigner::matching(null, 'ANNA@example.com ', null, 'X', SignMethod::BankId)->first())->toBeNull()
        ->and(SigningSigner::matching(null, null, null, 'Hyresvärd Hansson', SignMethod::BankId)->first())->toBeNull();
});

// ── Requests ──────────────────────────────────────────────────────────────────

it('maps DocSign document states to our statuses', function () {
    expect(SigningStatus::fromProviderState('pending'))->toBe(SigningStatus::Pending)
        ->and(SigningStatus::fromProviderState('paused'))->toBe(SigningStatus::Paused)
        ->and(SigningStatus::fromProviderState('completed'))->toBe(SigningStatus::Completed)
        ->and(fn () => SigningStatus::fromProviderState('weird'))->toThrow(UnexpectedValueException::class);
});

it('stores only a hash of the callback token and verifies it in constant time', function () {
    $request = SigningRequest::factory()->create();

    $token = $request->issueCallbackToken();

    expect($request->fresh()->callback_token_hash)->toBe(hash('sha256', $token))
        ->and($request->fresh()->callback_token_hash)->not->toBe($token)
        ->and($request->callbackTokenMatches($token))->toBeTrue()
        ->and($request->callbackTokenMatches('wrong'))->toBeFalse()
        ->and($request->toArray())->not->toHaveKey('callback_token_hash');
});

it('only treats pending and paused requests as open', function () {
    SigningRequest::factory()->create();             // draft
    $pending = SigningRequest::factory()->sent()->create();
    SigningRequest::factory()->completed()->create();

    expect(SigningRequest::open()->pluck('id')->all())->toBe([$pending->id]);
});

it('counts signers who have signed', function () {
    $request = SigningRequest::factory()->sent()->create();
    SigningRequestSigner::factory()->signed()->for($request, 'signingRequest')->create();
    SigningRequestSigner::factory()->for($request, 'signingRequest')->create();

    expect($request->load('signers')->signedCount())->toBe(1);
});

it('deletes files, signers and events with the request but keeps the reusable signer', function () {
    $request = SigningRequest::factory()->create();
    SigningRequestFile::factory()->for($request, 'signingRequest')->create();
    $requestSigner = SigningRequestSigner::factory()->for($request, 'signingRequest')->create();
    $request->recordEvent(SigningEventType::Created);

    $request->delete();

    expect(SigningRequestFile::count())->toBe(0)
        ->and(SigningRequestSigner::count())->toBe(0)
        ->and(SigningRequestEvent::count())->toBe(0)
        ->and(SigningSigner::find($requestSigner->signing_signer_id))->not->toBeNull();
});

it('stores the signed PDF with its hash and detects later changes', function () {
    Storage::fake('local');
    $request = SigningRequest::factory()->sent()->create();

    $request->storeSignedPdf('%PDF-1.7 signerat innehåll');

    expect($request->fresh()->signed_sha256)->toBe(hash('sha256', '%PDF-1.7 signerat innehåll'))
        ->and($request->fresh()->signedPdfIsIntact())->toBeTrue();

    Storage::disk('local')->put($request->fresh()->signed_path, '%PDF-1.7 ändrat i efterhand');

    expect($request->fresh()->signedPdfIsIntact())->toBeFalse()
        ->and(SigningRequest::factory()->create()->signedPdfIsIntact())->toBeNull();
});

// ── Personal data ─────────────────────────────────────────────────────────────

it('encrypts the sign url at rest and hides it from serialization', function () {
    $requestSigner = SigningRequestSigner::factory()->create(['sign_url' => 'https://docsign.se/sign/abc']);

    $raw = DB::table('signing_request_signers')->where('id', $requestSigner->id)->value('sign_url');

    expect($raw)->not->toContain('docsign.se')
        ->and($requestSigner->fresh()->sign_url)->toBe('https://docsign.se/sign/abc')
        ->and($requestSigner->toArray())->not->toHaveKey('sign_url');
});

it('removes personnummer, signing links and secrets from event payloads at any depth', function () {
    $request = SigningRequest::factory()->create();

    $event = $request->recordEvent(SigningEventType::Synced, [
        'api_key' => 'secret',
        'id' => 1,
        'state' => 'pending',
        'parties' => [[
            'id' => 7,
            'name' => 'Anna Andersson',
            'signed_by_name' => 'Anna Maria Andersson',
            'signed_by_id' => '19900101-1234',
            'sign_url' => 'https://docsign.se/sign/abc',
        ]],
        'attachments' => [['name' => 'avtal.pdf', 'base64_content' => 'JVBERi0x']],
    ]);

    $stored = json_encode(DB::table('signing_request_events')->where('id', $event->id)->value('payload'));

    expect($stored)->not->toContain('19900101-1234')
        ->not->toContain('docsign.se/sign')
        ->not->toContain('secret')
        ->not->toContain('JVBERi0x')
        ->toContain('Anna Maria Andersson')
        ->and($event->fresh()->payload['parties'][0])->toHaveKeys(['id', 'name', 'signed_by_name'])
        ->not->toHaveKey('signed_by_id');
});

it('knows its defaults right after create, without a refresh', function () {
    $request = SigningRequest::create(['name' => 'Nytt ärende']);

    expect($request->status)->toBe(SigningStatus::Draft)
        ->and($request->provider)->toBe(SigningRequest::PROVIDER_DOCSIGN)
        ->and($request->is_demo)->toBeFalse();
});
