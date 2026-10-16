<?php

namespace App\Notifications;

use App\Models\ProductionTask;
use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Notifications\Notification;

/** A production task was given to someone, or became ready for them (SRS FR-PRD-002). */
class TaskAssigned extends Notification
{
    public ?int $tenantId;

    public function __construct(public ProductionTask $task, public bool $ready = false)
    {
        $this->tenantId = $task->tenant_id;
    }

    public function via($notifiable): array
    {
        return [TenantDatabaseChannel::class];
    }

    public function toArray($notifiable): array
    {
        $t = $this->task->loadMissing('order', 'line');

        return [
            'title' => ($this->ready ? 'Ready for you: ' : 'New task: ') . $t->name,
            'body'  => trim($t->order->number . ' · ' . ($t->line?->description ?? '') . ($t->due_date ? ' · due ' . $t->due_date->format('d M') : ''), ' ·'),
            'url'   => route('production.tasks.show', $t),
            'icon'  => 'ri-scissors-cut-line',
        ];
    }
}
