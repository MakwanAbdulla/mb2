<?php

namespace App\Enums;

enum SignMethod: string
{
    case BankId = 'bankid';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::BankId => 'BankID',
            self::Email => 'E-post',
        };
    }
}
