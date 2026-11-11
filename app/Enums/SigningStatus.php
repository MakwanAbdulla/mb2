<?php

namespace App\Enums;

enum SigningStatus: string
{
    // Local only — not yet sent to DocSign
    case Draft = 'draft';

    // Mirrors DocSign's document `state`
    case Pending = 'pending';
    case Paused = 'paused';
    case Completed = 'completed';

    // Local only
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Utkast',
            self::Pending => 'Väntar på signering',
            self::Paused => 'Pausad',
            self::Completed => 'Klar',
            self::Cancelled => 'Avbruten',
            self::Failed => 'Fel',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::Pending => 'amber',
            self::Paused => 'blue',
            self::Completed => 'green',
            self::Cancelled => 'zinc',
            self::Failed => 'red',
        };
    }

    /** Statuses the scheduled sync should poll DocSign for. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Paused], true);
    }

    /** Map DocSign's `state` (pending | paused | completed) to our status. */
    public static function fromProviderState(string $state): self
    {
        return match ($state) {
            'pending' => self::Pending,
            'paused' => self::Paused,
            'completed' => self::Completed,
            default => throw new \UnexpectedValueException("Unknown DocSign state: {$state}"),
        };
    }
}
