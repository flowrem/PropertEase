<?php

namespace Database\Factories;

use App\Models\ContractTemplate;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractTemplate>
 */
class ContractTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'advance_months' => 1,
            'deposit_months' => 2,
            'minimum_stay_months_short' => 1,
            'minimum_stay_months_long' => 12,
            'notice_days' => 30,
            'late_fee' => null,
            'house_rules' => 'No smoking inside the unit.',
            'additional_terms' => null,
        ];
    }
}
