<?php

namespace App\Models\Signing;

use App\Enums\SigningEventType;
use App\Enums\SigningStatus;
use App\Models\User;
use Database\Factories\Signing\SigningRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A document sent (or to be sent) for e-signature.
 *
 * @property int $id
 * @property string|null $signable_type
 * @property int|null $signable_id
 * @property string $name
 * @property string|null $reference
 * @property string|null $comment
 * @property SigningStatus $status
 * @property string $provider
 * @property string|null $provider_document_id
 * @property bool $send_reminders
 * @property bool $send_receipt
 * @property bool $is_demo
 * @property string|null $callback_token_hash
 * @property string|null $signed_disk
 * @property string|null $signed_path
 * @property string|null $signed_sha256
 * @property string|null $last_error
 * @property int|null $created_by
 * @property Carbon|null $sent_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $last_synced_at
 */
class SigningRequest extends Model
{
    /** @use HasFactory<SigningRequestFactory> */
    use HasFactory;

    public const PROVIDER_DOCSIGN = 'docsign';

    protected $fillable = [
        'signable_type',
        'signable_id',
        'name',
        'comment',
        'status',
        'provider',
        'provider_document_id',
        'send_reminders',
        'send_receipt',
        'is_demo',
        'signed_disk',
        'signed_path',
        'signed_sha256',
        'last_error',
        'created_by',
        'sent_at',
        'completed_at',
        'cancelled_at',
        'last_synced_at',
    ];

    protected $hidden = ['callback_token_hash'];

    // Mirror the column defaults so a freshly created model knows them without a refresh.
    protected $attributes = [
        'status' => 'draft',
        'provider' => self::PROVIDER_DOCSIGN,
        'send_reminders' => true,
        'send_receipt' => true,
        'is_demo' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => SigningStatus::class,
            'send_reminders' => 'boolean',
            'send_receipt' => 'boolean',
            'is_demo' => 'boolean',
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (SigningRequest $request) {
            $request->forceFill(['reference' => self::computeReference($request->id, $request->created_at)])->saveQuietly();
        });
    }

    /**
     * Documents have no reference field at DocSign, so this goes in the name. Derived
     * from values that never change (the id alone would repeat across environments
     * sharing the DocSign account), stored so it can be searched.
     */
    public static function computeReference(int $id, Carbon $createdAt): string
    {
        return substr(sha1($id.'|'.$createdAt->format('Y-m-d H:i:s')), 0, 6);
    }

    /** The name as sent to DocSign: "Protokoll 12/3 [#3f9a1c]". */
    public function providerName(): string
    {
        return $this->reference ? "{$this->name} [#{$this->reference}]" : $this->name;
    }

    public function signable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function files(): HasMany
    {
        return $this->hasMany(SigningRequestFile::class)->orderBy('sort_order');
    }

    public function signers(): HasMany
    {
        return $this->hasMany(SigningRequestSigner::class)->orderBy('sort_order');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SigningRequestEvent::class)->latest('created_at')->latest('id');
    }

    /**
     * signing.view sees every request, signing.create only the ones they created.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->can('signing.view') ? $query : $query->where('created_by', $user->id);
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->can('signing.view') || $this->isOwnedBy($user);
    }

    /** Pause, resume, cancel, sync. Signing links and settings stay signing.manage only. */
    public function isManageableBy(User $user): bool
    {
        return $user->can('signing.manage') || $this->isOwnedBy($user);
    }

    private function isOwnedBy(User $user): bool
    {
        return $user->can('signing.create') && $this->created_by === $user->id;
    }

    public static function canBeCreatedBy(User $user): bool
    {
        return $user->can('signing.create') || $user->can('signing.manage');
    }

    /** Requests the scheduled sync should poll DocSign for. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [SigningStatus::Pending, SigningStatus::Paused]);
    }

    public function signedCount(): int
    {
        return $this->signers->whereNotNull('signed_at')->count();
    }

    /**
     * Generate a fresh callback token, store only its hash, and return the plain
     * token for building the callback URLs. The plain token is never persisted.
     */
    public function issueCallbackToken(): string
    {
        $token = Str::random(40);
        $this->forceFill(['callback_token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    /**
     * Store the signed PDF and its SHA-256. DocSign's signed PDF carries no digital
     * signature, so the hash is our evidence that the file hasn't changed since.
     */
    public function storeSignedPdf(string $contents, string $disk = 'local'): void
    {
        $path = "signing/{$this->id}/signed-".now()->format('YmdHis').'.pdf';
        Storage::disk($disk)->put($path, $contents);

        $this->forceFill([
            'signed_disk' => $disk,
            'signed_path' => $path,
            'signed_sha256' => hash('sha256', $contents),
        ])->save();
    }

    /** True if the stored signed PDF still matches its recorded hash; null if there is none. */
    public function signedPdfIsIntact(): ?bool
    {
        if ($this->signed_path === null || $this->signed_sha256 === null) {
            return null;
        }

        $disk = Storage::disk($this->signed_disk ?? 'local');

        return $disk->exists($this->signed_path)
            && hash_equals($this->signed_sha256, hash('sha256', $disk->get($this->signed_path)));
    }

    public function callbackTokenMatches(string $token): bool
    {
        return $this->callback_token_hash !== null
            && hash_equals($this->callback_token_hash, hash('sha256', $token));
    }

    public function recordEvent(SigningEventType $type, ?array $payload = null, ?SigningRequestSigner $signer = null): SigningRequestEvent
    {
        return $this->events()->create([
            'type' => $type,
            'payload' => $payload,
            'signing_request_signer_id' => $signer?->id,
        ]);
    }
}
