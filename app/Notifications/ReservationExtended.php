<?php

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReservationExtended extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Reservation $reservation) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('More time to pay for reservation :code', ['code' => $this->reservation->code]))
            ->greeting(__('Hello :name,', ['name' => $this->reservation->first_name]))
            ->line(__(':team gave you more time. Pay the downpayment by :deadline to keep your reservation.', [
                'team' => $this->reservation->team->name,
                'deadline' => $this->reservation->deadlineForDisplay(),
            ]))
            ->action(__('Pay the downpayment'), $this->reservation->statusUrl());
    }
}
