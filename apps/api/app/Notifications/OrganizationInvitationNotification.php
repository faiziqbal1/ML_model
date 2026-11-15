<?php

namespace App\Notifications;

use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $organizationName,
        private readonly string $invitationId,
        private readonly string $token,
        private readonly string $role,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = FrontendUrl::get();
        $invitationUrl = $frontendUrl.'/?'.http_build_query([
            'invitation' => $this->invitationId,
            'token' => $this->token,
        ]);

        return (new MailMessage)
            ->subject("Invitation to join {$this->organizationName}")
            ->greeting('You have been invited to ModelForge')
            ->line("You have been invited to join {$this->organizationName} as {$this->role}.")
            ->action('Review invitation', $invitationUrl)
            ->line('Sign in with this email address and verify it before accepting.');
    }
}
