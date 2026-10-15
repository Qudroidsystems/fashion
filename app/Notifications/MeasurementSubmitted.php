<?php

namespace App\Notifications;

use App\Models\MeasurementSession;
use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Notifications\Notification;

/** A customer sent measurements through a request link (SRS FR-LNK-006). */
class MeasurementSubmitted extends Notification
{
    public ?int $tenantId;

    public function __construct(public MeasurementSession $session)
    {
        $this->tenantId = $session->tenant_id;
    }

    public function via($notifiable): array
    {
        return [TenantDatabaseChannel::class];
    }

    public function toArray($notifiable): array
    {
        $customer = $this->session->customer;

        return [
            'title' => 'Measurements received',
            'body'  => ($customer?->name ?? 'A customer') . ' sent their measurements. They need your review before use.',
            'url'   => route('measurements.show', $this->session),
            'icon'  => 'ri-ruler-2-line',
        ];
    }
}
