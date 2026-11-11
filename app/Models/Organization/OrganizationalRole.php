<?php

namespace App\Models\Organization;

use App\Models\Organization\OrganizationalRolePeriod;
use App\Models\WorkspaceAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class OrganizationalRole extends Model
{
    /** @use HasFactory<\Database\Factories\OrganizationalRoleFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'cross_period_access',
        'provision_google_workspace',
        'discord_role_id',
        'section_id',
    ];

    protected function casts(): array
    {
        return [
            'cross_period_access'        => 'boolean',
            'provision_google_workspace' => 'boolean',
        ];
    }

    public function periods(): HasMany
    {
        return $this->hasMany(OrganizationalRolePeriod::class);
    }

    public function activePeriod(): ?OrganizationalRolePeriod
    {
        return $this->periods()
            ->where('status', OrganizationalRolePeriod::STATUS_ACTIVE)
            ->latest('active_start')
            ->first();
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function workspaceAccount(): MorphOne
    {
        return $this->morphOne(WorkspaceAccount::class, 'accountable');
    }
}
