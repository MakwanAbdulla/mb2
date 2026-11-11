<?php

namespace App\Models\Accounting;

use App\Models\Accounting\CostCenter;
use App\Models\Accounting\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id',
        'account_id',
        'cost_center_id',
        'description',
        'amount_ex_vat',
        'vat_rate',
        'vat_amount',
        'sort_order',
    ];

    protected $casts = [
        'amount_ex_vat' => 'integer',
        'vat_rate'      => 'integer',
        'vat_amount'    => 'integer',
        'sort_order'    => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function total(): int
    {
        return $this->amount_ex_vat + $this->vat_amount;
    }
}
