<?php

namespace App\Models\Shop;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductDimension extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'product_id',
        'label',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
