<?php

namespace App\Models\Signing;

use App\Enums\SigningEventType;
use Database\Factories\Signing\SigningRequestEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only log per signing request. Payloads are scrubbed of personnummer
 * (DocSign's `signed_by_id`) and signing links before they are stored.
 *
 * @property int $id
 * @property int $signing_request_id
 * @property int|null $signing_request_signer_id
 * @property SigningEventType $type
 * @property array|null $payload
 * @property Carbon $created_at
 */
class SigningRequestEvent extends Model
{
    /** @use HasFactory<SigningRequestEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** Keys removed from any payload, at any depth. */
    public const REDACTED_KEYS = ['signed_by_id', 'sign_url', 'api_key', 'base64_content'];

    protected $fillable = [
        'signing_request_id',
        'signing_request_signer_id',
        'type',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'type' => SigningEventType::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $event) {
            if (is_array($event->payload)) {
                $event->payload = self::redact($event->payload);
            }
        });
    }

    /** @param  array<mixed>  $payload */
    public static function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (in_array($key, self::REDACTED_KEYS, true)) {
                unset($payload[$key]);
            } elseif (is_array($value)) {
                $payload[$key] = self::redact($value);
            }
        }

        return $payload;
    }

    public function signingRequest(): BelongsTo
    {
        return $this->belongsTo(SigningRequest::class);
    }

    public function requestSigner(): BelongsTo
    {
        return $this->belongsTo(SigningRequestSigner::class, 'signing_request_signer_id');
    }
}
