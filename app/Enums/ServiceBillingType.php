<?php

namespace App\Enums;

enum ServiceBillingType: string
{
    case Fixed = 'fixed';
    case Metered = 'metered';
    case Variable = 'variable';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed',
            self::Metered => 'Metered',
            self::Variable => 'Variable',
        };
    }
}
