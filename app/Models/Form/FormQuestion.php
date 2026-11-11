<?php

namespace App\Models\Form;

use App\Enums\QuestionType;
use App\Models\Rental\RentalItemType;
use App\Models\Shop\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int          $id
 * @property int          $form_id
 * @property QuestionType $type
 * @property string       $label
 * @property string|null  $description
 * @property bool         $is_required
 * @property int          $sort_order
 * @property int|null     $scale_min
 * @property int|null     $scale_max
 * @property string|null  $scale_icon
 * @property string|null  $datetime_variant
 * @property float|null   $number_min
 * @property float|null   $number_max
 * @property int|null     $product_id
 * @property array|null   $event_ids
 * @property array|null   $section_ids
 * @property bool         $section_multi
 * @property string|null  $membership_type
 * @property int|null     $rental_item_type_id
 */
class FormQuestion extends Model
{
    protected $fillable = [
        'form_id',
        'type',
        'label',
        'description',
        'is_required',
        'sort_order',
        'scale_min',
        'scale_max',
        'scale_icon',
        'datetime_variant',
        'number_min',
        'number_max',
        'product_id',
        'event_ids',
        'section_ids',
        'section_multi',
        'membership_type',
        'rental_item_type_id',
    ];

    protected function casts(): array
    {
        return [
            'type'        => QuestionType::class,
            'is_required' => 'boolean',
            'sort_order'  => 'integer',
            'scale_min'   => 'integer',
            'scale_max'   => 'integer',
            'number_min'    => 'float',
            'number_max'    => 'float',
            'event_ids'     => 'array',
            'section_ids'   => 'array',
            'section_multi' => 'boolean',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function rentalItemType(): BelongsTo
    {
        return $this->belongsTo(RentalItemType::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(FormQuestionOption::class, 'question_id')->orderBy('sort_order');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(FormAnswer::class, 'question_id');
    }
}
