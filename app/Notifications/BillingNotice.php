<?php

namespace App\Notifications;

use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Subscription news for a house owner: trial ending, renewal failed, moved to free, payment received. */
class BillingNotice extends Notification
{
    public function __construct(public int $tenantId, public string $title, public string $body, public ?string $action = 'See your plan')
    {
    }

    public function via($notifiable): array
    {
        return [TenantDatabaseChannel::class, 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title)->greeting('Hello ' . strtok((string) $notifiable->name, ' ') . ',')->line($this->body);

        return $this->action ? $mail->action($this->action, route('billing.index')) : $mail;
    }

    public function toArray($notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'url' => route('billing.index'), 'icon' => 'ri-vip-crown-line'];
    }
}
