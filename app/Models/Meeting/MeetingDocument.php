<?php

namespace App\Models\Meeting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int      $id
 * @property int      $meeting_id
 * @property int|null $agenda_item_id
 * @property string   $type
 * @property bool     $is_protocol_attachment
 * @property string $label
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int    $size_bytes
 * @property int    $uploaded_by
 */
class MeetingDocument extends Model
{
    protected $fillable = [
        'meeting_id', 'agenda_item_id', 'type', 'is_protocol_attachment', 'label', 'disk', 'path',
        'original_name', 'mime_type', 'size_bytes', 'uploaded_by',
    ];

    public const TYPES = [
        'signed_minutes' => 'Justerat protokoll (underskrivet)',
        'attachment'     => 'Bilaga',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function agendaItem(): BelongsTo
    {
        return $this->belongsTo(MeetingAgendaItem::class, 'agenda_item_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function formattedSize(): string
    {
        $kb = $this->size_bytes / 1024;
        return $kb >= 1024
            ? number_format($kb / 1024, 1) . ' MB'
            : number_format($kb, 0) . ' KB';
    }
}
