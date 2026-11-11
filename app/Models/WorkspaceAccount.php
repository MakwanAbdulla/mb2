<?php

namespace App\Models;

use App\Models\Membership\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WorkspaceAccount extends Model
{
    protected $fillable = [
        'email',
        'display_name',
        'description',
        'accountable_type',
        'accountable_id',
        'pending_deletion_at',
    ];

    protected function casts(): array
    {
        return [
            'pending_deletion_at' => 'datetime',
        ];
    }

    public function accountable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPersonal(): bool
    {
        return $this->accountable_type === 'member';
    }

    public function scopePersonal($query)
    {
        return $query->where('accountable_type', 'member');
    }

    public function scopeNonPersonal($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('accountable_type')
              ->orWhere('accountable_type', '!=', 'member');
        });
    }
}
