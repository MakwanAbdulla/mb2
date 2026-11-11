<?php

namespace App\Models\Content;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailingTemplate extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'category',
        'description',
        'subject',
        'body_html',
        'created_by',
        'permission_required',
    ];

    public function isAvailableTo(User $user): bool
    {
        if ($user->hasPermissionTo('mailings.manage') && $this->permission_required === null) {
            return true;
        }

        return $user->hasPermissionTo('mailings.manage')
            && ($this->permission_required === null
                || $user->hasPermissionTo($this->permission_required));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function mailings(): HasMany
    {
        return $this->hasMany(Mailing::class);
    }
}
