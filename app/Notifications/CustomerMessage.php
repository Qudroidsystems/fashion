<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A plain e-mail to a customer from their fashion house. */
class CustomerMessage extends Notification
{
    public function __construct(public string $subject, public string $text, public string $house, public ?string $firstName = null, public ?int $tenantId = null)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $m = (new MailMessage)->subject($this->subject)->greeting('Hello' . ($this->firstName ? ' ' . $this->firstName : '') . ',');
        // The text starts with its own greeting for SMS/WhatsApp; drop it here.
        $body = preg_replace('/^Hello[^\n]*\n+/', '', trim($this->text));
        foreach (preg_split("/\n\s*\n/", $body) as $para) {
            $m->line(trim($para));
        }

        return $m->salutation('— ' . $this->house);
    }
}
