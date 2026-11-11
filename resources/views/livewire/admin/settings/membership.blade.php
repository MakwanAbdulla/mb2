<?php

use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {

    public int    $familyMaxAdults            = 2;
    public int    $familyMaxChildren          = 5;
    public int    $familyMaxChildAge          = 18;
    public int    $gracePeriodMonths          = 1;
    public int    $renewalVisibleMonth        = 12;
    public int    $newMemberNextYearFromMonth = 10;
    public string $municipalityName          = 'Kommunen';
    public int    $municipalityPostalMin      = 50100;
    public int    $municipalityPostalMax      = 52499;
    public int    $municipalityGrantAgeMin    = 7;
    public int    $municipalityGrantAgeMax    = 25;
    public bool   $saved                      = false;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('settings.manage'), 403);

        $this->familyMaxAdults            = (int)    SystemSetting::get('family.max_adults', 2);
        $this->familyMaxChildren          = (int)    SystemSetting::get('family.max_children', 5);
        $this->familyMaxChildAge          = (int)    SystemSetting::get('family.max_child_age', 18);
        $this->gracePeriodMonths          = (int)    SystemSetting::get('membership.grace_period_months', 1);
        $this->renewalVisibleMonth        = (int)    SystemSetting::get('membership.renewal_visible_month', 12);
        $this->newMemberNextYearFromMonth = (int)    SystemSetting::get('membership.new_member_next_year_from_month', 10);
        $this->municipalityName           = (string) SystemSetting::get('statistics.municipality_name', 'Kommunen');
        $this->municipalityPostalMin      = (int)    SystemSetting::get('statistics.municipality_postal_min', 50100);
        $this->municipalityPostalMax      = (int)    SystemSetting::get('statistics.municipality_postal_max', 52499);
        $this->municipalityGrantAgeMin    = (int)    SystemSetting::get('statistics.municipality_grant_age_min', 7);
        $this->municipalityGrantAgeMax    = (int)    SystemSetting::get('statistics.municipality_grant_age_max', 25);
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);

        $this->validate([
            'familyMaxAdults'            => ['required', 'integer', 'min:1', 'max:10'],
            'familyMaxChildren'          => ['required', 'integer', 'min:0', 'max:20'],
            'familyMaxChildAge'          => ['required', 'integer', 'min:1', 'max:25'],
            'gracePeriodMonths'          => ['required', 'integer', 'min:0', 'max:12'],
            'renewalVisibleMonth'        => ['required', 'integer', 'min:1', 'max:12'],
            'newMemberNextYearFromMonth' => ['required', 'integer', 'min:1', 'max:12'],
            'municipalityName'           => ['required', 'string', 'max:100'],
            'municipalityPostalMin'      => ['required', 'integer', 'min:10000', 'max:99999'],
            'municipalityPostalMax'      => ['required', 'integer', 'min:10000', 'max:99999', 'gte:municipalityPostalMin'],
            'municipalityGrantAgeMin'    => ['required', 'integer', 'min:0', 'max:99'],
            'municipalityGrantAgeMax'    => ['required', 'integer', 'min:0', 'max:99', 'gte:municipalityGrantAgeMin'],
        ]);

        $map = [
            'family.max_adults'                          => $this->familyMaxAdults,
            'family.max_children'                        => $this->familyMaxChildren,
            'family.max_child_age'                       => $this->familyMaxChildAge,
            'membership.grace_period_months'             => $this->gracePeriodMonths,
            'membership.renewal_visible_month'           => $this->renewalVisibleMonth,
            'membership.new_member_next_year_from_month' => $this->newMemberNextYearFromMonth,
            'statistics.municipality_name'               => $this->municipalityName,
            'statistics.municipality_postal_min'         => $this->municipalityPostalMin,
            'statistics.municipality_postal_max'         => $this->municipalityPostalMax,
            'statistics.municipality_grant_age_min'      => $this->municipalityGrantAgeMin,
            'statistics.municipality_grant_age_max'      => $this->municipalityGrantAgeMax,
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

        AuditLog::record('settings_saved', 'settings', 'Medlemskapsinställningar uppdaterade', null, $changes ?: null);
        $this->saved = true;
    }
}; ?>

<form wire:submit="save" class="space-y-6">
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">

        {{-- Familjegrupper --}}
        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Familjegrupper</h2>
        </div>
        <div class="grid gap-4 p-5 sm:grid-cols-3">
            <div>
                <label class="lbl">Max vuxna per familj</label>
                <input wire:model="familyMaxAdults" type="number" min="1" max="10"
                       class="inp mt-1 w-20 @error('familyMaxAdults') border-red-300 @enderror" />
                @error('familyMaxAdults') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lbl">Max barn per familj</label>
                <input wire:model="familyMaxChildren" type="number" min="0" max="20"
                       class="inp mt-1 w-20 @error('familyMaxChildren') border-red-300 @enderror" />
                @error('familyMaxChildren') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lbl">
                    Max ålder för barn
                    <span class="font-normal text-zinc-400">(år, ej inkl.)</span>
                </label>
                <input wire:model="familyMaxChildAge" type="number" min="1" max="25"
                       class="inp mt-1 w-20 @error('familyMaxChildAge') border-red-300 @enderror" />
                @error('familyMaxChildAge') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <hr class="border-zinc-100 dark:border-zinc-800">

        {{-- Förnyelse & karens --}}
        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Förnyelse &amp; karens</h2>
        </div>
        <div class="space-y-5 p-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="lbl">
                        Förnyelse-CTA synlig från månad
                        <span class="font-normal text-zinc-400">(1–12)</span>
                    </label>
                    <input wire:model="renewalVisibleMonth" type="number" min="1" max="12"
                           class="inp mt-1 w-20 @error('renewalVisibleMonth') border-red-300 @enderror" />
                    <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Från denna månad visas "Förnya för nästa år" på Min sida.</p>
                    @error('renewalVisibleMonth') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lbl">
                        Karenstid efter nyår
                        <span class="font-normal text-zinc-400">(månader)</span>
                    </label>
                    <input wire:model="gracePeriodMonths" type="number" min="0" max="12"
                           class="inp mt-1 w-20 @error('gracePeriodMonths') border-red-300 @enderror" />
                    <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Hur länge ett ej förnyat medlemskap behåller full access efter 1 jan.</p>
                    @error('gracePeriodMonths') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="lbl">
                    Ny förstagångsmedlem betalar för kommande år från månad
                    <span class="font-normal text-zinc-400">(1–12, rekommenderat: 10–12)</span>
                </label>
                <input wire:model="newMemberNextYearFromMonth" type="number" min="1" max="12"
                       class="inp mt-1 w-20 @error('newMemberNextYearFromMonth') border-red-300 @enderror" />
                <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Från och med denna månad registreras nya medlemmar för kommande år istället för innevarande.</p>
                @error('newMemberNextYearFromMonth') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    {{-- Kommunrapport --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Kommunrapport</h2>
        </div>
        <div class="space-y-5 p-5">
            <div>
                <label class="lbl">Kommunnamn</label>
                <input wire:model="municipalityName" type="text" maxlength="100"
                       class="inp mt-1 w-64 @error('municipalityName') border-red-300 @enderror" />
                <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Visas i kommunrapporten, t.ex. "Borås stad".</p>
                @error('municipalityName') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="lbl">Postnummer från</label>
                    <input wire:model="municipalityPostalMin" type="number" min="10000" max="99999"
                           class="inp mt-1 w-32 @error('municipalityPostalMin') border-red-300 @enderror" />
                    @error('municipalityPostalMin') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lbl">Postnummer till</label>
                    <input wire:model="municipalityPostalMax" type="number" min="10000" max="99999"
                           class="inp mt-1 w-32 @error('municipalityPostalMax') border-red-300 @enderror" />
                    <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Medlemmar inom detta postnummerspan räknas som kommuninvånare.</p>
                    @error('municipalityPostalMax') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="lbl">Bidragsberättigad ålder från</label>
                    <input wire:model="municipalityGrantAgeMin" type="number" min="0" max="99"
                           class="inp mt-1 w-20 @error('municipalityGrantAgeMin') border-red-300 @enderror" />
                    @error('municipalityGrantAgeMin') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lbl">Bidragsberättigad ålder till</label>
                    <input wire:model="municipalityGrantAgeMax" type="number" min="0" max="99"
                           class="inp mt-1 w-20 @error('municipalityGrantAgeMax') border-red-300 @enderror" />
                    <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Ålder (inkl.) för att räknas som bidragsberättigad i kommunrapporten.</p>
                    @error('municipalityGrantAgeMax') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
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
