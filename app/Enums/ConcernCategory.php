<?php

namespace App\Enums;

enum ConcernCategory: string
{
    case Maintenance = 'maintenance';
    case Complaint = 'complaint';

    public function label(): string
    {
        return match ($this) {
            self::Maintenance => 'Maintenance',
            self::Complaint => 'Complaint',
        };
    }
}
