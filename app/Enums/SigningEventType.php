<?php

namespace App\Enums;

enum SigningEventType: string
{
    case Created = 'created';
    case Sent = 'sent';
    case CallbackReceived = 'callback_received';
    case SignerSigned = 'signer_signed';
    case Synced = 'synced';
    case Completed = 'completed';
    case Paused = 'paused';
    case Resumed = 'resumed';
    case Cancelled = 'cancelled';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Skapad',
            self::Sent => 'Skickad för signering',
            self::CallbackReceived => 'Anrop från DocSign',
            self::SignerSigned => 'Undertecknare signerade',
            self::Synced => 'Status hämtad',
            self::Completed => 'Färdigsignerad',
            self::Paused => 'Pausad',
            self::Resumed => 'Återupptagen',
            self::Cancelled => 'Avbruten',
            self::Error => 'Fel',
        };
    }
}
