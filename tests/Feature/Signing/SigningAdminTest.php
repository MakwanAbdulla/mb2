<?php

use App\Enums\SigningStatus;
use App\Events\SigningRequestCompleted;
use App\Models\Signing\SigningRequest;
use App\Models\Signing\SigningRequestSigner;
use App\Models\Signing\SigningSigner;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\SigningBalanceLowNotification;
use App\Notifications\SigningRequestCompletedNotification;
use App\Services\Signing\DocsignClient;
use App\Services\Signing\DocsignException;
use App\Services\Signing\SigningService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.docsign.api_key' => 'test-key', 'services.docsign.external_id_prefix' => 'test-install', 'services.docsign.demo' => true]);
    Storage::fake('local');
    $this->seed(PermissionSeeder::class);
});

function signingManager(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(['signing.view', 'signing.manage']);

    return $user;
}

function fakeDocsign(int $balance = 10000): void
{
    $partyId = 500;

    Http::fake([
        'docsign.se/api/account*' => Http::response(['email' => 'konto@example.com', 'balance' => $balance]),
        // A new party id per created signer, like DocSign
        'docsign.se/api/parties*' => function () use (&$partyId) {
            return Http::response(['success' => true, 'party_id' => (string) ++$partyId]);
        },
        'docsign.se/api/documents*' => Http::response(['success' => true, 'document_id' => 9001]),
    ]);
}

function pdf(string $name = 'avtal.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, '%PDF-1.7 testinnehåll');
}

// ── Access ────────────────────────────────────────────────────────────────────

it('requires signing.view for the pages and signing.manage to create', function () {
    $member = User::factory()->create();
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('signing.view');
    $request = SigningRequest::factory()->create();

    $this->actingAs($member)->get(route('admin.signing.index'))->assertForbidden();
    $this->actingAs($viewer)->get(route('admin.signing.index'))->assertOk()->assertDontSee('Nytt signeringsärende');
    $this->actingAs($viewer)->get(route('admin.signing.show', $request))->assertOk();
    $this->actingAs($viewer)->get(route('admin.signing.create'))->assertForbidden();
    $this->actingAs(signingManager())->get(route('admin.signing.create'))->assertOk();
});

it('gives the treasurer, board and secretary roles access, and shows the menu entry', function () {
    foreach (['treasurer', 'styrelse', 'sekreterare'] as $role) {
        $user = User::factory()->create();
        $user->assignRole($role);

        expect($user->can('signing.manage'))->toBeTrue()
            ->and($user->isStaff())->toBeTrue();
    }

    $creator = User::factory()->create();
    $creator->givePermissionTo('signing.create');
    expect($creator->isStaff())->toBeTrue();
    $this->actingAs($creator)->get(route('dashboard'))->assertSee(route('admin.signing.index'), false);
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertDontSee(route('admin.signing.index'), false);
});

it('lets signing.create send requests and see and cancel only their own', function () {
    $creator = User::factory()->create();
    $creator->givePermissionTo('signing.create');
    $own = SigningRequest::factory()->sent()->create(['name' => 'Mitt avtal', 'created_by' => $creator->id, 'provider_document_id' => '9001']);
    $others = SigningRequest::factory()->sent()->create(['name' => 'Annans protokoll', 'provider_document_id' => '9002']);
    $othersFile = $others->files()->create(['name' => 'p.pdf', 'disk' => 'local', 'path' => 'signing/p.pdf', 'sort_order' => 0]);
    Storage::disk('local')->put('signing/p.pdf', '%PDF-1.7');
    fakeDocumentEdit();

    $this->actingAs($creator)->get(route('admin.signing.index'))->assertOk()
        ->assertSee('Mitt avtal')->assertDontSee('Annans protokoll')
        ->assertSee('Nytt signeringsärende')->assertDontSee('Saldo hos DocSign');
    $this->actingAs($creator)->get(route('admin.signing.create'))->assertOk();
    $this->actingAs($creator)->get(route('admin.signing.show', $others))->assertForbidden();
    $this->actingAs($creator)->get(route('admin.signing.file', $othersFile))->assertForbidden();
    $this->actingAs($creator)->get(route('admin.signing.show', $own))->assertOk()->assertSee('Avbryt ärendet');

    // Signing links stay signing.manage only, also on your own request.
    $component = Volt::actingAs($creator)->test('admin.signing.show', ['signingRequest' => $own]);
    $component->call('signUrl', $own->signers()->create(['signing_signer_id' => SigningSigner::factory()->create()->id, 'sort_order' => 0])->id)->assertForbidden();
    Volt::actingAs($creator)->test('admin.signing.show', ['signingRequest' => $own])->call('cancel');
    expect($own->fresh()->status)->toBe(SigningStatus::Cancelled);

    Sanctum::actingAs($creator);
    $this->getJson('/api/v1/admin/signing-requests')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Mitt avtal');
    $this->getJson("/api/v1/admin/signing-requests/{$others->id}")->assertForbidden();
    $this->postJson("/api/v1/admin/signing-requests/{$others->id}/cancel")->assertForbidden();
    expect($others->fresh()->status)->toBe(SigningStatus::Pending);
});

it('lets the creator download their own signed PDF, not anyone else\'s', function () {
    $creator = User::factory()->create();
    $creator->givePermissionTo('signing.create');
    $own = SigningRequest::factory()->completed()->create(['created_by' => $creator->id]);
    $others = SigningRequest::factory()->completed()->create();
    Storage::disk('local')->put($own->signed_path, '%PDF-1.7 signerad');
    Storage::disk('local')->put($others->signed_path, '%PDF-1.7 signerad');

    $this->actingAs($creator)->get(route('admin.signing.show', $own))->assertSee(route('admin.signing.signed', $own), false);
    $this->actingAs($creator)->get(route('admin.signing.signed', $own))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($creator)->get(route('admin.signing.signed', $others))->assertForbidden();
});

it('serves files only to users with signing.view', function () {
    $request = SigningRequest::factory()->completed()->create();
    Storage::disk('local')->put($request->signed_path, '%PDF-1.7 signerad');

    $this->actingAs(User::factory()->create())->get(route('admin.signing.signed', $request))->assertForbidden();
    $this->actingAs(signingManager())->get(route('admin.signing.signed', $request))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

// ── Create from the page ──────────────────────────────────────────────────────

it('creates and sends a request, linking a signer to an existing user by e-mail', function () {
    fakeDocsign();
    $member = User::factory()->create(['email' => 'anna@example.com']);

    Volt::actingAs(signingManager())->test('admin.signing.create')
        ->set('name', 'Protokoll styrelsemöte 3')
        ->set('files', [pdf()])
        ->set('signers', [
            ['name' => 'Anna', 'email' => 'Anna@Example.com', 'phone' => '', 'sign_method' => 'bankid', 'role_label' => 'Justerare'],
            ['name' => 'Sara Sponsor', 'email' => '', 'phone' => '070-111 22 33', 'sign_method' => 'bankid', 'role_label' => ''],
        ])
        ->call('send')
        ->assertHasNoErrors()
        ->assertRedirect();

    $request = SigningRequest::sole();
    expect($request->status)->toBe(SigningStatus::Pending)
        ->and($request->is_demo)->toBeTrue()
        ->and($request->files)->toHaveCount(1)
        ->and($request->signers)->toHaveCount(2)
        ->and(SigningSigner::where('user_id', $member->id)->exists())->toBeTrue()
        ->and(SigningSigner::where('phone_number', '+46701112233')->whereNull('user_id')->exists())->toBeTrue();
});

it('requires e-mail or phone for each signer and a PDF', function () {
    Volt::actingAs(signingManager())->test('admin.signing.create')
        ->set('name', 'X')
        ->set('files', [UploadedFile::fake()->create('bild.png', 10, 'image/png')])
        ->set('signers', [['name' => 'Ingen kontakt', 'email' => '', 'phone' => '', 'sign_method' => 'bankid', 'role_label' => '']])
        ->call('send')
        ->assertHasErrors(['files.0', 'signers.0.email', 'signers.0.phone']);
});

it('appends the signer notice to the message sent to DocSign', function () {
    fakeDocsign();
    SystemSetting::set(SigningService::SIGNER_NOTICE_SETTING, 'Vi sparar dina uppgifter i 3 år.');

    Volt::actingAs(signingManager())->test('admin.signing.create')
        ->set('name', 'Avtal')->set('comment', 'Hej!')->set('files', [pdf()])
        ->set('signers', [['name' => 'A', 'email' => 'a@example.com', 'phone' => '', 'sign_method' => 'bankid', 'role_label' => '']])
        ->call('send');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/documents') && $r->method() === 'POST'
        && $r['comment'] === "Hej!\n\nVi sparar dina uppgifter i 3 år.");
});

// ── Service additions ─────────────────────────────────────────────────────────

it('refuses to send a real document when the balance does not cover the signatures', function () {
    fakeDocsign(balance: 200); // 2 kr, two signers need 2,50 kr
    $request = app(SigningService::class)->createDraft('Avtal', null, [
        ['name' => 'A', 'email' => 'a@example.com', 'sign_method' => 'bankid'],
        ['name' => 'B', 'email' => 'b@example.com', 'sign_method' => 'bankid'],
    ], [['name' => 'a.pdf', 'contents' => '%PDF-1.7']], null);
    $request->update(['is_demo' => false]);

    expect(fn () => app(SigningService::class)->send($request))->toThrow(DocsignException::class, 'Saldot hos DocSign räcker inte');
    expect($request->fresh()->status)->toBe(SigningStatus::Failed);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/documents'));
});

it('gives each request a reference derived from id and creation time, sent in the name', function () {
    fakeDocsign();
    $request = app(SigningService::class)->createDraft('Avtal', null, [
        ['name' => 'A', 'email' => 'a@example.com', 'sign_method' => 'bankid'],
    ], [['name' => 'a.pdf', 'contents' => '%PDF-1.7']], null);

    expect($request->reference)->toMatch('/^[0-9a-f]{6}$/')
        ->and($request->fresh()->reference)->toBe(SigningRequest::computeReference($request->id, $request->fresh()->created_at));

    app(SigningService::class)->send($request);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/documents')
        && $r['name'] === "Avtal [#{$request->reference}]");
});

it('finds a request by its reference in the list', function () {
    $this->actingAs(signingManager());
    $found = SigningRequest::factory()->create(['name' => 'Protokoll']);
    SigningRequest::factory()->create(['name' => 'Avtal']);

    Volt::test('admin.signing.index')
        ->set('search', "[#{$found->reference}]")
        ->assertSee('Protokoll')
        ->assertDontSee('Avtal');
});

it('recovers the document id after a timeout instead of sending twice', function () {
    $request = app(SigningService::class)->createDraft('Avtal', null, [
        ['name' => 'A', 'email' => 'a@example.com', 'sign_method' => 'bankid'],
    ], [['name' => 'a.pdf', 'contents' => '%PDF-1.7']], null);
    $request->signers->first()->signer->update(['provider_party_id' => '501']);

    Http::fake(function (Request $r) use ($request) {
        if ($r->method() === 'POST' && str_contains($r->url(), '/documents')) {
            throw new ConnectionException('Operation timed out');
        }

        $document = fn (int $id, string $name) => [
            'id' => $id, 'name' => $name, 'state' => 'pending',
            'created_at' => now()->timezone('Europe/Stockholm')->format('Y-m-d H:i:s'),
            'original_pdf_url' => '', 'signed_pdf_url' => '',
            'parties' => [['id' => 501]],
        ];

        // Same name and signer, but without our reference: another request's document.
        return Http::response([$document(9099, '[DEMO] Avtal'), $document(9100, "[DEMO] Avtal [#{$request->reference}]")]);
    });

    app(SigningService::class)->send($request);

    expect($request->fresh()->provider_document_id)->toBe('9100')
        ->and($request->fresh()->status)->toBe(SigningStatus::Pending);
});

// ── Notifications ─────────────────────────────────────────────────────────────

it('notifies signing managers about a low balance at most once a day', function () {
    Notification::fake();
    $manager = signingManager();
    $other = User::factory()->create();
    fakeDocsign(balance: 1000); // 10 kr, under the default 50 kr

    app(SigningService::class)->refreshBalance();
    app(SigningService::class)->refreshBalance();

    Notification::assertSentToTimes($manager, SigningBalanceLowNotification::class, 1);
    Notification::assertNotSentTo($other, SigningBalanceLowNotification::class);
});

it('notifies the creator when a request is completed', function () {
    Notification::fake();
    $creator = signingManager();
    $request = SigningRequest::factory()->completed()->create(['created_by' => $creator->id]);

    SigningRequestCompleted::dispatch($request);

    Notification::assertSentTo($creator, SigningRequestCompletedNotification::class);
});

// ── Pruning ───────────────────────────────────────────────────────────────────

it('does not prune until a retention period is set', function () {
    config(['services.docsign.external_retention_years' => null]);

    $this->artisan('signing:prune-external')->expectsOutputToContain('avstängd')->assertSuccessful();
});

it('anonymizes external signers whose requests closed before the cutoff', function () {
    config(['services.docsign.external_retention_years' => 3]);

    $this->travelTo(now()->subYears(4));
    $old = SigningSigner::factory()->registered()->create(['name' => 'Gammal Extern', 'email' => 'gammal@example.com']);
    $oldRequest = SigningRequest::factory()->completed()->create();
    $oldLink = SigningRequestSigner::factory()->signed()->create(['signing_request_id' => $oldRequest->id, 'signing_signer_id' => $old->id]);
    $stillOpen = SigningSigner::factory()->create();
    SigningRequestSigner::factory()->create(['signing_request_id' => SigningRequest::factory()->sent()->create()->id, 'signing_signer_id' => $stillOpen->id]);
    $user = SigningSigner::factory()->forUser()->create();
    $this->travelBack();

    $this->artisan('signing:prune-external', ['--dry-run' => true])->expectsOutputToContain('Skulle anonymisera 1')->assertSuccessful();
    expect($old->fresh()->anonymized_at)->toBeNull();

    $partyId = $old->provider_party_id;
    Http::fake(['docsign.se/api/party/edit' => Http::response(['success' => true, 'message' => 'Request completed successfully'])]);

    $this->artisan('signing:prune-external')
        ->expectsOutputToContain('Anonymiserade 1')
        ->expectsOutputToContain('Raderade 1 undertecknare hos DocSign')
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/party/edit') && $r['party_id'] === $partyId && $r['action'] === 'delete');
    $old->refresh();
    expect($old->name)->toBe(SigningSigner::ANONYMIZED_NAME)
        ->and($old->email)->toBeNull()
        ->and($old->anonymized_at)->not->toBeNull()
        ->and($old->provider_party_id)->toBeNull()
        ->and($oldLink->fresh()->signed_by_name)->toBeNull()
        ->and($stillOpen->fresh()->anonymized_at)->toBeNull()
        ->and($user->fresh()->anonymized_at)->toBeNull();
});

it('does not restore anonymized signers when syncing from DocSign', function () {
    $signer = SigningSigner::factory()->registered()->create();
    $signer->anonymize();
    Http::fake(['docsign.se/api/parties*' => Http::response([
        ['id' => (int) $signer->provider_party_id, 'name' => 'Fortfarande Kvar', 'email' => 'kvar@example.com', 'sign_method' => 'bankid', 'external_id' => $signer->externalId()],
    ])]);

    app(SigningService::class)->syncSigners();

    expect($signer->fresh()->name)->toBe(SigningSigner::ANONYMIZED_NAME);
});

it('keeps signers whose deletion at DocSign failed, retries them, and lets a manager retry from the page', function () {
    config(['services.docsign.external_retention_years' => 3]);
    $failing = SigningSigner::factory()->registered()->create();
    $failing->anonymize();
    $gone = SigningSigner::factory()->registered()->create();
    $gone->anonymize();
    $docsignDown = true;
    Http::fake(function (Request $r) use ($failing, &$docsignDown) {
        return $r['party_id'] === $failing->provider_party_id
            ? ($docsignDown ? Http::response('Server error', 500) : Http::response(['success' => true]))
            : Http::response(['message' => 'No query results for model [App\\Models\\Party].'], 404); // already deleted counts as done
    });

    $this->artisan('signing:prune-external')->expectsOutputToContain('1 kunde inte raderas')->assertSuccessful();

    expect($failing->fresh()->provider_party_id)->not->toBeNull()
        ->and($gone->fresh()->provider_party_id)->toBeNull();

    $docsignDown = false;
    Volt::actingAs(signingManager())->test('admin.signing.index')
        ->assertSee('Radera hos DocSign')
        ->call('deleteAtDocsign', $failing->id)
        ->assertDontSee('Radera hos DocSign');

    expect($failing->fresh()->provider_party_id)->toBeNull();
});

// ── Pause, resume, cancel ─────────────────────────────────────────────────────

/** @param  list<array>  $documents  what GET /documents returns (checked after a delete) */
function fakeDocumentEdit(array $responses = [], array $documents = []): void
{
    Http::fake(function (Request $r) use ($responses, $documents) {
        if ($r->method() === 'GET') {
            return Http::response($documents);
        }

        return $responses[$r['action']] ?? Http::response(['success' => true, 'message' => 'Request completed successfully']);
    });
}

it('pauses and resumes a request at DocSign', function () {
    $request = SigningRequest::factory()->sent()->create(['provider_document_id' => '9001']);
    fakeDocumentEdit();

    app(SigningService::class)->pause($request);
    expect($request->status)->toBe(SigningStatus::Paused);

    app(SigningService::class)->resume($request);
    expect($request->status)->toBe(SigningStatus::Pending);

    Http::assertSentInOrder([
        fn (Request $r) => str_ends_with($r->url(), '/document/edit') && $r['document_id'] === '9001' && $r['action'] === 'pause',
        fn (Request $r) => $r['action'] === 'resume',
    ]);
    expect($request->events->pluck('type')->map->value->all())->toContain('paused', 'resumed');
});

it('refuses to pause a request that is not pending, without calling DocSign', function () {
    $request = SigningRequest::factory()->completed()->create(['provider_document_id' => '9001']);

    expect(fn () => app(SigningService::class)->pause($request))->toThrow(DocsignException::class, 'Väntar på signering');
    Http::assertNothingSent();
});

it('cancels a pending request by pausing and then deleting it at DocSign', function () {
    $request = SigningRequest::factory()->sent()->create(['provider_document_id' => '9001']);
    fakeDocumentEdit();

    app(SigningService::class)->cancel($request);

    Http::assertSentInOrder([
        fn (Request $r) => $r['action'] === 'pause',
        fn (Request $r) => $r['action'] === 'delete',
        fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/documents'), // still there?
    ]);
    expect($request->status)->toBe(SigningStatus::Cancelled)
        ->and($request->cancelled_at)->not->toBeNull();

    // A late callback must not touch it: sync skips cancelled requests.
    app(SigningService::class)->sync($request);
    expect($request->fresh()->status)->toBe(SigningStatus::Cancelled);
});

it('cancels a draft locally and a request already deleted at DocSign', function () {
    $draft = SigningRequest::factory()->create();
    app(SigningService::class)->cancel($draft);
    expect($draft->status)->toBe(SigningStatus::Cancelled);
    Http::assertNothingSent();

    $paused = SigningRequest::factory()->sent()->create(['status' => SigningStatus::Paused, 'provider_document_id' => '9001']);
    fakeDocumentEdit(['delete' => Http::response(['message' => 'No query results for model [App\\Models\\Document].'], 404)]);
    app(SigningService::class)->cancel($paused);
    expect($paused->status)->toBe(SigningStatus::Cancelled);
});

it('does not mark a request cancelled if the document is still at DocSign after the delete', function () {
    $request = SigningRequest::factory()->sent()->create(['provider_document_id' => '9001']);
    fakeDocumentEdit(documents: [['id' => 9001, 'name' => 'Avtal', 'state' => 'paused', 'created_at' => '2026-10-27 10:00:00', 'parties' => []]]);

    expect(fn () => app(SigningService::class)->cancel($request))->toThrow(DocsignException::class, 'finns kvar');
    expect($request->fresh()->status)->toBe(SigningStatus::Paused);
});

it('keeps the status and shows DocSign\'s reason when an action is refused', function () {
    $request = SigningRequest::factory()->sent()->create(['provider_document_id' => '9001']);
    // Signed in the meantime: DocSign refuses to pause, with a string (not a list) per field.
    fakeDocumentEdit(['pause' => Http::response(['success' => false, 'message' => 'validation_errors', 'data' => ['action' => 'Document does not have state pending']])]);

    Volt::actingAs(signingManager())->test('admin.signing.show', ['signingRequest' => $request])
        ->call('pause')
        ->assertSet('actionError', 'Kunde inte pausa: action: Document does not have state pending');

    expect($request->fresh()->status)->toBe(SigningStatus::Pending);
});

it('shows pause and cancel to managers only, and cancels from the page', function () {
    $request = SigningRequest::factory()->sent()->create(['provider_document_id' => '9001']);
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('signing.view');
    fakeDocumentEdit();

    $this->actingAs($viewer)->get(route('admin.signing.show', $request))->assertDontSee('Avbryt ärendet')->assertDontSee('Pausa');

    Volt::actingAs(signingManager())->test('admin.signing.show', ['signingRequest' => $request])
        ->assertSee('Pausa')
        ->call('cancel')
        ->assertSet('actionError', null)
        ->assertDontSee('Avbryt ärendet');

    expect($request->fresh()->status)->toBe(SigningStatus::Cancelled);
});

it('only accepts known document actions', function () {
    expect(fn () => app(DocsignClient::class)->editDocument('9001', 'bogus'))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

// ── Dashboard ─────────────────────────────────────────────────────────────────

it('shows "Väntar på din signatur" to a user with an open request', function () {
    $user = User::factory()->create();
    $signer = SigningSigner::factory()->forUser($user)->registered()->create();
    $request = SigningRequest::factory()->sent()->create(['name' => 'Protokoll 3']);
    SigningRequestSigner::factory()->create(['signing_request_id' => $request->id, 'signing_signer_id' => $signer->id, 'sign_url' => 'https://docsign.se/sign/abc']);

    Volt::actingAs($user)->test('signing.pending-for-me')
        ->assertSee('Väntar på din signatur')->assertSee('Protokoll 3')->assertSee('https://docsign.se/sign/abc');

    Volt::actingAs(User::factory()->create())->test('signing.pending-for-me')->assertDontSee('Väntar på din signatur');
});

// ── API ───────────────────────────────────────────────────────────────────────

it('lists, shows and creates signing requests via the API without exposing sign links', function () {
    fakeDocsign();
    Sanctum::actingAs($manager = signingManager());
    $existing = SigningRequestSigner::factory()->create(['sign_url' => 'https://docsign.se/sign/hemlig']);

    $this->getJson('/api/v1/admin/signing-requests')->assertOk()->assertJsonPath('data.0.id', $existing->signing_request_id);
    $this->getJson('/api/v1/admin/signing-requests/'.$existing->signing_request_id)->assertOk()
        ->assertJsonMissingPath('data.signers.0.sign_url')
        ->assertDontSee('hemlig');

    $this->postJson('/api/v1/admin/signing-requests', [
        'name' => 'Avtal via API',
        'files' => [['name' => 'avtal.pdf', 'content_base64' => base64_encode('%PDF-1.7 avtal')]],
        'signers' => [['name' => 'Extern', 'email' => 'extern@example.com', 'sign_method' => 'bankid']],
    ])->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.created_by', $manager->id);

    $this->postJson('/api/v1/admin/signing-requests', [
        'name' => 'Inte PDF',
        'files' => [['name' => 'x.pdf', 'content_base64' => base64_encode('hej')]],
        'signers' => [['name' => 'Extern', 'email' => 'extern@example.com', 'sign_method' => 'bankid']],
    ])->assertUnprocessable();
});

it('returns 502 with the failed request when DocSign refuses it, and 403 without permission', function () {
    Http::fake([
        'docsign.se/api/parties*' => Http::response(['success' => true, 'party_id' => '1']),
        'docsign.se/api/documents*' => Http::response(['success' => false, 'message' => 'validation_errors', 'data' => ['attachments' => ['Fältet är obligatoriskt.']]]),
    ]);
    $payload = [
        'name' => 'Avtal',
        'files' => [['name' => 'a.pdf', 'content_base64' => base64_encode('%PDF-1.7')]],
        'signers' => [['name' => 'A', 'email' => 'a@example.com', 'sign_method' => 'bankid']],
    ];

    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/admin/signing-requests', $payload)->assertForbidden();

    Sanctum::actingAs(signingManager());
    $this->postJson('/api/v1/admin/signing-requests', $payload)->assertStatus(502)->assertJsonPath('data.status', 'failed');
});

it('pauses, resumes and cancels via the API, with 409 for the wrong status', function () {
    $request = SigningRequest::factory()->sent()->create(['provider_document_id' => '9001']);
    fakeDocumentEdit();
    $url = "/api/v1/admin/signing-requests/{$request->id}";

    Sanctum::actingAs(User::factory()->create());
    $this->postJson("{$url}/pause")->assertForbidden();

    Sanctum::actingAs(signingManager());
    $this->postJson("{$url}/resume")->assertStatus(409);
    $this->postJson("{$url}/pause")->assertOk()->assertJsonPath('data.status', 'paused');
    $this->postJson("{$url}/resume")->assertOk()->assertJsonPath('data.status', 'pending');
    $this->postJson("{$url}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("{$url}/cancel")->assertStatus(409);
});
