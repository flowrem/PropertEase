<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Carries a plaintext temporary password, so it is queued and encrypted at
 * rest. It has no database channel and must never be logged or flashed.
 */
class TenantAccountCreated extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $username,
        public string $temporaryPassword,
        public CarbonInterface $expiresAt,
        public string $teamName,
    ) {}

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
            ->subject(__('Your :team reservation was approved', ['team' => $this->teamName]))
            ->line(__('Your reservation with :team was approved. Use these details to log in.', ['team' => $this->teamName]))
            ->line(__('Username: :username', ['username' => $this->username]))
            ->line(__('Temporary password: :password', ['password' => $this->temporaryPassword]))
            ->line(__('This password expires on :date. You will be asked to choose a new one when you first log in.', [
                'date' => $this->expiresAt->timezone(config('app.timezone'))->format('M j, Y g:i A'),
            ]))
            ->action(__('Log in'), route('login'));
    }
}
