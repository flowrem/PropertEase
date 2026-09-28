<?php

namespace App\Notifications;

use App\Models\LeaseContract;
use Illuminate\Notifications\Notification;

class ContractReady extends Notification
{
    public function __construct(public LeaseContract $contract) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Opens the tenant's Contract page.
     *
     * @return array{team_id: int, lease_contract_id: int, message: string, route: string, route_parameters: array<string, mixed>}
     */
    public function toArray(object $notifiable): array
    {
        $unit = $this->contract->lease->unit;

        return [
            'team_id' => $unit->property->team_id,
            'lease_contract_id' => $this->contract->id,
            'message' => __('Your contract for Unit :unit is ready. Please read it and agree.', ['unit' => $unit->unit_number]),
            'route' => 'contract',
            'route_parameters' => [],
        ];
    }
}
