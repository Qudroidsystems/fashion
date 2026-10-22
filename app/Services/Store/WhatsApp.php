<?php

namespace App\Services\Store;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopOrder;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp ordering.
 *
 *  - Customers can send their whole order (or one product) to the house's reps as a
 *    pre-filled WhatsApp message (wa.me link): items, sizes, prices, total, delivery and
 *    a link to the order. The order is saved on the shop first, so the rep sees it in
 *    Online orders and confirms it there.
 *  - Reps can send the customer a ready-made message with the order and how to pay.
 *  - Optionally, every new order is also pushed to the reps' numbers through the
 *    WhatsApp Cloud API (house's own Meta app token and phone number id).
 */
class WhatsApp
{
    /** International digits for a phone number (0803… → 234803… using the house's country code). */
    public static function normalize(?string $raw, ?Tenant $tenant = null): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($raw, '+') || str_starts_with($digits, '00')) {
            return ltrim(str_starts_with($digits, '00') ? substr($digits, 2) : $digits, '0');
        }
        if (str_starts_with($digits, '0')) {
            $code = config('store.calling_codes.' . ($tenant?->currency_code ?: 'NGN'), '234');

            return $code . substr($digits, 1);
        }

        return $digits;
    }

    /** The reps' WhatsApp number: the shop's, else the website's, else the house phone. */
    public static function houseNumber(Tenant $tenant): ?string
    {
        return self::normalize(StoreSettings::get($tenant, 'whatsapp.number') ?: $tenant->setting('website.whatsapp') ?: $tenant->phone, $tenant);
    }

    public static function link(?string $number, string $text): ?string
    {
        return $number ? 'https://wa.me/' . $number . '?text=' . rawurlencode($text) : null;
    }

    /** The message a customer sends with their order. */
    public static function orderMessage(ShopOrder $order, Tenant $tenant, ?string $orderUrl = null): string
    {
        $cur = $order->currency;
        $lines = ["Hello {$tenant->name}, I would like to place this order:", "*Order {$order->number}*", ''];
        foreach ($order->items as $i) {
            $lines[] = '• ' . $i->quantity . ' × ' . $i->name . ($i->variant_label ? ' (' . $i->variant_label . ')' : '') . ' — ' . Money::format($i->line_total, $cur);
        }
        $lines[] = '';
        $lines[] = 'Subtotal: ' . Money::format($order->subtotal, $cur);
        if ($order->discount_total > 0) {
            $lines[] = 'Discount' . ($order->coupon_code ? ' (' . $order->coupon_code . ')' : '') . ': -' . Money::format($order->discount_total, $cur);
        }
        if ($order->fulfilment === 'delivery') {
            $lines[] = 'Delivery' . ($order->shipping_label ? ' (' . $order->shipping_label . ')' : '') . ': ' . ($order->shipping_total > 0 ? Money::format($order->shipping_total, $cur) : 'Free');
        }
        $lines[] = '*Total: ' . Money::format($order->total, $cur) . '*';
        $lines[] = '';
        $lines[] = 'Name: ' . $order->name;
        $lines[] = 'Phone: ' . $order->phone;
        if ($order->fulfilment === 'pickup') {
            $lines[] = 'Pickup at: ' . ($order->pickupBranch?->name ?? 'your shop');
        } elseif ($order->address) {
            $lines[] = 'Deliver to: ' . $order->address;
        }
        if ($order->customer_note) {
            $lines[] = 'Note: ' . $order->customer_note;
        }
        if ($orderUrl) {
            $lines[] = '';
            $lines[] = 'Order details: ' . $orderUrl;
        }

        return implode("\n", $lines);
    }

    /** A customer asking about one product from its page. */
    public static function productMessage(Product $product, ?ProductVariant $variant, Tenant $tenant, ?string $url = null): string
    {
        $price = $variant ? Money::format($variant->price, $tenant->currency_code) : null;

        return trim("Hello {$tenant->name}, I would like to order:\n• " . $product->name
            . ($variant && $variant->label !== 'Standard' ? ' (' . $variant->label . ')' : '') . ($price ? ' — ' . $price : '')
            . ($url ? "\n" . $url : ''));
    }

    /** What a rep sends the customer: the order and how to pay. */
    public static function customerMessage(ShopOrder $order, Tenant $tenant, string $orderUrl): string
    {
        $cur = $order->currency;
        $lines = ["Hello {$order->first_name}, thank you for your order with {$tenant->name}.", "*Order {$order->number}*"];
        foreach ($order->items as $i) {
            $lines[] = '• ' . $i->quantity . ' × ' . $i->name . ($i->variant_label ? ' (' . $i->variant_label . ')' : '') . ' — ' . Money::format($i->line_total, $cur);
        }
        if ($order->shipping_total > 0) {
            $lines[] = 'Delivery: ' . Money::format($order->shipping_total, $cur);
        }
        if ($order->discount_total > 0) {
            $lines[] = 'Discount: -' . Money::format($order->discount_total, $cur);
        }
        $lines[] = '*Total: ' . Money::format($order->total, $cur) . '*';
        if ($order->balance() > 0.004) {
            $lines[] = '';
            $lines[] = 'Amount to pay: ' . Money::format($order->balance(), $cur);
            $bank = StoreSettings::get($tenant, 'bank');
            if (! empty($bank['account_number'])) {
                $lines[] = 'Bank transfer: ' . trim(($bank['bank_name'] ?? '') . ' ' . $bank['account_number'] . ' (' . ($bank['account_name'] ?? $tenant->name) . ')');
                $lines[] = 'Use ' . $order->number . ' as the reference.';
            }
            $lines[] = 'Pay online or send your transfer receipt here: ' . $orderUrl;
        } else {
            $lines[] = '';
            $lines[] = 'Paid in full — thank you! Track your order: ' . $orderUrl;
        }

        return implode("\n", $lines);
    }

    /**
     * Push a new order to the reps through the WhatsApp Cloud API (optional). Never throws:
     * a failed push is logged and the order is still in Online orders.
     */
    public static function notifyReps(ShopOrder $order, Tenant $tenant, string $orderUrl, string $adminUrl): bool
    {
        $cfg = StoreSettings::get($tenant, 'whatsapp');
        $token = StoreSettings::secret($tenant, 'whatsapp.cloud_token');
        $numbers = collect(preg_split('/[,\s;]+/', (string) ($cfg['notify_numbers'] ?? '')))->map(fn ($n) => self::normalize($n, $tenant))->filter()->unique();
        if (empty($cfg['cloud_enabled']) || ! $token || empty($cfg['cloud_phone_id']) || $numbers->isEmpty()) {
            return false;
        }
        $endpoint = 'https://graph.facebook.com/' . config('store.whatsapp_api_version', 'v21.0') . '/' . $cfg['cloud_phone_id'] . '/messages';
        $ok = false;
        foreach ($numbers as $to) {
            $body = ! empty($cfg['template'])
                ? ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => [
                    'name' => $cfg['template'], 'language' => ['code' => $cfg['template_lang'] ?: 'en'],
                    'components' => [['type' => 'body', 'parameters' => array_map(fn ($t) => ['type' => 'text', 'text' => $t], [
                        $order->number, $order->name, Money::format($order->total, $order->currency), $adminUrl,
                    ])]],
                ]]
                : ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['preview_url' => false,
                    'body' => "New online order {$order->number} (" . ShopOrder::PAYMENT_METHODS[$order->payment_method] . ")\n\n"
                        . self::orderMessage($order, $tenant, $orderUrl) . "\n\nOpen in admin: " . $adminUrl]];
            try {
                $res = Http::withToken($token)->acceptJson()->timeout(15)->post($endpoint, $body);
                $ok = $ok || $res->successful();
                if (! $res->successful()) {
                    Log::warning('WhatsApp Cloud API rejected an order alert', ['order' => $order->number, 'status' => $res->status(), 'error' => $res->json('error.message')]);
                }
            } catch (\Throwable $e) {
                Log::warning('WhatsApp Cloud API unreachable', ['order' => $order->number, 'error' => $e->getMessage()]);
            }
        }
        if ($ok) {
            $order->forceFill(['whatsapp_notified_at' => now()])->save();
        }

        return $ok;
    }
}
