<?php

namespace App\Enums;

enum ConcernPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
            self::Urgent => 'Urgent',
        };
    }

    /**
     * Get the badge color used to represent this priority.
     */
    public function color(): string
    {
        return match ($this) {
            self::Low => 'zinc',
            self::Medium => 'blue',
            self::High => 'amber',
            self::Urgent => 'red',
        };
    }

    /**
     * Get a sortable weight, higher meaning more important.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Urgent => 4,
        };
    }
}
