<?php

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReservationCancelled extends Notification implements ShouldQueue
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
            ->subject(__('Your reservation :code was cancelled', ['code' => $this->reservation->code]))
            ->greeting(__('Hello :name,', ['name' => $this->reservation->first_name]))
            ->line(__('Your reservation :code with :team was cancelled and the login details we sent you no longer work.', [
                'code' => $this->reservation->code,
                'team' => $this->reservation->team->name,
            ]))
            ->line(__('Reason: :reason', ['reason' => $this->reservation->cancellation_reason]))
            ->line(__('If you already sent a downpayment, contact the landlord about a refund.'));
    }
}
