<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Notifications\Channels\TenantDatabaseChannel;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Last week in a few numbers, by e-mail and in the bell (see WeeklySummary). */
class WeeklySummaryNotice extends Notification
{
    public ?int $tenantId;

    public function __construct(public Tenant $tenant, public array $f)
    {
        $this->tenantId = $tenant->id;
    }

    public function via($notifiable): array
    {
        return $notifiable->email ? ['mail', TenantDatabaseChannel::class] : [TenantDatabaseChannel::class];
    }

    public function toMail($notifiable): MailMessage
    {
        $f = $this->f;
        $mail = (new MailMessage)
            ->subject($this->tenant->name . ' — your week (' . $f['label'] . ')')
            ->greeting('Good morning ' . strtok((string) $notifiable->name, ' ') . ',')
            ->line('Here is last week at ' . $this->tenant->name . ' (' . $f['label'] . '):')
            ->line('**Money collected:** ' . Money::format($f['collected']))
            ->line('**Sales earned** (orders handed over and till sales): ' . Money::format($f['earned']))
            ->line('**Expenses recorded:** ' . Money::format($f['expenses']))
            ->line('**Orders confirmed:** ' . $f['new_orders'] . ' · **handed over:** ' . $f['delivered'])
            ->line('**Owed to you now:** ' . Money::format($f['owed']) . ($f['handed'] > 0 ? ' — ' . Money::format($f['handed']) . ' of it on garments already handed over' : ''));
        if ($f['overdue'] > 0) {
            $mail->line('⚠ **' . $f['overdue'] . ' order(s) are past their promised date.**');
        }

        return $mail->line($f['due_week'] . ' order(s) are due this week.')
            ->action('Open the reports', route('reports.index'))
            ->salutation('— ' . config('app.name'));
    }

    public function toArray($notifiable): array
    {
        $f = $this->f;

        return [
            'title' => 'Your week: ' . Money::format($f['collected']) . ' collected',
            'body'  => $f['label'] . ' · earned ' . Money::format($f['earned']) . ' · owed ' . Money::format($f['owed'])
                . ($f['overdue'] ? ' · ' . $f['overdue'] . ' order(s) late' : ''),
            'url'   => route('reports.show', ['sales', 'period' => 'last_week']),
            'icon'  => 'ri-bar-chart-box-line',
        ];
    }
}
