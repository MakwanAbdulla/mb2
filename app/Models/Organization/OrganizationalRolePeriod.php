<?php

namespace App\Models\Organization;

use App\Models\Organization\OrganizationalRoleMember;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationalRolePeriod extends Model
{
    /** @use HasFactory<\Database\Factories\OrganizationalRolePeriodFactory> */
    use HasFactory;

    const TYPE_CALENDAR_YEAR = 'calendar_year';
    const TYPE_CUSTOM = 'custom';

    const STATUS_FORMING = 'forming';
    const STATUS_ACTIVE = 'active';
    const STATUS_REPORTING = 'reporting';
    const STATUS_CLOSED = 'closed';

    /** @var list<string> */
    protected $fillable = [
        'organizational_role_id',
        'name',
        'period_type',
        'year',
        'custom_start',
        'custom_end',
        'forming_start',
        'active_start',
        'active_end',
        'reporting_end',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'custom_start' => 'date',
            'custom_end' => 'date',
            'forming_start' => 'date',
            'active_start' => 'date',
            'active_end' => 'date',
            'reporting_end' => 'date',
        ];
    }

    public function organizationalRole(): BelongsTo
    {
        return $this->belongsTo(OrganizationalRole::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationalRoleMember::class);
    }

    public function currentMembers(): HasMany
    {
        return $this->members()->where('transition_role', OrganizationalRoleMember::TRANSITION_CURRENT);
    }

    public function transitionTo(string $status): void
    {
        $this->update(['status' => $status]);
    }

    /**
     * Compute the status from dates; can be overridden by the stored status.
     */
    public function computedStatus(): string
    {
        $today = now()->startOfDay();

        if ($this->forming_start && $today->lt($this->active_start)) {
            return self::STATUS_FORMING;
        }

        if ($today->between($this->active_start, $this->active_end)) {
            return self::STATUS_ACTIVE;
        }

        if ($this->reporting_end && $today->lte($this->reporting_end)) {
            return self::STATUS_REPORTING;
        }

        return self::STATUS_CLOSED;
    }
}
