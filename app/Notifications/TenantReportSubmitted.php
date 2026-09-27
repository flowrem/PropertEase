<?php

namespace App\Notifications;

use App\Models\Concern;
use Illuminate\Notifications\Notification;

class TenantReportSubmitted extends Notification
{
    public function __construct(public Concern $concern) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * The link is stored as a route name, not a URL, and built when shown,
     * so renaming the team (which changes its address) never breaks it.
     *
     * @return array{team_id: int, concern_id: int, message: string, route: string, route_parameters: array<string, mixed>}
     */
    public function toArray(object $notifiable): array
    {
        $lease = $this->concern->lease;

        return [
            'team_id' => $lease->unit->property->team_id,
            'concern_id' => $this->concern->id,
            'message' => __(':tenant reported ":title" in Unit :unit.', [
                'tenant' => $lease->tenant->name,
                'title' => $this->concern->title,
                'unit' => $lease->unit->unit_number,
            ]),
            'route' => 'landlord.maintenance',
            'route_parameters' => ['report' => $this->concern->id],
        ];
    }
}
