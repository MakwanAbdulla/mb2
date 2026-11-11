<?php

use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {

    public string $organizationName      = '';
    public string $organizationNumber    = '';
    public string $contactEmail          = '';
    public string $websiteUrl            = '';
    public string $domainRewriteFrom     = '';
    public string $domainRewriteTo       = '';
    public bool   $saved                 = false;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('settings.manage'), 403);

        $this->organizationName   = (string) SystemSetting::get('general.organization_name', '');
        $this->organizationNumber = (string) SystemSetting::get('general.organization_number', '');
        $this->contactEmail       = (string) SystemSetting::get('general.contact_email', '');
        $this->websiteUrl         = (string) SystemSetting::get('general.website_url', '');

        $rewrite = SystemSetting::get('mail.domain_rewrite') ?? [];
        $this->domainRewriteFrom = $rewrite['from'] ?? '';
        $this->domainRewriteTo   = $rewrite['to'] ?? '';
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);

        $this->validate([
            'organizationName'   => ['nullable', 'string', 'max:255'],
            'organizationNumber' => ['nullable', 'string', 'max:20'],
            'contactEmail'       => ['nullable', 'email', 'max:255'],
            'websiteUrl'         => ['nullable', 'url', 'max:255'],
            'domainRewriteFrom'  => ['nullable', 'string', 'max:255'],
            'domainRewriteTo'    => ['nullable', 'string', 'max:255'],
        ], [
            'contactEmail.email' => 'Kontakt-e-postadressen måste vara en giltig e-postadress.',
            'websiteUrl.url'     => 'Webbsajtens URL måste vara en giltig URL.',
        ]);

        $rewrite = ($this->domainRewriteFrom && $this->domainRewriteTo)
            ? ['from' => $this->domainRewriteFrom, 'to' => $this->domainRewriteTo]
            : [];

        $map = [
            'general.organization_name'   => $this->organizationName,
            'general.organization_number' => $this->organizationNumber,
            'general.contact_email'       => $this->contactEmail,
            'general.website_url'         => $this->websiteUrl,
            'mail.domain_rewrite'         => $rewrite,
        ];

        $changes = [];
        foreach ($map as $key => $new) {
            $old = (string) SystemSetting::get($key, '');
            if ($old !== (string) $new) {
                $changes[$key] = ['from' => $old, 'to' => (string) $new];
            }
        }
        foreach ($map as $key => $value) {
            $type = $key === 'mail.domain_rewrite' ? \App\Models\SystemSetting::TYPE_JSON : \App\Models\SystemSetting::TYPE_STRING;
            SystemSetting::set($key, $value, $type);
        }

        AuditLog::record('settings_saved', 'settings', 'Allmänna inställningar uppdaterade', null, $changes ?: null);
        $this->saved = true;
    }

    public function activeSessions(): \Illuminate\Support\Collection
    {
        return \Illuminate\Support\Facades\DB::table('sessions')
            ->leftJoin('users', 'sessions.user_id', '=', 'users.id')
            ->where('sessions.last_activity', '>', now()->subMinutes(15)->timestamp)
            ->whereNotNull('sessions.user_id')
            ->select('users.name', 'sessions.last_activity', 'sessions.ip_address')
            ->orderByDesc('sessions.last_activity')
            ->get();
    }
}; ?>

<form wire:submit="save" class="space-y-6">
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">

        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Organisation</h2>
        </div>

        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">

            <div class="grid gap-4 p-5 sm:grid-cols-2">
                <div>
                    <label class="lbl">Föreningsnamn</label>
                    <input wire:model="organizationName" type="text" placeholder="Armagedon"
                           class="inp mt-1 @error('organizationName') border-red-300 @enderror" />
                    @error('organizationName') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lbl">Organisationsnummer</label>
                    <input wire:model="organizationNumber" type="text" placeholder="802406-0000"
                           class="inp mt-1 font-mono @error('organizationNumber') border-red-300 @enderror" />
                    @error('organizationNumber') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid gap-4 p-5 sm:grid-cols-2">
                <div>
                    <label class="lbl">Kontakt-e-post</label>
                    <input wire:model="contactEmail" type="email" placeholder="kontakt@foreningen.se"
                           class="inp mt-1 @error('contactEmail') border-red-300 @enderror" />
                    <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Används som avsändaradress och Reply-To i utskick.</p>
                    @error('contactEmail') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lbl">Webbsajt</label>
                    <input wire:model="websiteUrl" type="url" placeholder="https://www.foreningen.se"
                           class="inp mt-1 @error('websiteUrl') border-red-300 @enderror" />
                    @error('websiteUrl') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

        </div>
    </div>

    {{-- Temporary: domain rewrite for outgoing inbox mail during GWS MX transition --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">E-post — domänomskrivning</h2>
        </div>
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
            <div class="grid gap-4 p-5 sm:grid-cols-2">
                <div>
                    <label class="lbl">Skriv om från (domän)</label>
                    <input wire:model="domainRewriteFrom" type="text" placeholder="armagedon.se"
                           class="inp mt-1 font-mono" />
                    <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Lämna tomt för att inaktivera.</p>
                </div>
                <div>
                    <label class="lbl">Skriv om till (domän)</label>
                    <input wire:model="domainRewriteTo" type="text" placeholder="armagedon.se.test-google-a.com"
                           class="inp mt-1 font-mono" />
                    <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Tillfällig GWS-testdomän. Ta bort när MX pekas om.</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Aktiva användare --}}
    @php $activeSessions = $this->activeSessions(); @endphp
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800 flex items-center gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Aktiva användare</h2>
            <span class="rounded-full bg-brand px-2 py-0.5 text-xs font-semibold text-brand-dark">{{ $activeSessions->count() }}</span>
            <span class="text-xs text-zinc-400 dark:text-zinc-500">senaste 15 min</span>
        </div>
        <div class="p-5">
            @if ($activeSessions->isEmpty())
                <p class="text-sm text-zinc-400 dark:text-zinc-500 italic">Inga användare aktiva just nu.</p>
            @else
                <ul class="space-y-2">
                    @foreach ($activeSessions as $session)
                        <li class="flex items-center justify-between text-sm">
                            <span class="text-zinc-900 dark:text-white">{{ $session->name }}</span>
                            <span class="text-xs text-zinc-400 dark:text-zinc-500">
                                {{ \Carbon\Carbon::createFromTimestamp($session->last_activity)->diffForHumans() }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="flex items-center justify-end gap-3">
        @if($saved)
            <span class="flex items-center gap-1.5 text-sm text-green-600 dark:text-green-400">
                <x-bootstrap-icon name="check-circle-fill" class="size-4" />
                Sparat
            </span>
        @endif
        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="save">Spara</span>
            <span wire:loading wire:target="save">Sparar…</span>
        </button>
    </div>
</form>
