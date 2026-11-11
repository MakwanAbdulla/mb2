<?php

namespace App\Models\Content;

use App\Models\Membership\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailingRecipient extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'mailing_id',
        'email',
        'member_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function mailing(): BelongsTo
    {
        return $this->belongsTo(Mailing::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
