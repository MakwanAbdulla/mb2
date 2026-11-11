<?php

namespace App\Enums;

enum SurveyStatus: string
{
    case Draft  = 'draft';
    case Active = 'active';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft  => 'Utkast',
            self::Active => 'Aktiv',
            self::Closed => 'Stängd',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft  => 'zinc',
            self::Active => 'green',
            self::Closed => 'red',
        };
    }
}
