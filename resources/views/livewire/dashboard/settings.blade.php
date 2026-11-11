<?php

use App\Models\AuditLog;
use App\Models\Content\MailingCategoryOptout;
use App\Models\Membership\AccountDeletionRequest;
use App\Services\GdprExportService;
use App\Support\NotificationCategory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Validator;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public $avatar = null;

    public string $current_password      = '';
    public string $password              = '';
    public string $password_confirmation = '';

    public string $theme               = 'system';
    public string $flashAvatar        = '';
    public string $flashPassword      = '';
    public string $flashNotifications = '';
    public string $flashGoogle        = '';
    public string $flashDiscord       = '';

    // GDPR deletion request
    public string $deletionReason = '';
    public bool   $deletionRequested = false;

    #[\Livewire\Attributes\Computed]
    public function gdprCounts(): array
    {
        return app(GdprExportService::class)->summaryCounts(auth()->user());
    }

    public function mount(): void
    {
        $this->flashGoogle   = session('flash_google', '');
        $this->flashDiscord  = session('flash_discord', '');
        $this->theme = auth()->user()->ui_theme ?? \App\Models\User::THEME_SYSTEM;

        $member = auth()->user()->member ?? null;
        $this->deletionRequested = $member
            ? AccountDeletionRequest::where('member_id', $member->id)
                ->where('status', AccountDeletionRequest::STATUS_PENDING)
                ->exists()
            : false;
    }

    public function setTheme(string $theme): void
    {
        if (! in_array($theme, [\App\Models\User::THEME_LIGHT, \App\Models\User::THEME_DARK, \App\Models\User::THEME_SYSTEM])) {
            return;
        }
        auth()->user()->update(['ui_theme' => $theme]);
        $this->theme = $theme;
        $this->js(match($theme) {
            'dark'   => "localStorage.setItem('ui_theme','dark'); document.documentElement.classList.add('dark');",
            'light'  => "localStorage.setItem('ui_theme','light'); document.documentElement.classList.remove('dark');",
            default  => "localStorage.setItem('ui_theme','system'); document.documentElement.classList.toggle('dark', window.matchMedia('(prefers-color-scheme: dark)').matches);",
        });
    }

    public function toggleNotificationsViaEmail(): void
    {
        $user = auth()->user();
        $user->update(['notifications_via_email' => ! $user->notifications_via_email]);
        $this->flashNotifications = $user->notifications_via_email
            ? 'Du får nu e-post för aviseringar.'
            : 'Du får inte längre e-post för aviseringar.';
    }

    public function setNotificationEmail(string $email): void
    {
        $user    = auth()->user();
        $allowed = array_filter([$user->email, $user->google_personal_email]);

        if (! in_array($email, $allowed, true)) {
            return;
        }

        $user->update(['notification_email' => $email === $user->email ? null : $email]);
        $this->flashGoogle = 'Föredragen e-postadress uppdaterad.';
    }

    public function requestDeletion(): void
    {
        $user   = auth()->user();
        $member = $user->member;

        if (! $member) {
            return;
        }

        // Prevent duplicate requests
        if (AccountDeletionRequest::where('member_id', $member->id)->where('status', AccountDeletionRequest::STATUS_PENDING)->exists()) {
            return;
        }

        AccountDeletionRequest::create([
            'member_id' => $member->id,
            'user_id'   => $user->id,
            'reason'    => trim($this->deletionReason) ?: null,
            'status'    => AccountDeletionRequest::STATUS_PENDING,
        ]);

        AuditLog::record('account_deletion_requested', 'members', auth()->user()->email ?? 'okänd', auth()->user()->member);
        $this->deletionRequested = true;
        $this->deletionReason    = '';
    }

    public function cancelDeletionRequest(): void
    {
        $member = auth()->user()->member;

        if (! $member) {
            return;
        }

        AccountDeletionRequest::where('member_id', $member->id)
            ->where('status', AccountDeletionRequest::STATUS_PENDING)
            ->delete();

        AuditLog::record('account_deletion_request_cancelled', 'members', auth()->user()->email ?? 'okänd', $member);
        $this->deletionRequested = false;
    }

    public function optedOutCategories(): array
    {
        $email = auth()->user()->email;
        if (! $email) {
            return [];
        }
        return MailingCategoryOptout::forEmail($email)->all();
    }

    public function toggleCategory(string $category): void
    {
        if (! NotificationCategory::canOptOut($category)) {
            return;
        }

        $email  = auth()->user()->email;
        $member = auth()->user()->member;

        if (in_array($category, $this->optedOutCategories())) {
            MailingCategoryOptout::restore($email, $category);
        } else {
            MailingCategoryOptout::record($email, $category, $member?->id);
        }

        $this->flashNotifications = 'Aviseringsinställningar sparade.';
    }

    public function uploadAvatar(): void
    {
        $this->validate(
            ['avatar' => ['required', 'image', 'max:2048', 'mimes:jpg,jpeg,png,gif,webp']],
            ['avatar.required' => 'Välj en bildfil.']
        );

        $user = auth()->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $filename = 'avatars/' . Str::random(40) . '.jpg';
        $tmpPath  = $this->avatar->getRealPath();
        $mime     = $this->avatar->getMimeType();

        $src = match (true) {
            str_contains($mime, 'png')  => imagecreatefrompng($tmpPath),
            str_contains($mime, 'gif')  => imagecreatefromgif($tmpPath),
            str_contains($mime, 'webp') => imagecreatefromwebp($tmpPath),
            default                     => imagecreatefromjpeg($tmpPath),
        };

        $origW  = imagesx($src);
        $origH  = imagesy($src);
        $size   = min($origW, $origH);
        $cropX  = (int) (($origW - $size) / 2);
        $cropY  = (int) (($origH - $size) / 2);
        $target = imagecreatetruecolor(200, 200);

        imagecopyresampled($target, $src, 0, 0, $cropX, $cropY, 200, 200, $size, $size);
        imagedestroy($src);

        ob_start();
        imagejpeg($target, null, 85);
        $jpeg = ob_get_clean();
        imagedestroy($target);

        Storage::disk('public')->put($filename, $jpeg);
        $user->update(['avatar_path' => $filename]);

        AuditLog::record('avatar_uploaded', 'members', $user->email ?? 'okänd', $user->member);
        $this->avatar      = null;
        $this->flashAvatar = 'Profilbilden uppdaterades.';
    }

    public function removeAvatar(): void
    {
        $user = auth()->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        AuditLog::record('avatar_removed', 'members', $user->email ?? 'okänd', $user->member);
        $this->flashAvatar = 'Profilbilden togs bort.';
    }

    public function updatePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'current_password'],
            'password'         => ['required', 'confirmed', Password::defaults()],
        ], [
            'current_password.current_password' => 'Nuvarande lösenord stämmer inte.',
            'password.confirmed'                => 'Lösenorden matchar inte.',
        ]);

        $user = auth()->user();
        $user->update(['password' => $this->password]);

        AuditLog::record('password_changed', 'members', $user->email ?? 'okänd', $user->member);
        $this->current_password      = '';
        $this->password              = '';
        $this->password_confirmation = '';
        $this->flashPassword         = 'Lösenordet uppdaterades.';
    }
}; ?>

<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6">

    {{-- Profilbild --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div class="bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Profilbild</h2>
        </div>
        <div class="p-6">
            @if($flashAvatar)
                <div class="mb-4 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                    {{ $flashAvatar }}
                </div>
            @endif

            <div class="flex items-start gap-6">
                <div class="shrink-0">
                    @if(auth()->user()->avatar_path)
                        <img src="{{ auth()->user()->avatarUrl(160) }}"
                             alt="{{ auth()->user()->name }}"
                             class="size-20 rounded-full object-cover ring-2 ring-zinc-200 dark:ring-zinc-700">
                    @else
                        <div class="flex size-20 items-center justify-center rounded-full bg-zinc-200 text-xl font-semibold text-zinc-600 ring-2 ring-zinc-200 dark:bg-zinc-700 dark:text-zinc-300 dark:ring-zinc-600">
                            {{ collect(explode(' ', auth()->user()->name))->map(fn ($n) => mb_substr($n, 0, 1))->take(2)->implode('') }}
                        </div>
                    @endif
                </div>

                <div class="flex-1">
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                Välj ny bild
                                <span class="ml-1 font-normal text-zinc-400 dark:text-zinc-500">(jpg, png, webp — max 2 MB)</span>
                            </label>
                            <input type="file" wire:model="avatar" accept="image/*"
                                   class="mt-1 block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:file:text-zinc-300 dark:hover:file:bg-zinc-600" />
                            @error('avatar') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        @if($avatar)
                            <div>
                                <p class="mb-1 text-xs text-zinc-400">Förhandsgranskning:</p>
                                <img src="{{ $avatar->temporaryUrl() }}" class="size-16 rounded-full object-cover ring-2 ring-brand" alt="Förhandsgranskning">
                            </div>
                        @endif

                        <div class="flex gap-2">
                            <button type="button" wire:click="uploadAvatar"
                                    class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                                Ladda upp
                            </button>
                            @if(auth()->user()->avatar_path)
                                <button type="button" wire:click="removeAvatar"
                                        wire:confirm="Ta bort profilbilden?"
                                        class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-600 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-800">
                                    Ta bort bild
                                </button>
                            @endif
                        </div>
                    </div>

                    <p class="mt-3 text-xs text-zinc-400 dark:text-zinc-500">
                        Bilden beskärs automatiskt till en kvadrat (200×200 px). Om ingen bild laddas upp visas initialer.
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- Utseende --}}
    <div class="mt-6 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div class="bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Utseende</h2>
        </div>
        <div class="p-6">
            <p class="mb-3 text-sm text-zinc-600 dark:text-zinc-400">Välj om sajten ska visas i ljust eller mörkt läge.</p>
            <div class="flex gap-2">
                @foreach([
                    ['value' => 'light',  'label' => 'Ljust',  'icon' => 'sun-fill'],
                    ['value' => 'dark',   'label' => 'Mörkt',  'icon' => 'moon-stars-fill'],
                    ['value' => 'system', 'label' => 'System', 'icon' => 'circle-half'],
                ] as $opt)
                    <button type="button"
                            wire:click="setTheme('{{ $opt['value'] }}')"
                            @class([
                                'flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-colors',
                                'border-brand bg-brand text-brand-dark'              => $theme === $opt['value'],
                                'border-zinc-300 bg-white text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' => $theme !== $opt['value'],
                            ])>
                        <x-bootstrap-icon name="{{ $opt['icon'] }}" class="size-4" />
                        {{ $opt['label'] }}
                    </button>
                @endforeach
            </div>
            <p class="mt-2 text-xs text-zinc-400 dark:text-zinc-500">
                System följer din enhets inställning för mörkt/ljust läge.
            </p>
        </div>
    </div>

    {{-- Google-konton --}}
    <div class="mt-6 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div class="bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Google-konton</h2>
        </div>
        <div class="p-6 space-y-4">
            @if($flashGoogle)
                <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                    {{ $flashGoogle }}
                </div>
            @endif
            @if($errors->has('google_link'))
                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                    {{ $errors->first('google_link') }}
                </div>
            @endif

            {{-- Primary account --}}
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Primärt konto</p>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ auth()->user()->email }}</p>
                </div>
                <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400">Inloggning</span>
            </div>

            {{-- Personal account --}}
            @if(auth()->user()->google_personal_email)
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Personligt konto</p>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ auth()->user()->google_personal_email }}</p>
                    </div>
                    <form method="POST" action="{{ route('auth.google.unlink') }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-xs text-red-600 hover:underline dark:text-red-400">Koppla bort</button>
                    </form>
                </div>

                {{-- Notification preference --}}
                <div class="border-t border-zinc-100 pt-4 dark:border-zinc-700">
                    <p class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">Föredragen mejladress för notiser</p>
                    <div class="space-y-2">
                        @foreach([auth()->user()->email, auth()->user()->google_personal_email] as $addr)
                            <label class="flex cursor-pointer items-center gap-3">
                                <input type="radio" name="notification_email"
                                       wire:click="setNotificationEmail('{{ $addr }}')"
                                       @checked(auth()->user()->notificationEmail() === $addr)
                                       class="rounded-full border-zinc-300 text-complement focus:ring-complement dark:border-zinc-600" />
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $addr }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @else
                <a href="{{ route('auth.google.link') }}"
                   class="inline-flex items-center gap-2 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700">
                    <x-bootstrap-icon name="google" class="size-4" />
                    Koppla personligt Google-konto
                </a>
            @endif

            {{-- Workspace-konto + e-postimport --}}
            @if(auth()->user()->member?->workspaceAccount?->email)
                @php
                    $wsEmail   = auth()->user()->member->workspaceAccount->email;
                    $chooser   = 'https://accounts.google.com/AccountChooser?Email=' . urlencode($wsEmail) . '&continue=';
                    $gmailUrl  = $chooser . urlencode('https://mail.google.com');
                    $driveUrl  = $chooser . urlencode('https://drive.google.com');
                    $calUrl    = $chooser . urlencode('https://calendar.google.com');
                @endphp
                <div class="border-t border-zinc-100 pt-4 dark:border-zinc-700">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Workspace-konto</p>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $wsEmail }}</p>
                        </div>
                        <a href="{{ route('settings.imap-import') }}" wire:navigate
                           class="inline-flex items-center gap-1.5 text-xs text-complement hover:underline dark:text-complement-light">
                            <x-bootstrap-icon name="inbox-arrow-down" class="size-3.5" />
                            Importera e-post
                        </a>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ $gmailUrl }}" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 shadow-sm transition-colors hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-600">
                            <x-bootstrap-icon name="envelope" class="size-3.5 text-red-500" />
                            Öppna Gmail
                        </a>
                        <a href="{{ $driveUrl }}" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 shadow-sm transition-colors hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-600">
                            <x-bootstrap-icon name="folder2" class="size-3.5 text-yellow-500" />
                            Öppna Drive
                        </a>
                        <a href="{{ $calUrl }}" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 shadow-sm transition-colors hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-600">
                            <x-bootstrap-icon name="calendar3" class="size-3.5 text-blue-500" />
                            Öppna Kalender
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Discord --}}
    <div class="mt-6 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div class="bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Discord</h2>
        </div>
        <div class="p-6 space-y-4">
            @if($flashDiscord)
                <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                    {{ $flashDiscord }}
                </div>
            @endif
            @if($errors->has('discord_link'))
                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                    {{ $errors->first('discord_link') }}
                </div>
            @endif

            @if(auth()->user()->discord_id)
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <x-bootstrap-icon name="discord" class="size-5 text-indigo-500 dark:text-indigo-400" />
                        <div>
                            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ auth()->user()->discord_username }}</p>
                            <p class="text-xs text-zinc-400 dark:text-zinc-500">Kopplades {{ auth()->user()->discord_linked_at?->diffForHumans() }}</p>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('auth.discord.unlink') }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-xs text-red-600 hover:underline dark:text-red-400">Koppla bort</button>
                    </form>
                </div>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Dina Discord-roller uppdateras automatiskt baserat på ditt medlemskap.</p>
            @else
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Koppla ditt Discord-konto för att få automatiska roller på Armagedon-servern.
                </p>
                <a href="{{ route('auth.discord.link') }}"
                   class="inline-flex items-center gap-2 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700">
                    <x-bootstrap-icon name="discord" class="size-4" />
                    Koppla Discord-konto
                </a>
            @endif
        </div>
    </div>

    {{-- Lösenord --}}
    @if(auth()->user()->password)
        <div class="mt-6 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Byt lösenord</h2>
            </div>
            <div class="p-6">
                @if($flashPassword)
                    <div class="mb-4 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                        {{ $flashPassword }}
                    </div>
                @endif

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nuvarande lösenord</label>
                        <input wire:model="current_password" type="password" autocomplete="current-password"
                               class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                        @error('current_password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nytt lösenord</label>
                        <input wire:model="password" type="password" autocomplete="new-password"
                               class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                        @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Bekräfta lösenord</label>
                        <input wire:model="password_confirmation" type="password" autocomplete="new-password"
                               class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                    </div>
                    <button type="button" wire:click="updatePassword"
                            class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                        Uppdatera lösenord
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Tvåfaktorsautentisering --}}
    <div class="mt-6 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div class="bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
            <div class="flex items-center gap-2">
                <x-bootstrap-icon name="shield-lock" class="size-4 text-zinc-500 dark:text-zinc-400" />
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Tvåfaktorsautentisering</h2>
            </div>
        </div>
        <div class="p-6">
            @livewire('dashboard.two-factor')
        </div>
    </div>

    {{-- Passnycklar --}}
    <div class="mt-6 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div class="bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
            <div class="flex items-center gap-2">
                <x-bootstrap-icon name="fingerprint" class="size-4 text-zinc-500 dark:text-zinc-400" />
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Passnycklar (lösenordsfri inloggning)</h2>
            </div>
        </div>
        <div class="p-6">
            @livewire('dashboard.passkeys')
        </div>
    </div>

    {{-- Aviseringsinställningar --}}
    <div class="mt-6 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div class="bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">E-postaviseringar</h2>
        </div>
        <div class="p-6">
            @if($flashNotifications)
                <div class="mb-4 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                    {{ $flashNotifications }}
                </div>
            @endif

            {{-- Aviseringar via e-post --}}
            <div class="mb-5 flex items-start gap-4 rounded-lg border border-zinc-100 bg-zinc-50/50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                <div class="flex h-5 items-center pt-0.5">
                    <input type="checkbox"
                           id="notifications-via-email"
                           wire:click="toggleNotificationsViaEmail"
                           @checked(auth()->user()->wantsEmailForNotifications())
                           class="size-4 rounded border-zinc-300 text-brand focus:ring-brand dark:border-zinc-600 dark:bg-zinc-800" />
                </div>
                <div class="flex-1">
                    <label for="notifications-via-email" class="block cursor-pointer text-sm font-medium text-zinc-800 dark:text-zinc-200">
                        Få aviseringar via e-post
                    </label>
                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                        Aviseringar (omröstningar, enkäter m.m.) visas alltid i klockan. Med detta tillval skickas de även till din e-post.
                    </p>
                </div>
            </div>

            @php $optedOut = $this->optedOutCategories(); @endphp

            <div class="space-y-4">
                @foreach(NotificationCategory::OPT_OUTABLE as $slug)
                    @php
                        $isOptedOut   = in_array($slug, $optedOut);
                        $warningLevel = NotificationCategory::warningLevel($slug);
                    @endphp
                    <div class="flex items-start gap-4">
                        <div class="flex h-5 items-center pt-0.5">
                            <input type="checkbox"
                                   id="cat-{{ $slug }}"
                                   wire:click="toggleCategory('{{ $slug }}')"
                                   @checked(! $isOptedOut)
                                   class="size-4 rounded border-zinc-300 text-brand focus:ring-brand dark:border-zinc-600 dark:bg-zinc-800" />
                        </div>
                        <div class="flex-1">
                            <label for="cat-{{ $slug }}" class="block cursor-pointer text-sm font-medium text-zinc-800 dark:text-zinc-200">
                                {{ NotificationCategory::label($slug) }}
                            </label>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ NotificationCategory::description($slug) }}
                            </p>
                            @if($isOptedOut && $warningLevel !== NotificationCategory::WARNING_NONE)
                                <p class="mt-1 text-xs {{ $warningLevel === NotificationCategory::WARNING_STRONG ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400' }}">
                                    {{ NotificationCategory::warningText($slug) }}
                                </p>
                            @endif
                        </div>
                    </div>
                    @if(! $loop->last)
                        <div class="border-t border-zinc-100 dark:border-zinc-800"></div>
                    @endif
                @endforeach
            </div>

            <p class="mt-4 text-xs text-zinc-400">
                Systemmeddelanden (kontosäkerhet m.m.) kan inte stängas av.
            </p>
        </div>
    </div>

    {{-- GDPR — Mina uppgifter --}}
    <div class="mt-6 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div class="bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Mina uppgifter (GDPR art. 15)</h2>
        </div>
        <div class="p-6">
            <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
                Du har rätt att få ut alla uppgifter vi lagrar om dig. Nedan ser du en sammanfattning.
            </p>

            @php $counts = $this->gdprCounts; @endphp
            <div class="mb-5 grid grid-cols-2 gap-2 sm:grid-cols-4">
                @foreach([
                    ['icon' => 'calendar-check',  'label' => 'Evenemang',      'count' => $counts['anmalningar']],
                    ['icon' => 'bag',              'label' => 'Ordrar',         'count' => $counts['ordrar']],
                    ['icon' => 'door-open',        'label' => 'Lokalbokningar', 'count' => $counts['bokningar']],
                    ['icon' => 'key',              'label' => 'Hyror',          'count' => $counts['hyror']],
                    ['icon' => 'clipboard-check',  'label' => 'Enkäter',        'count' => $counts['enkater']],
                    ['icon' => 'wifi',             'label' => 'RFID-taggar',    'count' => $counts['rfid_taggar']],
                    ['icon' => 'envelope',         'label' => 'Mejl skickade',  'count' => $counts['mail_log']],
                    ['icon' => 'clock-history',    'label' => 'Aktivitetslogg', 'count' => $counts['aktivitetslogg']],
                ] as $row)
                <div class="flex items-center gap-3 rounded-lg border border-zinc-100 px-3 py-2.5 dark:border-zinc-800">
                    <x-bootstrap-icon name="{{ $row['icon'] }}" class="size-4 shrink-0 text-zinc-400 dark:text-zinc-500" />
                    <div class="min-w-0">
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $row['label'] }}</p>
                        <p class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">{{ $row['count'] }}</p>
                    </div>
                </div>
                @endforeach
            </div>

            <a href="{{ route('gdpr.export') }}"
               class="inline-flex items-center gap-2 rounded-lg bg-complement px-4 py-2 text-sm font-semibold text-white hover:bg-complement/90">
                <x-bootstrap-icon name="download" class="size-4" />
                Ladda ned mina uppgifter (JSON)
            </a>
        </div>
    </div>

    {{-- GDPR — Radera konto --}}
    <div class="mt-6 overflow-hidden rounded-xl border border-red-200 dark:border-red-900/50">
        <div class="bg-red-50 px-6 py-4 dark:bg-red-900/10">
            <h2 class="text-sm font-semibold text-red-800 dark:text-red-300">Radera konto (GDPR)</h2>
        </div>
        <div class="p-6">
            @if($deletionRequested)
                {{-- Pending request --}}
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
                    <div class="flex items-start gap-3">
                        <x-bootstrap-icon name="hourglass-split" class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                        <div class="flex-1">
                            <p class="text-sm font-medium text-amber-900 dark:text-amber-300">Din begäran om radering behandlas</p>
                            <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">
                                Vi granskar din begäran och återkommer. Du kan återkalla begäran om du ångrar dig.
                            </p>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="button"
                                wire:click="cancelDeletionRequest"
                                wire:confirm="Återkalla begäran om radering?"
                                class="rounded-lg border border-amber-300 px-3 py-1.5 text-xs font-medium text-amber-800 hover:bg-amber-100 dark:border-amber-700 dark:text-amber-300 dark:hover:bg-amber-900/30">
                            Återkalla begäran
                        </button>
                    </div>
                </div>
            @else
                <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
                    Du har rätt att begära att dina personuppgifter raderas (GDPR artikel 17).
                    Din begäran granskas av en administratör. Grundläggande bokföringsuppgifter kan behöva bevaras
                    i enlighet med gällande lagstiftning.
                </p>
                <div x-data="{ open: false }">
                    <button type="button"
                            @click="open = !open"
                            class="rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/20">
                        Begär radering av mina uppgifter
                    </button>
                    <div x-show="open" x-cloak class="mt-4 space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                Anledning <span class="font-normal text-zinc-400">(valfri)</span>
                            </label>
                            <textarea wire:model="deletionReason" rows="3"
                                      placeholder="Berätta gärna varför du vill radera ditt konto…"
                                      class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-400/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500"></textarea>
                        </div>
                        <div class="flex gap-2">
                            <button type="button"
                                    wire:click="requestDeletion"
                                    wire:confirm="Skicka in begäran om radering? En administratör granskar din begäran."
                                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 dark:bg-red-700 dark:hover:bg-red-600">
                                Skicka begäran
                            </button>
                            <button type="button" @click="open = false"
                                    class="rounded-lg px-4 py-2 text-sm font-medium text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                                Avbryt
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

</div>
