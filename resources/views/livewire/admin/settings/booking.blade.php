<?php

use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {

    public int    $maxBookingsPerUser     = 1;
    public int    $maxBookingsSuperBooker = 3;
    public bool   $openBookingsExempt     = false;
    public bool   $allowSameDayBooking    = false;
    public int    $userMaxMonthsAhead     = 6;
    public int    $adminMaxMonthsAhead    = 11;
    public int    $venueMaxMonthsAhead    = 11;
    public string $bookingOpensFrom       = '';
    public string $bookingStartTime       = '00:00';
    public string $bookingEndTime         = '24:00';
    public string $nonMemberMessage       = '';
    public string $reminderEmailMessage   = '';
    public bool   $saved                  = false;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('settings.manage'), 403);

        $this->maxBookingsPerUser     = (int)    SystemSetting::get('max_bookings_per_user', 1);
        $this->maxBookingsSuperBooker = (int)    SystemSetting::get('max_bookings_super_booker', 3);
        $this->openBookingsExempt     = (bool)   SystemSetting::get('open_bookings_exempt', false);
        $this->allowSameDayBooking    = (bool)   SystemSetting::get('allow_same_day_booking', false);
        $this->userMaxMonthsAhead     = (int)    SystemSetting::get('user_max_months_ahead', 6);
        $this->adminMaxMonthsAhead    = (int)    SystemSetting::get('admin_max_months_ahead', 11);
        $this->venueMaxMonthsAhead    = (int)    SystemSetting::get('venue_max_months_ahead', 11);
        $this->bookingOpensFrom       = (string) SystemSetting::get('booking_opens_from', '');
        $this->bookingStartTime       = (string) SystemSetting::get('booking_start_time', '00:00');
        $this->bookingEndTime         = (string) SystemSetting::get('booking_end_time', '24:00');
        $this->nonMemberMessage       = (string) SystemSetting::get('non_member_message', '');
        $this->reminderEmailMessage   = (string) SystemSetting::get('reminder_email_message', '');
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);

        $this->validate([
            'maxBookingsPerUser'     => ['required', 'integer', 'min:1', 'max:20'],
            'maxBookingsSuperBooker' => ['required', 'integer', 'min:1', 'max:20'],
            'openBookingsExempt'     => ['boolean'],
            'allowSameDayBooking'    => ['boolean'],
            'userMaxMonthsAhead'     => ['required', 'integer', 'min:1', 'max:11'],
            'adminMaxMonthsAhead'    => ['required', 'integer', 'min:1', 'max:11'],
            'venueMaxMonthsAhead'    => ['required', 'integer', 'min:1', 'max:11'],
            'bookingOpensFrom'       => ['nullable', 'date'],
            'bookingStartTime'       => ['required', 'regex:/^([01]\d|2[0-3]):(00|30)$/'],
            'bookingEndTime'         => ['required', 'regex:/^(([01]\d|2[0-3]):(00|30)|24:00)$/', function ($attr, $value, $fail) {
                if ($value <= $this->bookingStartTime) {
                    $fail('Sluttiden måste vara efter starttiden.');
                }
            }],
            'nonMemberMessage'       => ['required', 'string', 'max:1000'],
            'reminderEmailMessage'   => ['nullable', 'string', 'max:2000'],
        ]);

        $map = [
            'max_bookings_per_user'    => $this->maxBookingsPerUser,
            'max_bookings_super_booker' => $this->maxBookingsSuperBooker,
            'open_bookings_exempt'     => $this->openBookingsExempt ? '1' : '0',
            'allow_same_day_booking'   => $this->allowSameDayBooking ? '1' : '0',
            'user_max_months_ahead'    => $this->userMaxMonthsAhead,
            'admin_max_months_ahead'   => $this->adminMaxMonthsAhead,
            'venue_max_months_ahead'   => $this->venueMaxMonthsAhead,
            'booking_opens_from'       => $this->bookingOpensFrom ?: null,
            'booking_start_time'       => $this->bookingStartTime,
            'booking_end_time'         => $this->bookingEndTime,
            'non_member_message'       => $this->nonMemberMessage,
            'reminder_email_message'   => $this->reminderEmailMessage,
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

        AuditLog::record('settings_saved', 'settings', 'Bokningsinställningar uppdaterade', null, $changes ?: null);
        $this->saved = true;
    }
}; ?>

<form wire:submit="save" class="space-y-6">
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">

        {{-- Bokningsgränser --}}
        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Bokningsgränser</h2>
        </div>
        <div class="space-y-5 p-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="lbl">Max aktiva bokningar per person</label>
                    <input wire:model="maxBookingsPerUser" type="number" min="1" max="20"
                           class="inp mt-1 w-24 @error('maxBookingsPerUser') border-red-300 @enderror" />
                    @error('maxBookingsPerUser') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lbl">Max aktiva bokningar för superbokare</label>
                    <input wire:model="maxBookingsSuperBooker" type="number" min="1" max="20"
                           class="inp mt-1 w-24 @error('maxBookingsSuperBooker') border-red-300 @enderror" />
                    @error('maxBookingsSuperBooker') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="space-y-2">
                <label class="flex cursor-pointer items-center gap-2.5">
                    <input type="checkbox" wire:model="openBookingsExempt"
                           class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600">
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">Öppna arrangemang räknas inte mot bokningsgränsen</span>
                </label>
                <label class="flex cursor-pointer items-center gap-2.5">
                    <input type="checkbox" wire:model="allowSameDayBooking"
                           class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600">
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">Tillåt bokning samma dag</span>
                </label>
            </div>
        </div>

        <hr class="border-zinc-100 dark:border-zinc-800">

        {{-- Tidshorisont --}}
        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Tidshorisont (månader framåt)</h2>
        </div>
        <div class="grid gap-4 p-5 sm:grid-cols-3">
            <div>
                <label class="lbl">Vanliga användare</label>
                <input wire:model="userMaxMonthsAhead" type="number" min="1" max="11"
                       class="inp mt-1 w-20 @error('userMaxMonthsAhead') border-red-300 @enderror" />
                @error('userMaxMonthsAhead') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lbl">Administratörer</label>
                <input wire:model="adminMaxMonthsAhead" type="number" min="1" max="11"
                       class="inp mt-1 w-20 @error('adminMaxMonthsAhead') border-red-300 @enderror" />
                @error('adminMaxMonthsAhead') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lbl">Lokalansökningar</label>
                <input wire:model="venueMaxMonthsAhead" type="number" min="1" max="11"
                       class="inp mt-1 w-20 @error('venueMaxMonthsAhead') border-red-300 @enderror" />
                @error('venueMaxMonthsAhead') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <hr class="border-zinc-100 dark:border-zinc-800">

        {{-- Tillåten bokningstid --}}
        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Tillåten bokningstid per dygn</h2>
        </div>
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <div>
                <label class="lbl">Tidigaste starttid</label>
                <select wire:model="bookingStartTime" class="inp mt-1 @error('bookingStartTime') border-red-300 @enderror">
                    @for ($i = 0; $i <= 47; $i++)
                        @php $val = sprintf('%02d:%02d', intdiv($i, 2), ($i % 2) * 30); @endphp
                        <option value="{{ $val }}">{{ $val }}</option>
                    @endfor
                </select>
                @error('bookingStartTime') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lbl">Senaste sluttid</label>
                <select wire:model="bookingEndTime" class="inp mt-1 @error('bookingEndTime') border-red-300 @enderror">
                    @for ($i = 1; $i <= 48; $i++)
                        @php $val = $i === 48 ? '24:00' : sprintf('%02d:%02d', intdiv($i, 2), ($i % 2) * 30); @endphp
                        <option value="{{ $val }}">{{ $val }}</option>
                    @endfor
                </select>
                @error('bookingEndTime') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <hr class="border-zinc-100 dark:border-zinc-800">

        {{-- Systemöppning --}}
        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Systemöppning</h2>
        </div>
        <div class="p-5">
            <label class="lbl">
                Bokningar tillåts från datum
                <span class="font-normal text-zinc-400">(valfritt — tomt = alltid tillåtet)</span>
            </label>
            <input wire:model="bookingOpensFrom" type="date"
                   class="inp mt-1 @error('bookingOpensFrom') border-red-300 @enderror" />
            @error('bookingOpensFrom') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <hr class="border-zinc-100 dark:border-zinc-800">

        {{-- Meddelanden --}}
        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Meddelanden</h2>
        </div>
        <div class="space-y-4 p-5">
            <div>
                <label class="lbl">Meddelande till icke-medlemmar</label>
                <textarea wire:model="nonMemberMessage" rows="2"
                          class="inp mt-1 w-full @error('nonMemberMessage') border-red-300 @enderror"></textarea>
                @error('nonMemberMessage') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lbl">
                    Extra text i påminnelsemail
                    <span class="font-normal text-zinc-400">(valfritt)</span>
                </label>
                <textarea wire:model="reminderEmailMessage" rows="3" class="inp mt-1 w-full"></textarea>
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
