<?php

namespace App\Notifications;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Notifications\Notification;

/** Available stock fell below the reorder level (SRS FR-INV-008, §10 — de-duplicated by StockLedger). */
class LowStock extends Notification
{
    public ?int $tenantId;

    public function __construct(public InventoryItem $item, public ?Branch $branch, public float $available)
    {
        $this->tenantId = $item->tenant_id;
    }

    public function via($notifiable): array
    {
        return [TenantDatabaseChannel::class];
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => 'Low stock: ' . $this->item->name,
            'body'  => sprintf('%s available at %s (reorder level %s).',
                $this->item->qty(max(0, $this->available)), $this->branch?->name ?? 'a location', $this->item->qty($this->item->reorder_level)),
            'url'   => route('inventory.items.show', $this->item),
            'icon'  => 'ri-alarm-warning-line',
        ];
    }
}
