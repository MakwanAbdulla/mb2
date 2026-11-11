<?php

use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {

    public bool $moduleSelfRegistration = true;
    public bool $moduleWebshop          = true;
    public bool $moduleEvents           = true;
    public bool $moduleBookings         = true;
    public bool $moduleRentals          = true;
    public bool $moduleSurveys          = true;
    public bool $moduleFamilyGroups     = true;
    public bool $moduleSubmissions      = true;
    public bool $saved                  = false;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('settings.manage'), 403);

        $this->moduleSelfRegistration = (bool) SystemSetting::get('modules.self_registration', true);
        $this->moduleWebshop          = (bool) SystemSetting::get('modules.webshop', true);
        $this->moduleEvents           = (bool) SystemSetting::get('modules.events', true);
        $this->moduleBookings         = (bool) SystemSetting::get('modules.bookings', true);
        $this->moduleRentals          = (bool) SystemSetting::get('modules.rentals', true);
        $this->moduleSurveys          = (bool) SystemSetting::get('modules.surveys', true);
        $this->moduleFamilyGroups     = (bool) SystemSetting::get('modules.family_groups', true);
        $this->moduleSubmissions      = (bool) SystemSetting::get('modules.submissions', true);
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);

        $this->validate([
            'moduleSelfRegistration' => ['boolean'],
            'moduleWebshop'          => ['boolean'],
            'moduleEvents'           => ['boolean'],
            'moduleBookings'         => ['boolean'],
            'moduleRentals'          => ['boolean'],
            'moduleSurveys'          => ['boolean'],
            'moduleFamilyGroups'     => ['boolean'],
            'moduleSubmissions'      => ['boolean'],
        ]);

        $map = [
            'modules.self_registration' => $this->moduleSelfRegistration ? '1' : '0',
            'modules.webshop'           => $this->moduleWebshop ? '1' : '0',
            'modules.events'            => $this->moduleEvents ? '1' : '0',
            'modules.bookings'          => $this->moduleBookings ? '1' : '0',
            'modules.rentals'           => $this->moduleRentals ? '1' : '0',
            'modules.surveys'           => $this->moduleSurveys ? '1' : '0',
            'modules.family_groups'     => $this->moduleFamilyGroups ? '1' : '0',
            'modules.submissions'       => $this->moduleSubmissions ? '1' : '0',
        ];

        $changes = [];
        foreach ($map as $key => $new) {
            $old = (string) SystemSetting::get($key, '');
            if ($old !== (string) $new) {
                $changes[$key] = ['from' => $old, 'to' => (string) $new];
            }
        }
        foreach ($map as $key => $value) {
            SystemSetting::set($key, $value);
        }

        AuditLog::record('settings_saved', 'settings', 'Modulinställningar uppdaterade', null, $changes ?: null);
        $this->saved = true;
    }
}; ?>

<form wire:submit="save" class="space-y-6">
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">

        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Moduler</h2>
        </div>

        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">

            @php
                $modules = [
                    ['field' => 'moduleSelfRegistration', 'label' => 'Självregistrering', 'desc'  => 'Tillåter besökare att registrera sig som nya medlemmar via webbsajten.'],
                    ['field' => 'moduleWebshop',          'label' => 'Webbshop',           'desc'  => 'Aktiverar webbshoppen med produkter och beställningar.'],
                    ['field' => 'moduleEvents',           'label' => 'Evenemang',           'desc'  => 'Aktiverar evenemangsmodulen med anmälan, biljettkontroll och deltagarlista.'],
                    ['field' => 'moduleBookings',         'label' => 'Lokalbokningar',      'desc'  => 'Aktiverar bokningsmodulen för lokaler och resurser.'],
                    ['field' => 'moduleRentals',          'label' => 'Skåp/Taggar/Nycklar', 'desc'  => 'Aktiverar uthyrning av skåp, RFID-taggar och nycklar.'],
                    ['field' => 'moduleSurveys',          'label' => 'Enkäter',             'desc'  => 'Aktiverar enkätmodulen för att samla in svar från medlemmar.'],
                    ['field' => 'moduleFamilyGroups',     'label' => 'Familjegrupper',      'desc'  => 'Tillåter medlemmar att koppla ihop familjemedlemmar under ett konto.'],
                    ['field' => 'moduleSubmissions',      'label' => 'Motioner',            'desc'  => 'Aktiverar möjligheten att skicka in motioner och ärenden inför stämmor.'],
                ];
            @endphp

            @foreach($modules as $m)
                <label class="flex cursor-pointer items-start gap-3 p-5">
                    <input type="checkbox" wire:model="{{ $m['field'] }}"
                           class="mt-0.5 rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600">
                    <span>
                        <span class="block text-sm font-medium text-zinc-900 dark:text-white">{{ $m['label'] }}</span>
                        <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $m['desc'] }}</span>
                    </span>
                </label>
            @endforeach

        </div>
    </div>

    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800/40 dark:bg-amber-900/20">
        <p class="flex items-start gap-2 text-sm text-amber-800 dark:text-amber-300">
            <x-bootstrap-icon name="info-circle" class="mt-0.5 size-4 shrink-0" />
            Att inaktivera en modul döljer den från navigationen men tar inte bort befintlig data. Modulen kan aktiveras igen när som helst.
        </p>
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
