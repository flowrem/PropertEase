<?php

namespace App\Enums;

enum ItemServiceAction: string
{
    case Repaired = 'repaired';
    case Replaced = 'replaced';
    case Inspected = 'inspected';

    public function label(): string
    {
        return match ($this) {
            self::Repaired => 'Repaired',
            self::Replaced => 'Replaced',
            self::Inspected => 'Inspected',
        };
    }

    /**
     * Whether this counts as the item having had a problem, for spotting
     * items that keep breaking. An inspection alone does not.
     */
    public function isFix(): bool
    {
        return $this !== self::Inspected;
    }

    /**
     * The actions that count as a fix, as stored values for queries.
     *
     * @return array<int, string>
     */
    public static function fixValues(): array
    {
        return [self::Repaired->value, self::Replaced->value];
    }
}
