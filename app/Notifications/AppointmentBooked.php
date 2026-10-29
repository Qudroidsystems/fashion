<?php

namespace App\Notifications;

use App\Models\Appointment;
use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Notifications\Notification;

/** In-app note to the staff member an appointment was booked with. */
class AppointmentBooked extends Notification
{
    public ?int $tenantId;

    public function __construct(public Appointment $appointment)
    {
        $this->tenantId = $appointment->tenant_id;
    }

    public function via($notifiable): array
    {
        return [TenantDatabaseChannel::class];
    }

    public function toArray($notifiable): array
    {
        $a = $this->appointment->loadMissing('customer', 'tenant');
        $tz = $a->tenant?->timezone ?? config('app.timezone');

        return [
            'title' => $a->type_label . ' · ' . $a->starts_at->copy()->timezone($tz)->format('D j M, g:i A'),
            'body'  => $a->customer?->name . ' is booked with you.',
            'url'   => route('appointments.show', $a),
            'icon'  => 'ri-calendar-todo-line',
        ];
    }
}
