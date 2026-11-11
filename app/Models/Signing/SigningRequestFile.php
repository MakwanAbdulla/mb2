<?php

namespace App\Models\Signing;

use Database\Factories\Signing\SigningRequestFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An attachment sent to DocSign. `name` is the filename the signer sees.
 *
 * @property int $id
 * @property int $signing_request_id
 * @property string $name
 * @property string $disk
 * @property string $path
 * @property int $sort_order
 */
class SigningRequestFile extends Model
{
    /** @use HasFactory<SigningRequestFileFactory> */
    use HasFactory;

    protected $fillable = [
        'signing_request_id',
        'name',
        'disk',
        'path',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function signingRequest(): BelongsTo
    {
        return $this->belongsTo(SigningRequest::class);
    }
}
