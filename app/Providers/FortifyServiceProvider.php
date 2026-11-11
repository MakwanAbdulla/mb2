<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\LoginResponse;
use App\Models\User;
use App\Services\CogWorksService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\Laravel\Fortify\Contracts\LoginResponse::class, LoginResponse::class);

        // After registration, send new users to the membership apply page.
        $this->app->singleton(
            RegisterResponse::class,
            fn () => new class implements RegisterResponse
            {
                public function toResponse($request)
                {
                    return redirect()->intended(route('membership.apply'));
                }
            }
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        Fortify::loginView(fn () => view('auth.login'));
        Fortify::registerView(fn () => view('auth.register'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));

        // ── Custom authentication: CogWorks first, local password fallback ────
        Fortify::authenticateUsing(function (Request $request) {
            $email = $request->input('email');
            $password = $request->input('password');

            /** @var User|null $user */
            $user = User::where('email', $email)->first();

            // ── Try CogWorks if the user has (or can resolve) a username ──────
            $cogworks = app(CogWorksService::class);

            if ($user && ! $user->cogworks_username && $cogworks->isConfigured()) {
                $cwUser = $cogworks->findByEmail($user->email);
                if ($cwUser && ! empty($cwUser['username'])) {
                    $user->update(['cogworks_username' => $cwUser['username']]);
                    $user->refresh();
                }
            }

            if ($user?->cogworks_username) {
                $result = $cogworks->authenticate($user->cogworks_username, $password);

                if ($result && ! isset($result['error'])) {
                    // Success: capture password locally + refresh synced name/email
                    $updates = [
                        'password' => Hash::make($password),
                        'cogworks_synced_at' => now(),
                    ];

                    if ($result['name']) {
                        $updates['name'] = $result['name'];
                    }
                    if ($result['email']) {
                        $updates['email'] = $result['email'];
                    }

                    $user->update($updates);

                    Log::info('CogWorks login success', ['user_id' => $user->id]);

                    return $user;
                }

                if (isset($result['error'])) {
                    // Username may have changed — re-fetch and retry once
                    $cwUser = $cogworks->findByEmail($user->email);
                    if ($cwUser && ! empty($cwUser['username']) && $cwUser['username'] !== $user->cogworks_username) {
                        $user->update(['cogworks_username' => $cwUser['username']]);
                        $result = $cogworks->authenticate($cwUser['username'], $password);

                        if ($result && ! isset($result['error'])) {
                            $updates = [
                                'password' => Hash::make($password),
                                'cogworks_synced_at' => now(),
                            ];
                            if ($result['name']) {
                                $updates['name'] = $result['name'];
                            }
                            if ($result['email']) {
                                $updates['email'] = $result['email'];
                            }
                            $user->update($updates);
                            Log::info('CogWorks login success after username refresh', ['user_id' => $user->id]);

                            return $user;
                        }
                    }

                    Log::info('CogWorks login rejected', ['user_id' => $user->id, 'error' => $result['error'] ?? 'unknown']);

                    return null;
                }

                // $result === null means CogWorks was unreachable — fall through to local
                Log::warning('CogWorks unreachable, falling back to local auth', ['user_id' => $user?->id]);
            }

            // ── Local password fallback (native users or CogWorks offline) ─────
            if ($user && $user->password && Hash::check($password, $user->password)) {
                return $user;
            }

            return null;
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('reset-password', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });
    }
}
