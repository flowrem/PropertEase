<?php

namespace App\Notifications;

use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LandlordApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Team $team) {}

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
            ->subject(__('Your :app account was approved', ['app' => config('app.name')]))
            ->line(__(':team was approved. You can now set up your properties and units.', ['team' => $this->team->name]))
            ->action(__('Log in'), route('login'));
    }
}
