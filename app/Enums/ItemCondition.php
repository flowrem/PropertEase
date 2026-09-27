<?php

namespace App\Enums;

enum ItemCondition: string
{
    case GoodAsNew = 'good_as_new';
    case Working = 'working';
    case NeedsRepair = 'needs_repair';
    case NotWorking = 'not_working';
    case Missing = 'missing';

    public function label(): string
    {
        return match ($this) {
            self::GoodAsNew => 'Good as new',
            self::Working => 'Working',
            self::NeedsRepair => 'Needs repair',
            self::NotWorking => 'Not working',
            self::Missing => 'Missing',
        };
    }

    /**
     * Get the badge color used to represent this condition.
     */
    public function color(): string
    {
        return match ($this) {
            self::GoodAsNew, self::Working => 'green',
            self::NeedsRepair => 'amber',
            self::NotWorking, self::Missing => 'red',
        };
    }

    /**
     * Whether an item in this condition stops a tenant moving in until it is
     * fixed, or the landlord proceeds anyway with a reason.
     */
    public function blocksMoveIn(): bool
    {
        return $this === self::NotWorking || $this === self::Missing;
    }

    /**
     * The conditions that block a move-in, as stored values for queries.
     *
     * @return array<int, string>
     */
    public static function blockingValues(): array
    {
        return array_values(array_map(
            fn (self $condition): string => $condition->value,
            array_filter(self::cases(), fn (self $condition): bool => $condition->blocksMoveIn()),
        ));
    }
}
