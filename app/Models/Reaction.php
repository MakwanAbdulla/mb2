<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Reaction extends Model
{
    /** Full curated set available for admins to pick from. */
    public const CURATED = [
        'Reaktioner' => ['👍','👎','❤️','🔥','🎉','😂','😮','😢','😡','💯','👀','🤔'],
        'Ansikten'   => ['😀','😄','😅','🥹','😊','🙂','😐','😬','🧐','😎','🤩','🥳','😍','🤯','😤','😭','🤣','😇','🤗','🫡','🫠','😴'],
        'Gester'     => ['👏','🙌','🤝','🫶','💪','🤞','✌️','🤙','👋','🫂','🤦','🤷'],
        'Spel'       => ['🎮','🕹️','🎲','♟️','🃏','🎯','🏆','🥇','💻','🖥️','🎧','🤖','👾','🐉'],
        'Symboler'   => ['✅','❌','❓','❗','💡','⭐','🌟','💥','✨','🚀','💀','👻','🍕','💩','🎶','📌','🔔'],
        'Siffror'    => ['0️⃣','1️⃣','2️⃣','3️⃣','4️⃣','5️⃣','6️⃣','7️⃣','8️⃣','9️⃣'],
    ];

    /** Default set used as fallback if no setting exists. */
    public const DEFAULT_EMOJIS = ['👍','❤️','😂','😮','😢','🔥','🎉','👀','💯','🤔'];

    protected $fillable = ['reactable_type', 'reactable_id', 'user_id', 'emoji'];

    /** Active reaction emojis — reads from SystemSetting with fallback to default. */
    public static function allowed(): array
    {
        return SystemSetting::get('sections.reaction_emojis', self::DEFAULT_EMOJIS) ?? self::DEFAULT_EMOJIS;
    }

    /** All curated emojis as a flat list. */
    public static function allCurated(): array
    {
        return array_merge(...array_values(self::CURATED));
    }

    /** Usage counts keyed by emoji: ['👍' => 142, '❤️' => 38, ...] */
    public static function usageCounts(): array
    {
        return static::query()
            ->selectRaw('emoji, count(*) as cnt')
            ->groupBy('emoji')
            ->pluck('cnt', 'emoji')
            ->all();
    }

    public function reactable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
