<?php

namespace App\Notifications;

use App\Models\SiteEnquiry;
use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** In-app note to staff when someone writes through the house website. */
class NewSiteEnquiry extends Notification
{
    public ?int $tenantId;

    public function __construct(public SiteEnquiry $enquiry)
    {
        $this->tenantId = $enquiry->tenant_id;
    }

    public function via($notifiable): array
    {
        return [TenantDatabaseChannel::class];
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => 'Website: ' . $this->enquiry->type_label . ' from ' . $this->enquiry->name,
            'body'  => Str::limit((string) $this->enquiry->message, 120) ?: ($this->enquiry->phone ?? $this->enquiry->email),
            'url'   => route('site-enquiries.index'),
            'icon'  => 'ri-global-line',
        ];
    }
}
