<?php

namespace Database\Factories;

use App\Enums\ItemCondition;
use App\Models\ConditionCheck;
use App\Models\ConditionCheckItem;
use App\Models\UnitItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConditionCheckItem>
 */
class ConditionCheckItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'condition_check_id' => ConditionCheck::factory(),
            'unit_item_id' => UnitItem::factory(),
            'condition' => ItemCondition::Working,
        ];
    }

    public function condition(ItemCondition $condition): static
    {
        return $this->state(fn () => ['condition' => $condition]);
    }
}
