<?php

namespace App\Notifications;

use App\Enums\ConcernStatus;
use App\Models\ConcernUpdate;
use Illuminate\Notifications\Notification;

class ReportUpdated extends Notification
{
    public function __construct(public ConcernUpdate $update) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{team_id: int, concern_id: int, message: string, route: string, route_parameters: array<string, mixed>}
     */
    public function toArray(object $notifiable): array
    {
        $concern = $this->update->concern;

        return [
            'team_id' => $concern->lease->unit->property->team_id,
            'concern_id' => $concern->id,
            'message' => match ($this->update->new_status) {
                ConcernStatus::Resolved => __('Your report ":title" was resolved.', ['title' => $concern->title]),
                ConcernStatus::InProgress => __('Your report ":title" is being worked on.', ['title' => $concern->title]),
                default => __('Your landlord replied to your report ":title".', ['title' => $concern->title]),
            },
            'route' => 'maintenance',
            'route_parameters' => [],
        ];
    }
}
