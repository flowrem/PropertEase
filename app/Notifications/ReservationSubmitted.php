<?php

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Notifications\Notification;

class ReservationSubmitted extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(public Reservation $reservation)
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the database representation of the notification. It carries no
     * ID or payment details, only enough to say who applied and for what.
     *
     * @return array{team_id: int, reservation_id: int, code: string, applicant: string, message: string, route: string, route_parameters: array<string, mixed>}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'team_id' => $this->reservation->team_id,
            'route' => 'reservations',
            'route_parameters' => ['reservation' => $this->reservation->id],
            'reservation_id' => $this->reservation->id,
            'code' => $this->reservation->code,
            'applicant' => $this->reservation->fullName(),
            'message' => __(':name submitted a reservation (:code).', [
                'name' => $this->reservation->fullName(),
                'code' => $this->reservation->code,
            ]),
        ];
    }
}
