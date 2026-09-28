<?php

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReservationAccepted extends Notification implements ShouldQueue
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
        $reservation = $this->reservation;
        $amount = $reservation->listing?->downpayment_amount;

        return (new MailMessage)
            ->subject(__('Pay the downpayment by :deadline to keep your reservation', ['deadline' => $reservation->deadlineForDisplay()]))
            ->greeting(__('Hello :name,', ['name' => $reservation->first_name]))
            ->line(__(':team accepted your reservation :code and is holding the unit for you.', [
                'team' => $reservation->team->name,
                'code' => $reservation->code,
            ]))
            ->line($amount !== null
                ? __('Pay the downpayment of :amount by :deadline and send your proof of payment from the link below.', [
                    'amount' => '₱'.number_format((float) $amount, 2),
                    'deadline' => $reservation->deadlineForDisplay(),
                ])
                : __('Ask the landlord how much the downpayment is, pay it by :deadline, and send your proof of payment from the link below.', [
                    'deadline' => $reservation->deadlineForDisplay(),
                ]))
            ->action(__('Pay the downpayment'), $reservation->statusUrl())
            ->line(__('If nothing is sent by then, the reservation ends and the unit opens to others.'));
    }
}
