<?php

namespace App\Notifications;

use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LandlordRejected extends Notification implements ShouldQueue
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
            ->subject(__('We could not approve your :app account', ['app' => config('app.name')]))
            ->line(__(':team was not approved yet.', ['team' => $this->team->name]))
            ->line(__('Reason: :reason', ['reason' => $this->team->rejection_reason]))
            ->line(__('Log in to upload a new ID and try again.'))
            ->action(__('Log in'), route('login'));
    }
}
