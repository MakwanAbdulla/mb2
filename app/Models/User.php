<?php

namespace App\Models;

use App\Models\Membership\Member;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $password
 * @property string|null $cogworks_id
 * @property string|null $cogworks_username
 * @property string|null $avatar_path
 * @property string $ui_theme
 * @property string|null $ical_token
 * @property bool $reminder_emails
 * @property bool $notifications_via_email
 * @property Carbon|null $cogworks_synced_at
 * @property string|null $google_id
 * @property string|null $google_personal_email
 * @property string|null $discord_id
 * @property string|null $discord_username
 * @property Carbon|null $discord_linked_at
 * @property string|null $discord_access_token
 * @property string|null $discord_refresh_token
 * @property Carbon|null $discord_token_expires_at
 */
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    use HasRoles { hasPermissionTo as traitHasPermissionTo; }

    public const THEME_LIGHT = 'light';

    public const THEME_DARK = 'dark';

    public const THEME_SYSTEM = 'system';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'google_personal_email',
        'notification_email',
        'discord_id',
        'discord_username',
        'discord_linked_at',
        'discord_access_token',
        'discord_refresh_token',
        'discord_token_expires_at',
        'cogworks_id',
        'cogworks_username',
        'cogworks_synced_at',
        'avatar_path',
        'ui_theme',
        'ical_token',
        'reminder_emails',
        'notifications_via_email',
        'is_service_account',
        'pwa_first_seen_at',
        'pwa_last_seen_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'ical_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'pwa_first_seen_at' => 'datetime',
            'pwa_last_seen_at' => 'datetime',
            'cogworks_synced_at' => 'datetime',
            'discord_linked_at' => 'datetime',
            'discord_token_expires_at' => 'datetime',
            'password' => 'hashed',
            'reminder_emails' => 'boolean',
            'notifications_via_email' => 'boolean',
            'is_service_account' => 'boolean',
        ];
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    // ── Relations ────────────────────────────────────────────────────────────

    public function member(): HasOne
    {
        return $this->hasOne(Member::class);
    }

    // ── Permission helpers ─────────────────────────────────────────────────────

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    public function hasPermissionTo($permission, $guardName = null): bool
    {
        // A scoped API token narrows what the user may do — even for admins.
        if (! $this->tokenAllowsPermission($permission)) {
            return false;
        }

        if ($this->hasRole('admin')) {
            return true;
        }

        return $this->traitHasPermissionTo($permission, $guardName);
    }

    /**
     * API client tokens carry the client's scopes as Sanctum abilities. When the current
     * token is restricted (no '*'), a permission must be in its scopes as well as held by
     * the user. Sessions, SPA cookies and unrestricted tokens are unaffected.
     */
    protected function tokenAllowsPermission($permission): bool
    {
        $token = $this->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return true;
        }

        $abilities = $token->abilities;

        if (! is_array($abilities) || in_array('*', $abilities, true)) {
            return true;
        }

        $name = match (true) {
            $permission instanceof Permission => $permission->name,
            $permission instanceof \BackedEnum => (string) $permission->value,
            is_int($permission) => Permission::find($permission)?->name,
            default => (string) $permission,
        };

        // api.access is the gate to the API itself, not something a scope should withhold.
        return $name === 'api.access' || in_array($name, $abilities, true);
    }

    /** Any admin-area access — used to show the admin nav section */
    public function isStaff(): bool
    {
        return $this->hasAnyRole(['admin', 'treasurer', 'member-manager', 'booking-admin', 'website-editor', 'event-organizer', 'shop-manager', 'styrelse', 'revisor', 'sekreterare'])
            || $this->hasAnyPermission([
                'members.view', 'members.edit', 'members.manage', 'membership-periods.manage',
                'payments.view', 'payments.confirm', 'payments.import',
                'bookings.manage', 'bookings.categories', 'bookings.applications', 'bookings.statistics', 'bookings.super',
                'cms.edit', 'cms.publish', 'notices.manage', 'help.manage',
                'events.view', 'events.create', 'events.manage-own', 'events.manage-all', 'events.check-in', 'events.economy',
                'shop.manage',
                'accounting.view', 'accounting.manage', 'accounting.admin', 'accounting.approve',
                'forms.manage', 'surveys.manage', 'mailings.manage', 'statistics.view',
                'meetings.view', 'meetings.manage',
                'signing.create', 'signing.view', 'signing.manage',
                'wiki.manage',
                'sections.manage', 'org-roles.manage',
                'workspace.manage',
                'settings.manage', 'users.manage-roles', 'audit.view',
                'gamification.manage',
                'tournaments.view', 'tournaments.manage',
            ]);
    }

    public function isMember(): bool
    {
        return $this->member?->hasFullAccess() ?? false;
    }

    /** Room booking (create, view and edit own bookings) requires membership. */
    public function canBook(): bool
    {
        return $this->isAdmin() || $this->isMember();
    }

    public function isSuperBooker(): bool
    {
        return $this->hasPermissionTo('bookings.super');
    }

    // ── UI helpers ────────────────────────────────────────────────────────────

    public function prefersLight(): bool
    {
        return $this->ui_theme === self::THEME_LIGHT;
    }

    public function prefersDark(): bool
    {
        return $this->ui_theme === self::THEME_DARK;
    }

    // ── Notification email + preferences ─────────────────────────────────────

    public function notificationEmail(): string
    {
        return $this->notification_email ?? $this->email;
    }

    public function wantsEmailForNotifications(): bool
    {
        return $this->notifications_via_email ?? true;
    }

    // ── Avatar ────────────────────────────────────────────────────────────────

    public function avatarUrl(int $size = 80): string
    {
        if ($this->avatar_path) {
            return asset('storage/'.$this->avatar_path);
        }

        $hash = md5(strtolower(trim($this->email)));

        return "https://www.gravatar.com/avatar/{$hash}?s={$size}&d=identicon";
    }

    // ── iCal token ───────────────────────────────────────────────────────────

    public function generateIcalToken(): string
    {
        $this->ical_token = Str::random(48);
        $this->save();

        return $this->ical_token;
    }
}
