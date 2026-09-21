<?php

namespace App\Notifications\Teams;

use App\Enums\TeamRole;
use App\Models\TeamInvitation as TeamInvitationModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public TeamInvitationModel $invitation)
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
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $isTenant = $this->invitation->role === TeamRole::Tenant;

        $replacements = [
            'inviterName' => $this->invitation->inviter->name,
            'teamName' => $this->invitation->team->name,
        ];

        $subject = $isTenant
            ? __("You've been invited to become a tenant under :teamName", $replacements)
            : __("You've been invited to join :teamName", $replacements);

        $invitedBy = $isTenant
            ? __(':inviterName has invited you to become a tenant under :teamName.', $replacements)
            : __(':inviterName has invited you to join :teamName.', $replacements);

        return (new MailMessage)
            ->subject($subject)
            ->line($invitedBy)
            ->line(__('Log in and visit your dashboard to accept or decline this invitation.'))
            ->action(
                __('Log in'),
                route('login', ['invitation' => $this->invitation->code]),
            );
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invitation_id' => $this->invitation->id,
            'team_id' => $this->invitation->team_id,
            'team_name' => $this->invitation->team->name,
            'role' => $this->invitation->role->value,
        ];
    }
}
