<?php

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Notifications\Notification;

class DownpaymentSubmitted extends Notification
{
    public function __construct(public Reservation $reservation) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Opens the reservation on the landlord's Reservations page. Carries no
     * payment details.
     *
     * @return array{team_id: int, reservation_id: int, code: string, message: string, route: string, route_parameters: array<string, mixed>}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'team_id' => $this->reservation->team_id,
            'route' => 'reservations',
            'route_parameters' => ['reservation' => $this->reservation->id],
            'reservation_id' => $this->reservation->id,
            'code' => $this->reservation->code,
            'message' => __(':name sent the downpayment for reservation :code. Check it and confirm.', [
                'name' => $this->reservation->fullName(),
                'code' => $this->reservation->code,
            ]),
        ];
    }
}
