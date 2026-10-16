<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectUpdate;
use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * project.milestone.published (§10): the client by email (with consent) and the
 * project manager in-app. Only customer-visible, published content is sent.
 */
class ProjectPublished extends Notification
{
    public ?int $tenantId;

    public function __construct(public Project $project, public ProjectMilestone|ProjectUpdate $item, public bool $forClient, public ?string $trackingUrl = null)
    {
        $this->tenantId = $project->tenant_id;
    }

    public function via($notifiable): array
    {
        return $this->forClient ? ['mail'] : [TenantDatabaseChannel::class];
    }

    protected function headline(): string
    {
        return $this->item instanceof ProjectMilestone
            ? ($this->item->status === 'done' ? "Done: {$this->item->name}" : "Coming up: {$this->item->name}")
            : ($this->item->title ?: 'A new update');
    }

    public function toMail($notifiable): MailMessage
    {
        $p = $this->project->loadMissing('customer', 'tenant');
        $house = $p->tenant?->name ?? config('app.name');
        $mail = (new MailMessage)
            ->subject("{$p->name}: " . $this->headline())
            ->greeting('Hello ' . $p->customer->first_name . ',');
        if ($this->item instanceof ProjectMilestone) {
            $mail->line($this->item->status === 'done'
                ? "We have reached a milestone on {$p->name}: {$this->item->name}."
                : "Next on {$p->name}: {$this->item->name}" . ($this->item->due_date ? ' (planned for ' . $this->item->due_date->format('j F') . ')' : '') . '.');
            if ($this->item->notes) {
                $mail->line($this->item->notes);
            }
        } else {
            $mail->line(Str::limit($this->item->body, 600));
        }
        if ($this->trackingUrl) {
            $mail->action('See progress', $this->trackingUrl);
        }

        return $mail->salutation("— {$house}");
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => 'Published to client · ' . $this->project->name,
            'body'  => $this->headline(),
            'url'   => route('projects.show', $this->project),
            'icon'  => 'ri-heart-2-line',
        ];
    }
}
