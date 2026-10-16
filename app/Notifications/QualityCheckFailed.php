<?php

namespace App\Notifications;

use App\Models\ProductionTask;
use App\Models\QualityCheck;
use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** §10: tell the production manager and the rework owner — the issue, not customer details. */
class QualityCheckFailed extends Notification
{
    public ?int $tenantId;

    public function __construct(public QualityCheck $check, public ProductionTask $rework)
    {
        $this->tenantId = $check->tenant_id;
    }

    public function via($notifiable): array
    {
        return [TenantDatabaseChannel::class];
    }

    public function toArray($notifiable): array
    {
        $r = $this->rework->loadMissing('order', 'line');

        return [
            'title' => 'Quality check failed: ' . ($r->line?->description ?? $r->order->number),
            'body'  => Str::limit((string) $this->check->issues, 160) . ' · rework ' . $r->number,
            'url'   => route('production.tasks.show', $r),
            'icon'  => 'ri-error-warning-line',
        ];
    }
}
