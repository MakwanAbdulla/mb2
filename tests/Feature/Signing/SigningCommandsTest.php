<?php

use App\Enums\SigningStatus;
use App\Models\Signing\SigningRequest;
use App\Models\Signing\SigningSigner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.docsign.api_key' => 'test-key']);
    Storage::fake('local');
    Http::fake([
        'docsign.se/api/account*' => Http::response(['email' => 'konto@example.com', 'balance' => 9875]),
        'docsign.se/api/parties*' => Http::response(['success' => true, 'party_id' => '501']),
        'docsign.se/api/documents*' => Http::response(['success' => true, 'document_id' => 9001]),
    ]);
});

it('sends a free demo test document by default, linked to an existing user', function () {
    $user = User::factory()->create(['email' => 'anna@example.com', 'name' => 'Anna Andersson']);

    $this->artisan('signing:test-send', ['email' => 'Anna@Example.com'])
        ->expectsOutputToContain('98,75 kr')
        ->expectsOutputToContain('(demo)')
        ->assertSuccessful();

    $request = SigningRequest::sole();
    expect($request->is_demo)->toBeTrue()
        ->and($request->status)->toBe(SigningStatus::Pending)
        ->and($request->files)->toHaveCount(1)
        ->and(SigningSigner::sole()->user_id)->toBe($user->id);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/documents') && $r['is_demo'] === true);
});

it('asks for confirmation before sending a real document', function () {
    $this->artisan('signing:test-send', ['email' => 'extern@example.com', '--real' => true])
        ->expectsConfirmation('Ett riktigt dokument kostar 1,25 kr per signatur. Fortsätta?', 'no')
        ->assertFailed();

    expect(SigningRequest::count())->toBe(0);
    Http::assertNothingSent();
});

it('shows a request with its events', function () {
    $this->artisan('signing:test-send', ['email' => 'extern@example.com', '--name' => 'Extern Person'])->assertSuccessful();
    $request = SigningRequest::sole();

    $this->artisan('signing:show', ['id' => $request->id])
        ->expectsOutputToContain("Ärende {$request->id}")
        ->expectsOutputToContain('Väntar på signering')
        ->expectsOutputToContain('Skickad för signering')
        ->assertSuccessful();
});
