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
     * @return array{reservation_id: int, code: string, applicant: string, message: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
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
