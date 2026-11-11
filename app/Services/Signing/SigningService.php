<?php

namespace App\Services\Signing;

use App\Enums\SigningEventType;
use App\Enums\SigningStatus;
use App\Enums\SignMethod;
use App\Events\SigningRequestCompleted;
use App\Models\Signing\SigningRequest;
use App\Models\Signing\SigningRequestSigner;
use App\Models\Signing\SigningSigner;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\SigningBalanceLowNotification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Throwable;

/**
 * Sends signing requests to DocSign and keeps them in sync with DocSign's state.
 * Status is always fetched from the API — never taken from a callback.
 */
class SigningService
{
    public const BALANCE_SETTING = 'docsign.balance_ore';

    public const BALANCE_CHECKED_SETTING = 'docsign.balance_checked_at';

    public const LOW_BALANCE_SETTING = 'docsign.low_balance_ore';

    public const LOW_BALANCE_NOTIFIED_SETTING = 'docsign.low_balance_notified_at';

    public const SIGNER_NOTICE_SETTING = 'docsign.signer_notice';

    /** Observed 2026-10-23: 1 kr + moms per signature, charged when signed. */
    public const PRICE_PER_SIGNATURE_ORE = 125;

    public function __construct(private readonly DocsignClient $client) {}

    /**
     * Register the signer with DocSign unless already registered. Our prefixed id is
     * sent as `external_id`, which DocSign uses to prevent duplicates on retry.
     */
    public function registerSigner(SigningSigner $signer): string
    {
        if ($signer->isRegistered()) {
            return $signer->provider_party_id;
        }

        try {
            $partyId = $this->client->createParty([
                'name' => $signer->name,
                'company' => $signer->company,
                'email' => $signer->email,
                'phone_number' => $signer->phone_number,
                'sign_method' => $signer->sign_method->value,
                'external_id' => $signer->externalId(),
            ]);
        } catch (DocsignException $e) {
            // A retry after a half-finished create may be refused as a duplicate:
            // look the party up by our external_id before giving up.
            $partyId = collect($this->client->listParties())->firstWhere('external_id', $signer->externalId())['id'] ?? null;

            if ($partyId === null) {
                throw $e;
            }
        }

        $signer->forceFill(['provider_party_id' => $partyId])->save();

        return $partyId;
    }

    /**
     * Create a draft request with its files and signers. A signer whose e-mail belongs
     * to a Membrum user is linked to that user; otherwise it's an external signer.
     * Existing signers are reused (see SigningSigner::scopeMatching).
     *
     * @param  list<array{name: string, email?: ?string, phone?: ?string, sign_method: string, role_label?: ?string}>  $signers
     * @param  list<array{name: string, contents: string}>  $files  PDF contents
     */
    public function createDraft(string $name, ?string $comment, array $signers, array $files, ?int $createdBy, bool $sendReminders = true, bool $sendReceipt = true): SigningRequest
    {
        return DB::transaction(function () use ($name, $comment, $signers, $files, $createdBy, $sendReminders, $sendReceipt) {
            $request = SigningRequest::create([
                'name' => trim($name),
                'comment' => trim((string) $comment) ?: null,
                'is_demo' => (bool) config('services.docsign.demo'),
                'send_reminders' => $sendReminders,
                'send_receipt' => $sendReceipt,
                'created_by' => $createdBy,
            ]);
            $request->recordEvent(SigningEventType::Created);

            foreach (array_values($files) as $i => $file) {
                $path = "signing/{$request->id}/".Str::uuid().'.pdf';
                Storage::disk('local')->put($path, $file['contents']);
                $request->files()->create(['name' => $file['name'], 'disk' => 'local', 'path' => $path, 'sort_order' => $i]);
            }

            foreach (array_values($signers) as $i => $row) {
                $email = trim((string) ($row['email'] ?? '')) ?: null;
                $phone = trim((string) ($row['phone'] ?? '')) ?: null;
                $method = SignMethod::from($row['sign_method']);
                $userId = $email ? User::where('email', mb_strtolower($email))->value('id') : null;

                $signer = SigningSigner::matching($userId, $email, $phone, trim($row['name']), $method)->first()
                    ?? SigningSigner::create([
                        'user_id' => $userId,
                        'name' => trim($row['name']),
                        'email' => $email,
                        'phone_number' => $phone,
                        'sign_method' => $method,
                    ]);

                $request->signers()->create([
                    'signing_signer_id' => $signer->id,
                    'role_label' => trim((string) ($row['role_label'] ?? '')) ?: null,
                    'sort_order' => $i,
                ]);
            }

            return $request;
        });
    }

    /** Send a draft request with its files and signers. */
    public function send(SigningRequest $request): SigningRequest
    {
        $request->loadMissing(['files', 'signers.signer']);

        if ($request->status !== SigningStatus::Draft) {
            throw new DocsignException('Ärendet är redan skickat.');
        }

        try {
            if (! $request->is_demo) {
                $this->ensureBalanceFor($request->signers->count());
            }

            $token = $request->issueCallbackToken();
            $payload = $this->documentPayload($request, $token);

            try {
                $documentId = $this->createDocumentOrRecover($payload);
            } catch (DocsignException $e) {
                // "parties.N är ogiltigt": the party was deleted at DocSign (e.g. in their
                // web UI). Register those signers again and retry once.
                if ($e->invalidPartyIndexes() === []) {
                    throw $e;
                }

                foreach ($e->invalidPartyIndexes() as $index) {
                    $request->signers[$index]?->signer->forceFill(['provider_party_id' => null])->save();
                }

                $payload = $this->documentPayload($request, $token);
                $documentId = $this->createDocumentOrRecover($payload);
            }
        } catch (Throwable $e) {
            $message = $e instanceof DocsignException ? $e->summary() : $e->getMessage();
            $request->forceFill(['status' => SigningStatus::Failed, 'last_error' => $message])->save();
            $request->recordEvent(SigningEventType::Error, ['stage' => 'send', 'message' => $message]);

            throw $e;
        }

        $request->forceFill([
            'status' => SigningStatus::Pending,
            'provider_document_id' => $documentId,
            'sent_at' => now(),
            'last_error' => null,
        ])->save();

        // Payload is logged without attachments' content (scrubbed by the event model)
        // and without the callback token (the URLs contain it).
        unset($payload['callback_url'], $payload['callback_sign_url']);
        $request->recordEvent(SigningEventType::Sent, ['document_id' => $documentId] + $payload);

        return $request;
    }

    /**
     * Fetch the document from DocSign and update signers, status and the signed PDF.
     * Idempotent: callbacks and the scheduled sync may run it for the same request
     * at nearly the same time.
     */
    public function sync(SigningRequest $request): void
    {
        if ($request->provider_document_id === null) {
            return;
        }

        // The signed and completed callbacks arrive ~2 s apart and the scheduled sync
        // may overlap: serialize per request so the PDF is fetched and the completed
        // event dispatched once.
        $this->locked($request, function (SigningRequest $request) {
            if ($request->status !== SigningStatus::Cancelled) {
                $this->syncLocked($request);
            }
        });
    }

    public function pause(SigningRequest $request): void
    {
        $this->changeState($request, SigningStatus::Pending, 'pause', SigningStatus::Paused, SigningEventType::Paused);
    }

    public function resume(SigningRequest $request): void
    {
        $this->changeState($request, SigningStatus::Paused, 'resume', SigningStatus::Pending, SigningEventType::Resumed);
    }

    /**
     * Withdraw a request. DocSign only deletes paused (or completed) documents, so a
     * pending one is paused first. Deleting removes the document and its data at
     * DocSign; our copies of the files and the event log stay.
     */
    public function cancel(SigningRequest $request): void
    {
        $this->locked($request, function (SigningRequest $request) {
            if (in_array($request->status, [SigningStatus::Completed, SigningStatus::Cancelled], true)) {
                throw new DocsignException('Ett färdigsignerat eller avbrutet ärende kan inte avbrytas.');
            }

            if ($request->provider_document_id !== null) {
                if ($request->status === SigningStatus::Pending) {
                    $this->client->editDocument($request->provider_document_id, 'pause');
                    $request->forceFill(['status' => SigningStatus::Paused])->save();
                }

                try {
                    $this->client->editDocument($request->provider_document_id, 'delete');
                } catch (DocsignException $e) {
                    if ($e->status !== 404) { // 404: already deleted at DocSign
                        throw $e;
                    }
                }

                // Sync skips cancelled requests, so a delete that silently failed would
                // leave a signable document nobody watches. Other actions are left to sync.
                if ($this->client->findDocument($request->provider_document_id) !== null) {
                    throw new DocsignException('DocSign svarade att dokumentet raderats, men det finns kvar. Försök igen.');
                }
            }

            $request->forceFill(['status' => SigningStatus::Cancelled, 'cancelled_at' => now(), 'last_error' => null])->save();
            $request->recordEvent(SigningEventType::Cancelled);
        });
    }

    /** Delete an (anonymized) signer at DocSign. Already gone there counts as done. */
    public function deleteSignerAtDocsign(SigningSigner $signer): void
    {
        if ($signer->provider_party_id === null) {
            return;
        }

        $this->client->deleteParty($signer->provider_party_id);
        $signer->forceFill(['provider_party_id' => null])->save();
    }

    private function changeState(SigningRequest $request, SigningStatus $from, string $action, SigningStatus $to, SigningEventType $event): void
    {
        $this->locked($request, function (SigningRequest $request) use ($from, $action, $to, $event) {
            if ($request->status !== $from || $request->provider_document_id === null) {
                throw new DocsignException("Ärendet har inte status {$from->label()}.");
            }

            $this->client->editDocument($request->provider_document_id, $action);
            $request->forceFill(['status' => $to])->save();
            $request->recordEvent($event);
        });
    }

    /** Run $callback on a fresh copy under the request's lock, then refresh $request. */
    private function locked(SigningRequest $request, callable $callback): void
    {
        try {
            Cache::lock("signing-sync-{$request->id}", 60)->block(30, fn () => $callback($request->fresh()));
        } finally {
            $request->refresh();
        }
    }

    private function syncLocked(SigningRequest $request): void
    {
        $request->loadMissing('signers.signer');
        $document = $this->client->findDocument($request->provider_document_id);

        if ($document === null) {
            $request->forceFill(['last_synced_at' => now(), 'last_error' => 'Dokumentet finns inte längre hos DocSign.'])->save();
            $request->recordEvent(SigningEventType::Error, ['stage' => 'sync', 'message' => 'document not found', 'document_id' => $request->provider_document_id]);

            return;
        }

        foreach ($document['parties'] as $party) {
            $requestSigner = $request->signers->first(fn (SigningRequestSigner $s) => $s->signer->provider_party_id === $party['id']);

            if ($requestSigner !== null) {
                $this->syncSigner($request, $requestSigner, $party);
            }
        }

        $previous = $request->status;
        $status = SigningStatus::fromProviderState($document['state']);
        $request->status = $status;
        $request->last_synced_at = now();

        if ($status === SigningStatus::Completed && $request->completed_at === null) {
            $request->completed_at = now();
        }

        $request->save();

        if ($status !== $previous) {
            $request->recordEvent(match ($status) {
                SigningStatus::Completed => SigningEventType::Completed,
                SigningStatus::Paused => SigningEventType::Paused,
                default => SigningEventType::Resumed,
            }, ['state' => $document['state']]);
        }

        // Also retried on later syncs if the download failed the first time.
        if ($status === SigningStatus::Completed && $request->signed_path === null && $document['signed_pdf_url'] !== null) {
            if ($this->downloadSignedPdf($request, $document['signed_pdf_url'])) {
                SigningRequestCompleted::dispatch($request);
            }
        }
    }

    /** Fetch and store DocSign's account balance (öre). */
    public function refreshBalance(): int
    {
        $balance = $this->client->account()['balance_ore'];

        SystemSetting::set(self::BALANCE_SETTING, $balance, SystemSetting::TYPE_INTEGER);
        SystemSetting::set(self::BALANCE_CHECKED_SETTING, now()->toIso8601String());

        $threshold = self::lowBalanceThreshold();

        if ($balance < $threshold) {
            Log::warning('DocSign balance is low', ['balance_ore' => $balance]);
            $this->notifyLowBalance($balance, $threshold);
        }

        return $balance;
    }

    public static function lowBalanceThreshold(): int
    {
        return (int) SystemSetting::get(self::LOW_BALANCE_SETTING, config('services.docsign.low_balance_ore'));
    }

    /** At most once a day, to everyone who can manage signing. */
    private function notifyLowBalance(int $balance, int $threshold): void
    {
        $last = SystemSetting::get(self::LOW_BALANCE_NOTIFIED_SETTING);
        if ($last && Carbon::parse($last)->gt(now()->subDay())) {
            return;
        }

        if (! Permission::where('name', 'signing.manage')->exists()) {
            return;
        }

        Notification::send(User::permission('signing.manage')->get(), new SigningBalanceLowNotification($balance, $threshold));
        SystemSetting::set(self::LOW_BALANCE_NOTIFIED_SETTING, now()->toIso8601String());
    }

    /**
     * Update our copies of signers changed in DocSign's web UI, matched on external_id.
     * Signers missing from the list are left alone — the list may be paginated, so
     * absence doesn't prove deletion. Deleted parties are re-registered on send().
     */
    public function syncSigners(): int
    {
        $parties = collect($this->client->listParties())->whereNotNull('external_id')->keyBy('external_id');
        $updated = 0;

        // Anonymized signers are skipped: DocSign still holds their data until it's deleted
        // there, and syncing would put the personal data back.
        SigningSigner::whereNotNull('provider_party_id')->whereNull('anonymized_at')->each(function (SigningSigner $signer) use ($parties, &$updated) {
            $party = $parties->get($signer->externalId());

            if ($party === null) {
                return;
            }

            $signer->fill([
                'name' => $party['name'] ?? $signer->name,
                'company' => $party['company'] ?? null,
                'email' => $party['email'] ?? null,
                'phone_number' => $party['phone_number'] ?? null,
            ]);

            if ($signer->isDirty() && ($signer->email !== null || $signer->phone_number !== null)) {
                $signer->save();
                $updated++;
            }
        });

        return $updated;
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    private function ensureBalanceFor(int $signers): void
    {
        $needed = $signers * self::PRICE_PER_SIGNATURE_ORE;
        $balance = $this->refreshBalance();

        if ($balance < $needed) {
            throw new DocsignException('Saldot hos DocSign räcker inte: '.format_ore($needed).' behövs, '.format_ore($balance).' finns. Fyll på hos Inleed.');
        }
    }

    /**
     * Documents have no idempotency key at DocSign. If the create call times out it
     * may still have gone through, so look for it before letting the error through —
     * a blind retry would send the document to the signers twice. The name carries our
     * reference; time and parties guard against a six-character collision.
     */
    private function createDocumentOrRecover(array $payload): string
    {
        $startedAt = now()->subMinute();

        try {
            return $this->client->createDocument($payload);
        } catch (ConnectionException $e) {
            $parties = collect($payload['parties'])->map(fn ($id) => (string) $id)->sort()->values()->all();

            $match = collect($this->client->listDocuments())->first(fn (array $document) => in_array($document['name'], [$payload['name'], '[DEMO] '.$payload['name']], true)
                && self::parseProviderTime($document['created_at'])->gte($startedAt)
                && collect($document['parties'])->pluck('id')->sort()->values()->all() === $parties);

            if ($match === null) {
                throw $e;
            }

            return $match['id'];
        }
    }

    private function documentPayload(SigningRequest $request, string $token): array
    {
        $partyIds = $request->signers->map(fn (SigningRequestSigner $s) => $this->registerSigner($s->signer))->all();
        $callback = fn (string $event) => route('docsign.callback', [
            'signingRequest' => $request->id,
            'token' => $token,
            'event' => $event,
        ]);

        $notice = trim((string) SystemSetting::get(self::SIGNER_NOTICE_SETTING, config('services.docsign.signer_notice')));

        return [
            'name' => $request->providerName(),
            'comment' => trim(implode("\n\n", array_filter([$request->comment, $notice]))) ?: null,
            'parties' => array_map('intval', $partyIds),
            'is_demo' => $request->is_demo,
            'send_reminders' => $request->send_reminders,
            'send_receipt' => $request->send_receipt,
            'send_notifications' => false, // Membrum sends its own notifications
            'callback_url' => $callback('completed'),
            'callback_sign_url' => $callback('signed'),
            'attachments' => $request->files->map(fn ($file) => [
                'name' => $file->name,
                'base64_content' => base64_encode(Storage::disk($file->disk)->get($file->path)),
            ])->all(),
        ];
    }

    /** @param  array<string, mixed>  $party  A normalized party from GET /documents */
    private function syncSigner(SigningRequest $request, SigningRequestSigner $requestSigner, array $party): void
    {
        if (! empty($party['sign_url'])) {
            $requestSigner->sign_url = $party['sign_url'];
        }

        $newlySigned = ! empty($party['signed_at']) && $requestSigner->signed_at === null;

        if ($newlySigned) {
            $requestSigner->signed_at = self::parseProviderTime($party['signed_at']);
            $requestSigner->signed_by_name = $party['signed_by_name'] ?? null;
        }

        if ($requestSigner->isDirty()) {
            $requestSigner->save();
        }

        if ($newlySigned) {
            $request->recordEvent(SigningEventType::SignerSigned, [
                'party_id' => $party['id'],
                'signed_at' => $requestSigner->signed_at->toIso8601String(),
            ], $requestSigner);
        }
    }

    private function downloadSignedPdf(SigningRequest $request, string $url): bool
    {
        try {
            $response = Http::timeout(60)->get($url);

            if (! $response->successful() || ! str_starts_with($response->body(), '%PDF')) {
                throw new DocsignException("Kunde inte hämta signerad PDF (HTTP {$response->status()}).");
            }

            $request->storeSignedPdf($response->body());

            return true;
        } catch (Throwable $e) {
            $request->forceFill(['last_error' => $e->getMessage()])->save();
            $request->recordEvent(SigningEventType::Error, ['stage' => 'download_signed_pdf', 'message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * DocSign mixes formats: `signed_at` is ISO 8601 in UTC ("…T20:39:17.000000Z"),
     * `created_at` is Swedish local time without a zone ("2026-10-23 22:31:01").
     * Returned in the app's timezone, since datetime columns are stored without one.
     */
    public static function parseProviderTime(string $value): Carbon
    {
        $hasZone = str_contains($value, 'T') || preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value);

        return ($hasZone ? Carbon::parse($value) : Carbon::parse($value, 'Europe/Stockholm'))
            ->setTimezone(config('app.timezone'));
    }
}
