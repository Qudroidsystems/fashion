<?php

namespace App\Notifications;

use App\Models\ShopOrder;
use App\Notifications\Channels\TenantDatabaseChannel;
use App\Support\Money;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Staff alert about an online order: a new order, a payment receipt to check, or a payment.
 * In-app for staff who can see online orders; e-mail for the extra addresses in the shop settings.
 */
class NewOnlineOrder extends Notification
{
    public ?int $tenantId;

    public function __construct(public ShopOrder $order, public string $kind = 'placed', public ?string $extra = null)
    {
        $this->tenantId = $order->tenant_id;
    }

    public function via($notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : [TenantDatabaseChannel::class];
    }

    protected function title(): string
    {
        $o = $this->order;

        return match ($this->kind) {
            'proof'   => "Payment receipt to check: {$o->number}",
            'paid'    => "Paid online: {$o->number}",
            'cancelled' => "Cancelled by the customer: {$o->number}",
            default   => ($o->payment_method === 'whatsapp' ? 'WhatsApp order ' : 'Online order ') . "{$o->number} from {$o->name}",
        };
    }

    protected function body(): string
    {
        $o = $this->order;

        return $this->extra ?: $o->itemCount() . ' item(s) · ' . Money::format($o->total, $o->currency) . ' · ' . $o->payment_method_label;
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => $this->title(),
            'body'  => $this->body(),
            'url'   => route('store.orders.show', $this->order->public_id),
            'icon'  => $this->kind === 'proof' ? 'ri-bank-line' : 'ri-shopping-bag-3-line',
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $o = $this->order;
        $m = (new MailMessage)->subject($this->title())->line($this->body());
        foreach ($o->items as $i) {
            $m->line('• ' . $i->quantity . ' × ' . $i->name . ($i->variant_label ? ' (' . $i->variant_label . ')' : '') . ' — ' . Money::format($i->line_total, $o->currency));
        }

        return $m->line('Customer: ' . $o->name . ' · ' . $o->phone . ($o->email ? ' · ' . $o->email : ''))
            ->action('Open the order', route('store.orders.show', $o->public_id));
    }
}
