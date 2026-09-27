<?php

namespace App\Notifications;

use App\Models\ConditionCheck;
use Illuminate\Notifications\Notification;

class RoutineCheckRecorded extends Notification
{
    public function __construct(public ConditionCheck $check) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{team_id: int, condition_check_id: int, message: string, route: string, route_parameters: array<string, mixed>}
     */
    public function toArray(object $notifiable): array
    {
        $unit = $this->check->unit;

        return [
            'team_id' => $unit->property->team_id,
            'condition_check_id' => $this->check->id,
            'message' => __('A routine check of Unit :unit was recorded on :date. See what was found.', [
                'unit' => $unit->unit_number,
                'date' => $this->check->checked_at->format('M j, Y'),
            ]),
            'route' => 'unit-checks',
            'route_parameters' => [],
        ];
    }
}
