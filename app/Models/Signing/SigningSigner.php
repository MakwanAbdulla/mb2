<?php

namespace App\Models\Signing;

use App\Enums\SignMethod;
use App\Models\User;
use Database\Factories\Signing\SigningSignerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A signer as registered with DocSign ("party"). Reused across documents;
 * our prefixed id (externalId()) is sent to DocSign as `external_id` so retries never create duplicates.
 *
 * Either a user — member or not, the member is reached via user->member, so an
 * applicant stays linked when they become a member — or an external one-off
 * person (user_id null, matched on name plus e-mail or phone).
 *
 * DocSign requires an e-mail address or a phone number (or both).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property string|null $company
 * @property string|null $email
 * @property string|null $phone_number
 * @property SignMethod $sign_method
 * @property string|null $provider_party_id
 * @property Carbon|null $anonymized_at
 */
class SigningSigner extends Model
{
    /** @use HasFactory<SigningSignerFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'company',
        'email',
        'phone_number',
        'sign_method',
        'provider_party_id',
    ];

    public const ANONYMIZED_NAME = 'Anonymiserad undertecknare';

    protected function casts(): array
    {
        return [
            'sign_method' => SignMethod::class,
            'anonymized_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $signer) {
            if ($signer->anonymized_at === null && $signer->email === null && $signer->phone_number === null) {
                throw new InvalidArgumentException('A signer needs an e-mail address or a phone number.');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requestSigners(): HasMany
    {
        return $this->hasMany(SigningRequestSigner::class);
    }

    public function isExternal(): bool
    {
        return $this->user_id === null;
    }

    /**
     * Remove an external signer's personal data. The row stays because requests
     * reference it; the signed PDFs are kept as the archive. DocSign has no API to
     * delete a party, so it must be removed in their web UI (provider_party_id is
     * kept until someone marks that as done).
     */
    public function anonymize(): void
    {
        DB::transaction(function () {
            $this->forceFill([
                'name' => self::ANONYMIZED_NAME,
                'company' => null,
                'email' => null,
                'phone_number' => null,
                'anonymized_at' => now(),
            ])->save();

            $this->requestSigners()->update(['signed_by_name' => null, 'sign_url' => null]);
        });
    }

    public function isRegistered(): bool
    {
        return $this->provider_party_id !== null;
    }

    /**
     * Our id as sent to DocSign, prefixed per installation because environments
     * share one DocSign account (e.g. "membrum.local:12", "staging.example.se:12").
     */
    public function externalId(): string
    {
        return config('services.docsign.external_id_prefix').':'.$this->id;
    }

    /** Trimmed and lower-cased so matching is reliable; blank becomes null. */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => ($value === null || trim($value) === '')
            ? null
            : mb_strtolower(trim($value)));
    }

    /** DocSign wants international format: "0728-57 20 55" → "+46728572055". */
    protected function phoneNumber(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => self::normalizePhone($value));
    }

    public static function normalizePhone(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $digits = preg_replace('/[^\d+]/', '', $value);

        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            return '+46'.substr($digits, 1);
        }

        return str_starts_with($digits, '+') ? $digits : '+'.$digits;
    }

    /**
     * Signer to reuse for a person + method: the same user, or for an external
     * person the same name *and* the same e-mail or phone among signers without
     * a user — family members imported without their own account can share one
     * e-mail address, so e-mail alone isn't enough.
     */
    public function scopeMatching(Builder $query, ?int $userId, ?string $email, ?string $phone, string $name, SignMethod $method): Builder
    {
        $query->where('sign_method', $method);

        if ($userId !== null) {
            return $query->where('user_id', $userId);
        }

        $email = ($email === null || trim($email) === '') ? null : mb_strtolower(trim($email));
        $phone = self::normalizePhone($phone);

        if ($email === null && $phone === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereNull('user_id')
            ->where('name', $name)
            ->where(fn (Builder $q) => $q
                ->when($email !== null, fn (Builder $q) => $q->orWhere('email', $email))
                ->when($phone !== null, fn (Builder $q) => $q->orWhere('phone_number', $phone)));
    }
}
