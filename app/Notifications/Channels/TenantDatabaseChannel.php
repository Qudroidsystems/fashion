<?php

namespace App\Notifications\Channels;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/** Database notifications stamped with the fashion house they belong to. */
class TenantDatabaseChannel extends DatabaseChannel
{
    protected function buildPayload($notifiable, Notification $notification)
    {
        return parent::buildPayload($notifiable, $notification) + [
            'tenant_id' => $notification->tenantId ?? CurrentTenant::id(),
        ];
    }
}
