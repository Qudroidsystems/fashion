<?php

namespace App\Notifications;

use App\Models\Fitting;
use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Fitting reminder (§10): in-app for the staff member, email for the customer (with consent). */
class FittingReminderDue extends Notification
{
    public ?int $tenantId;

    public function __construct(public Fitting $fitting, public bool $forCustomer)
    {
        $this->tenantId = $fitting->tenant_id;
    }

    public function via($notifiable): array
    {
        return $this->forCustomer ? ['mail'] : [TenantDatabaseChannel::class];
    }

    protected function when(): string
    {
        $tz = $this->fitting->tenant?->timezone ?? config('app.timezone');

        return $this->fitting->scheduled_at->timezone($tz)->format('l j F, g:ia');
    }

    public function toMail($notifiable): MailMessage
    {
        $f = $this->fitting->loadMissing('customer', 'branch', 'tenant');
        $house = $f->tenant?->name ?? config('app.name');

        return (new MailMessage)
            ->subject("Your fitting at {$house} — " . $this->when())
            ->greeting('Hello ' . $f->customer->first_name . ',')
            ->line("This is a reminder of your fitting on {$this->when()}" . ($f->branch ? " at {$f->branch->name}" . ($f->branch->address ? " ({$f->branch->address})" : '') : '') . '.')
            ->line('If you can no longer make it, please call us' . ($f->tenant?->phone ? ' on ' . $f->tenant->phone : '') . ' so we can move it.')
            ->salutation("— {$house}");
    }

    public function toArray($notifiable): array
    {
        $f = $this->fitting->loadMissing('customer', 'order');

        return [
            'title' => 'Fitting ' . $this->when(),
            'body'  => $f->customer->name . ($f->order ? ' · ' . $f->order->number : ''),
            'url'   => route('fittings.show', $f),
            'icon'  => 'ri-calendar-check-line',
        ];
    }
}
