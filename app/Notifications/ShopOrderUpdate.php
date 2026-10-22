<?php

namespace App\Notifications;

use App\Models\ShopOrder;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** E-mail to the shopper about their order (placed, confirmed, paid, on its way, ready, delivered, cancelled). */
class ShopOrderUpdate extends Notification
{
    public function __construct(public ShopOrder $order, public Tenant $house, public string $event, public string $orderUrl, public ?string $extra = null)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $o = $this->order;
        $cur = $o->currency;
        [$subject, $intro] = match ($this->event) {
            'placed'    => ["We received your order {$o->number}", 'Thank you for shopping with ' . $this->house->name . '. Here is what you ordered.'],
            'confirmed' => ["Your order {$o->number} is confirmed", 'Good news — your order is confirmed and being prepared.'],
            'paid'      => ["Payment received for {$o->number}", 'We have received ' . Money::format((float) $this->extra, $cur) . '. Thank you!'],
            'shipped'   => ["Your order {$o->number} is on its way", 'Your order has left us' . ($o->courier ? ' with ' . $o->courier : '') . ($o->tracking_number ? ' (tracking ' . $o->tracking_number . ')' : '') . '.'],
            'ready_for_pickup' => ["Your order {$o->number} is ready for pickup", 'Your order is ready at ' . ($o->pickupBranch?->name ?? 'our shop') . ($o->pickupBranch?->address ? ', ' . $o->pickupBranch->address : '') . '.'],
            'delivered' => ["Your order {$o->number} was delivered", 'Your order has been delivered. We hope you love it!'],
            'cancelled' => ["Your order {$o->number} was cancelled", 'Your order was cancelled' . ($this->extra ? ': ' . $this->extra : '.')],
            'proof_rejected' => ["We could not confirm your payment for {$o->number}", 'We could not match your transfer: ' . $this->extra . ' Please check and send the receipt again.'],
            default     => ["Update on your order {$o->number}", $this->extra ?: 'There is an update on your order.'],
        };
        $m = (new MailMessage)->subject($this->house->name . ': ' . $subject)->greeting('Hello ' . $o->first_name . ',')->line($intro);
        if (in_array($this->event, ['placed', 'confirmed'], true)) {
            foreach ($o->items as $i) {
                $m->line('• ' . $i->quantity . ' × ' . $i->name . ($i->variant_label ? ' (' . $i->variant_label . ')' : '') . ' — ' . Money::format($i->line_total, $cur));
            }
            $m->line('Total: ' . Money::format($o->total, $cur));
        }
        if ($o->balance() > 0.004 && ! in_array($this->event, ['cancelled', 'delivered'], true)) {
            $m->line('Still to pay: ' . Money::format($o->balance(), $cur) . ' — you can pay or send your transfer receipt on your order page.');
        }

        return $m->action('View your order', $this->orderUrl)->salutation('— ' . $this->house->name);
    }
}
