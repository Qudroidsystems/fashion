<?php

namespace App\Notifications;

use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Notifications\Notification;

/** Daily, for staff who look after customer materials: what should already have gone back. */
class MaterialsAwaitingReturn extends Notification
{
    public function __construct(public int $items, public int $customers, public ?int $tenantId = null)
    {
    }

    public function via($notifiable): array
    {
        return [TenantDatabaseChannel::class];
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => $this->items . ' customer ' . ($this->items === 1 ? 'item' : 'items') . ' to give back',
            'body'  => 'Belonging to ' . $this->customers . ' ' . ($this->customers === 1 ? 'customer' : 'customers') . '. Return them, or call the customer.',
            'url'   => route('materials.index', ['show' => 'overdue']),
            'icon'  => 'ri-archive-drawer-line',
        ];
    }
}
