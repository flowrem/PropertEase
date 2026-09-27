<?php

namespace Database\Factories;

use App\Enums\ItemServiceAction;
use App\Models\ItemService;
use App\Models\UnitItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemService>
 */
class ItemServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_item_id' => UnitItem::factory(),
            'action' => ItemServiceAction::Repaired,
            'performed_at' => today(),
        ];
    }

    public function action(ItemServiceAction $action): static
    {
        return $this->state(fn () => ['action' => $action]);
    }
}
