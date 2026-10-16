<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Notifications\Notification;

/** FR-PRD-009 / §10: explainable late-delivery warning, sent only when the risk goes up. */
class DeliveryAtRisk extends Notification
{
    public ?int $tenantId;

    public function __construct(public Order $order, public array $assessment)
    {
        $this->tenantId = $order->tenant_id;
    }

    public function via($notifiable): array
    {
        return [TenantDatabaseChannel::class];
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => ($this->assessment['level'] === 'high' ? 'Likely late: ' : 'Watch: ') . $this->order->number,
            'body'  => collect($this->assessment['reasons'])->pluck('text')->take(2)->implode(' ') . ' Promised ' . $this->order->promised_date?->format('d M') . '.',
            'url'   => route('orders.show', $this->order),
            'icon'  => 'ri-timer-flash-line',
        ];
    }
}
