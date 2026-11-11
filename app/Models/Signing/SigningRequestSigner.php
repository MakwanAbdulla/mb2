<?php

namespace App\Models\Signing;

use Database\Factories\Signing\SigningRequestSignerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A signer on a specific request. `signed_by_name` is the name per BankID, which
 * may differ from the name we registered. DocSign's `signed_by_id` (personnummer)
 * is deliberately never stored — it lives in the sealed PDF.
 *
 * @property int $id
 * @property int $signing_request_id
 * @property int $signing_signer_id
 * @property string|null $role_label
 * @property int $sort_order
 * @property Carbon|null $signed_at
 * @property string|null $signed_by_name
 * @property string|null $sign_url
 */
class SigningRequestSigner extends Model
{
    /** @use HasFactory<SigningRequestSignerFactory> */
    use HasFactory;

    protected $fillable = [
        'signing_request_id',
        'signing_signer_id',
        'role_label',
        'sort_order',
        'signed_at',
        'signed_by_name',
        'sign_url',
    ];

    // Whoever has the sign_url can sign — never expose it by accident.
    protected $hidden = ['sign_url'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'signed_at' => 'datetime',
            'sign_url' => 'encrypted',
        ];
    }

    public function signingRequest(): BelongsTo
    {
        return $this->belongsTo(SigningRequest::class);
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(SigningSigner::class, 'signing_signer_id');
    }

    public function hasSigned(): bool
    {
        return $this->signed_at !== null;
    }
}
