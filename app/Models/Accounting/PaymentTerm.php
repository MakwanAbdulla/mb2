<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentTerm extends Model
{
    protected $fillable = [
        'code',
        'name',
        'due_days',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'due_days'   => 'integer',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Calculate due date from an invoice date. */
    public function dueDateFrom(\DateTimeInterface|string $invoiceDate): \Carbon\Carbon
    {
        return \Carbon\Carbon::parse($invoiceDate)->addDays($this->due_days);
    }
}
