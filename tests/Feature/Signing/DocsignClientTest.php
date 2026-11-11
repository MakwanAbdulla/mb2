<?php

use App\Services\Signing\DocsignClient;
use App\Services\Signing\DocsignException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Never reach the real DocSign from tests.
    Http::preventStrayRequests();
    config(['services.docsign.api_key' => 'test-key', 'services.docsign.base_url' => 'https://docsign.se/api']);
});

it('reads the account balance in öre', function () {
    Http::fake(['docsign.se/api/account*' => Http::response(['email' => 'ekonomi@example.com', 'balance' => 9875])]);

    expect(DocsignClient::fromConfig()->account())->toBe(['email' => 'ekonomi@example.com', 'balance_ore' => 9875]);

    Http::assertSent(fn (Request $r) => $r['api_key'] === 'test-key');
});

it('refuses to build without an api key', function () {
    config(['services.docsign.api_key' => null]);

    expect(fn () => DocsignClient::fromConfig())->toThrow(DocsignException::class, 'DOCSIGN_API_KEY saknas.');
});

it('treats success:false with HTTP 200 as an error and keeps validation messages', function () {
    Http::fake(['docsign.se/api/documents*' => Http::response([
        'success' => false,
        'message' => 'validation_errors',
        'data' => ['parties.1' => ['Parties.1 är ogiltigt.'], 'attachments' => ['Fältet är obligatoriskt.']],
    ], 200)]);

    try {
        DocsignClient::fromConfig()->createDocument(['name' => 'X', 'parties' => [1, 2]]);
        $this->fail('Expected DocsignException');
    } catch (DocsignException $e) {
        expect($e->getMessage())->toBe('validation_errors')
            ->and($e->invalidPartyIndexes())->toBe([1])
            ->and($e->summary())->toContain('parties.1: Parties.1 är ogiltigt.')
            ->and($e->summary())->toContain('attachments: Fältet är obligatoriskt.');
    }
});

it('throws on HTTP errors such as a 500 from DocSign', function () {
    Http::fake(['docsign.se/api/documents*' => Http::response(['message' => 'Server Error'], 500)]);

    expect(fn () => DocsignClient::fromConfig()->createDocument(['name' => 'X']))
        ->toThrow(DocsignException::class, 'Server Error');
});

it('normalizes ids to strings whether DocSign sends integers or strings', function () {
    Http::fake([
        'docsign.se/api/parties*' => Http::sequence()
            ->push(['success' => true, 'message' => 'Party created', 'party_id' => '17'])
            ->push([['id' => 2135751113, 'name' => 'A', 'email' => 'a@example.com', 'sign_method' => 'bankid', 'external_id' => 42]]),
        'docsign.se/api/documents*' => Http::response(['success' => true, 'message' => 'Document created', 'document_id' => 2050915006]),
    ]);
    $client = DocsignClient::fromConfig();

    expect($client->createParty(['name' => 'A', 'email' => 'a@example.com', 'sign_method' => 'bankid']))->toBe('17')
        ->and($client->listParties()[0])->toMatchArray(['id' => '2135751113', 'external_id' => '42'])
        ->and($client->createDocument(['name' => 'X']))->toBe('2050915006');
});

it('treats an empty signed_pdf_url as missing and finds a document by id', function () {
    Http::fake(['docsign.se/api/documents*' => Http::response([[
        'id' => 1107690229, 'name' => '[DEMO] Test', 'state' => 'pending',
        'original_pdf_url' => 'https://docsign.se/stream-file/abc', 'signed_pdf_url' => '',
        'parties' => [['id' => 7, 'signed_at' => null, 'sign_url' => 'https://docsign.se/sign/x']],
    ]])]);

    $doc = DocsignClient::fromConfig()->findDocument('1107690229');

    expect($doc['id'])->toBe('1107690229')
        ->and($doc['signed_pdf_url'])->toBeNull()
        ->and($doc['parties'][0]['id'])->toBe('7')
        ->and(DocsignClient::fromConfig()->findDocument('999'))->toBeNull();
});

it('does not send null fields', function () {
    Http::fake(['docsign.se/api/parties*' => Http::response(['success' => true, 'party_id' => '1'])]);

    DocsignClient::fromConfig()->createParty(['name' => 'A', 'email' => null, 'phone_number' => '+46701234567', 'sign_method' => 'bankid']);

    Http::assertSent(fn (Request $r) => ! array_key_exists('email', $r->data()) && $r['phone_number'] === '+46701234567');
});
