<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ApiClient extends Model
{
    protected $fillable = [
        'name',
        'client_id',
        'client_secret',
        'scopes',
        'enabled',
        'user_id',
        'last_used_at',
    ];

    protected $hidden = ['client_secret'];

    protected $casts = [
        'scopes'       => 'array',
        'enabled'      => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifySecret(string $plainSecret): bool
    {
        return Hash::check($plainSecret, $this->client_secret);
    }

    /**
     * Create a new API client with its service-account user.
     * Returns ['client' => ApiClient, 'plain_secret' => string].
     */
    public static function provision(string $name, ?array $scopes = null): array
    {
        $clientId    = (string) Str::uuid();
        $plainSecret = Str::random(48);

        $user = User::create([
            'name'               => $name,
            'email'              => "api-{$clientId}@service.internal",
            'password'           => Hash::make(Str::random(64)),
            'is_service_account' => true,
        ]);

        $user->givePermissionTo('api.access');

        $client = static::create([
            'name'          => $name,
            'client_id'     => $clientId,
            'client_secret' => Hash::make($plainSecret),
            'scopes'        => $scopes,
            'user_id'       => $user->id,
        ]);

        return ['client' => $client, 'plain_secret' => $plainSecret];
    }

    /**
     * Regenerate the client secret. Returns the new plain secret.
     * Revokes all existing Sanctum tokens for the service user.
     */
    public function regenerateSecret(): string
    {
        $this->user->tokens()->delete();

        $plain = Str::random(48);
        $this->update(['client_secret' => Hash::make($plain)]);

        return $plain;
    }
}
