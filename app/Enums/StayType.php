<?php

namespace App\Enums;

enum StayType: string
{
    case ShortTerm = 'short_term';
    case LongTerm = 'long_term';

    public function label(): string
    {
        return match ($this) {
            self::ShortTerm => 'Short-term',
            self::LongTerm => 'Long-term',
        };
    }

    /**
     * One line explaining the choice to an applicant.
     */
    public function description(): string
    {
        return match ($this) {
            self::ShortTerm => 'A few months, such as one school term.',
            self::LongTerm => 'A year or more.',
        };
    }
}
