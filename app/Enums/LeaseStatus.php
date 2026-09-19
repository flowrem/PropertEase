<?php

namespace App\Enums;

enum LeaseStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Ended = 'ended';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Active => 'Active',
            self::Ended => 'Ended',
            self::Terminated => 'Terminated',
        };
    }
}
