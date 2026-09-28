<?php

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReservationExpired extends Notification implements ShouldQueue
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
        $listing = $this->reservation->listing;

        $message = (new MailMessage)
            ->subject(__('Your reservation :code has ended', ['code' => $this->reservation->code]))
            ->greeting(__('Hello :name,', ['name' => $this->reservation->first_name]))
            ->line(__('No downpayment for reservation :code arrived by the deadline (:deadline), so :team has released the unit.', [
                'code' => $this->reservation->code,
                'deadline' => $this->reservation->deadlineForDisplay(),
                'team' => $this->reservation->team->name,
            ]));

        return $listing
            ? $message->line(__('If the unit still has room, you can reserve it again.'))->action(__('See the listing'), route('listings.show', $listing))
            : $message;
    }
}
