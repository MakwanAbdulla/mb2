<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class ApprovalRule extends Model
{
    public const INVOICE_TYPE_PAYABLE    = 'payable';
    public const INVOICE_TYPE_RECEIVABLE = 'receivable';
    public const INVOICE_TYPE_EXPENSE    = 'expense';
    public const INVOICE_TYPE_ANY        = 'any';

    protected $fillable = [
        'name',
        'invoice_type',
        'amount_min',
        'amount_max',
        'required_approvals',
        'approver_permission',
        'block_submitter',
        'block_beneficiary',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'amount_min'          => 'integer',
        'amount_max'          => 'integer',
        'required_approvals'  => 'integer',
        'block_submitter'     => 'boolean',
        'block_beneficiary'   => 'boolean',
        'is_active'           => 'boolean',
        'sort_order'          => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function appliesToAmount(int $amountOre): bool
    {
        if ($this->amount_min !== null && $amountOre < $this->amount_min) {
            return false;
        }
        if ($this->amount_max !== null && $amountOre > $this->amount_max) {
            return false;
        }
        return true;
    }
}
