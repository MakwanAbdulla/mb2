<?php

namespace App\Livewire\Admin\Statistics;

use App\Models\AuditLog;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

class MunicipalityReport extends Component
{
    #[Url(as: 'ar')]
    public int $year = 0;

    public bool $editingOverride = false;

    // Override form fields (populated when editingOverride opens)
    public string $overrideName       = '';
    public string $overridePostalMin  = '';
    public string $overridePostalMax  = '';
    public string $overrideGrantMin   = '';
    public string $overrideGrantMax   = '';

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('statistics.view'), 403);
        if ($this->year < 2000) {
            $this->year = now()->year;
        }
    }

    public function updatedYear(): void
    {
        $this->editingOverride = false;
    }

    public function openOverride(): void
    {
        $year = $this->year;
        $this->overrideName      = (string) $this->resolved('statistics.municipality_name',      $year, 'Kommunen');
        $this->overridePostalMin = (string) $this->resolved('statistics.municipality_postal_min', $year, 50100);
        $this->overridePostalMax = (string) $this->resolved('statistics.municipality_postal_max', $year, 52499);
        $this->overrideGrantMin  = (string) $this->resolved('statistics.municipality_grant_age_min', $year, 7);
        $this->overrideGrantMax  = (string) $this->resolved('statistics.municipality_grant_age_max', $year, 25);
        $this->editingOverride   = true;
    }

    public function saveOverride(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);

        $this->validate([
            'overrideName'      => ['required', 'string', 'max:100'],
            'overridePostalMin' => ['required', 'integer', 'min:10000', 'max:99999'],
            'overridePostalMax' => ['required', 'integer', 'min:10000', 'max:99999', 'gte:overridePostalMin'],
            'overrideGrantMin'  => ['required', 'integer', 'min:0', 'max:99'],
            'overrideGrantMax'  => ['required', 'integer', 'min:0', 'max:99', 'gte:overrideGrantMin'],
        ]);

        $year = $this->year;
        SystemSetting::set("statistics.municipality_name.{$year}",          $this->overrideName);
        SystemSetting::set("statistics.municipality_postal_min.{$year}",     $this->overridePostalMin);
        SystemSetting::set("statistics.municipality_postal_max.{$year}",     $this->overridePostalMax);
        SystemSetting::set("statistics.municipality_grant_age_min.{$year}",  $this->overrideGrantMin);
        SystemSetting::set("statistics.municipality_grant_age_max.{$year}",  $this->overrideGrantMax);

        AuditLog::record('settings_saved', 'settings', "Kommunrapport-inställningar för {$year} uppdaterade", null);
        $this->editingOverride = false;
    }

    public function clearOverride(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);

        $year = $this->year;
        foreach (['statistics.municipality_name', 'statistics.municipality_postal_min', 'statistics.municipality_postal_max', 'statistics.municipality_grant_age_min', 'statistics.municipality_grant_age_max'] as $key) {
            SystemSetting::where('key', "{$key}.{$year}")->delete();
        }

        AuditLog::record('settings_saved', 'settings', "Kommunrapport-override för {$year} borttagen", null);
        $this->editingOverride = false;
    }

    public function render()
    {
        $year         = $this->year;
        $snapshotDate = Carbon::createFromDate($year, 12, 31)->endOfDay();

        $municipalityName = (string) $this->resolved('statistics.municipality_name',          $year, 'Kommunen');
        $postalMin        = (int)    $this->resolved('statistics.municipality_postal_min',     $year, 50100);
        $postalMax        = (int)    $this->resolved('statistics.municipality_postal_max',     $year, 52499);
        $grantAgeMin      = (int)    $this->resolved('statistics.municipality_grant_age_min',  $year, 7);
        $grantAgeMax      = (int)    $this->resolved('statistics.municipality_grant_age_max',  $year, 25);

        $hasYearOverride = SystemSetting::where('key', "statistics.municipality_grant_age_min.{$year}")->exists();

        // Member IDs with an active membership period for this year
        $activeMemberIds = MembershipPeriod::where('year', $year)
            ->where('status', MembershipPeriod::STATUS_ACTIVE)
            ->pluck('member_id')
            ->unique();

        $members = Member::whereIn('id', $activeMemberIds)
            ->whereNotNull('date_of_birth')
            ->whereNotNull('postal_code')
            ->whereNotNull('gender')
            ->get(['id', 'date_of_birth', 'gender', 'postal_code']);

        $inMunicipality = $members->filter(function (Member $m) use ($postalMin, $postalMax) {
            $digits = (int) preg_replace('/\D/', '', $m->postal_code);
            return $digits >= $postalMin && $digits <= $postalMax;
        });

        $withAge = $inMunicipality->map(function (Member $m) use ($snapshotDate) {
            $age = Carbon::parse($m->date_of_birth)->diffInYears($snapshotDate);
            return ['gender' => $m->gender, 'age' => $age];
        });

        $grantEligible = $withAge->filter(fn ($m) => $m['age'] >= $grantAgeMin && $m['age'] <= $grantAgeMax)->count();

        $groups = [
            ['num' => 0, 'label' => '0–5 år',   'min' => 0,  'max' => 5],
            ['num' => 1, 'label' => '6–9 år',    'min' => 6,  'max' => 9],
            ['num' => 2, 'label' => '10–12 år',  'min' => 10, 'max' => 12],
            ['num' => 3, 'label' => '13–15 år',  'min' => 13, 'max' => 15],
            ['num' => 4, 'label' => '16–18 år',  'min' => 16, 'max' => 18],
            ['num' => 5, 'label' => '19–20 år',  'min' => 19, 'max' => 20],
            ['num' => 6, 'label' => '21–64',     'min' => 21, 'max' => 64],
            ['num' => 7, 'label' => '65–',       'min' => 65, 'max' => 999],
        ];

        $femaleLabel = fn (array $g) => $g['min'] <= 20 ? 'Flickor' : 'Kvinnor';
        $maleLabel   = fn (array $g) => $g['min'] <= 20 ? 'Pojkar'  : 'Män';

        $rows = collect($groups)->flatMap(function (array $g) use ($withAge, $femaleLabel, $maleLabel) {
            $inGroup = $withAge->filter(fn ($m) => $m['age'] >= $g['min'] && $m['age'] <= $g['max']);
            return [
                [
                    'num'   => $g['num'],
                    'label' => $femaleLabel($g) . ' ' . $g['label'],
                    'count' => $inGroup->where('gender', Member::GENDER_FEMALE)->count(),
                ],
                [
                    'num'   => $g['num'],
                    'label' => $maleLabel($g) . ' ' . $g['label'],
                    'count' => $inGroup->where('gender', Member::GENDER_MALE)->count(),
                ],
            ];
        });

        $total = $rows->sum('count');

        $firstYear = (int) (MembershipPeriod::min('year') ?: now()->year);
        $years = array_reverse(range(max($firstYear, now()->year - 9), now()->year));

        return view('livewire.admin.statistics.municipality-report', compact(
            'year', 'years',
            'municipalityName', 'grantAgeMin', 'grantAgeMax',
            'rows', 'total', 'grantEligible', 'hasYearOverride',
        ));
    }

    /**
     * Resolve a setting for a specific year.
     * Finds the most recent year-specific override (key pattern: "{key}.YYYY") with year ≤ $year,
     * falling back to the global default if none exists.
     */
    private function resolved(string $key, int $year, mixed $default): mixed
    {
        $override = SystemSetting::where('key', 'like', "{$key}.____")
            ->get(['key', 'value'])
            ->filter(fn ($s) => (int) substr($s->key, strrpos($s->key, '.') + 1) <= $year)
            ->sortByDesc(fn ($s) => (int) substr($s->key, strrpos($s->key, '.') + 1))
            ->first();

        return $override ? $override->value : SystemSetting::get($key, $default);
    }
}
