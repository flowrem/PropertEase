<?php

namespace App\Enums;

enum BillingTiming: string
{
    case Advance = 'advance';
    case Arrears = 'arrears';

    public function label(): string
    {
        return match ($this) {
            self::Advance => 'Advance',
            self::Arrears => 'Arrears',
        };
    }
}
