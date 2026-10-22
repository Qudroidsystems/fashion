<?php

namespace App\Services\Store;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopOrder;
use App\Models\ShopOrderEvent;
use App\Models\StockMovement;
use App\Models\StoreAccount;
use App\Models\StoreCart;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Inventory\StockLedger;
use App\Services\Numbering\DocumentNumber;
use App\Services\Orders\BillingService;
use App\Services\Orders\OrderService;
use App\Support\Money;
use App\Support\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Online orders from checkout to doorstep.
 *
 *  1. place: a shopper checks out (or a rep takes a WhatsApp order). The lines are frozen,
 *     stock is reserved at the fulfilment branch and held until the order is paid/accepted
 *     or the hold runs out.
 *  2. accept: a card payment succeeds, or staff confirm a transfer / WhatsApp / pay-on-delivery
 *     order. The shopper becomes (or is matched to) a customer, a normal confirmed order is
 *     created with the same lines plus delivery, and the stock is posted to the ledger as sold.
 *  3. recordPayment: money goes through the normal payments (receipt numbers, allocations).
 *  4. advance: being prepared → ready for pickup / on its way → delivered (a handover record).
 *  5. cancel: reservations released, stock put back, the order cancelled; money already paid
 *     stays as the customer's credit to refund from Payments.
 */
class ShopOrderService
{
    public function __construct(
        protected StoreSettings $settings,
        protected StoreCatalog $catalog,
        protected CartService $carts,
        protected ShippingService $shipping,
        protected StockLedger $ledger,
        protected OrderService $orders,
        protected BillingService $billing,
        protected StoreNotifier $notify,
    ) {
    }

    /* -------------------------------------------------------------- placing */

    /**
     * Checkout from the shopper's cart.
     * $data: first_name, last_name, email, phone, fulfilment, address_line1, address_line2, city, state, country,
     *        postal_code, pickup_branch_id, payment_method, customer_note.
     */
    public function checkout(Tenant $tenant, StoreCart $cart, array $data, ?StoreAccount $account, ?Request $request = null): ShopOrder
    {
        $methods = $this->settings->methods($tenant);
        if (! isset($methods[$data['payment_method'] ?? ''])) {
            throw ValidationException::withMessages(['payment_method' => 'Choose how you would like to pay.']);
        }
        $fulfilment = ($data['fulfilment'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery';
        $sum = $this->carts->summary($tenant, $cart, [
            'fulfilment' => $fulfilment, 'state' => $data['state'] ?? null, 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null,
        ]);
        if (! $sum['lines']) {
            throw ValidationException::withMessages(['cart' => 'Your basket is empty.']);
        }
        if ($sum['problems']) {
            throw ValidationException::withMessages(['cart' => 'Some items in your basket have changed (sold out or fewer left). Please check your basket.']);
        }
        if ($cart->coupon_code && $sum['coupon_error']) {
            throw ValidationException::withMessages(['coupon' => $sum['coupon_error']]);
        }
        $this->assertFulfilment($tenant, $fulfilment, $data, $sum['delivery']);

        $lines = collect($sum['lines'])->map(fn ($l) => [
            'variant_id' => $l['variant_id'], 'product_id' => $l['product_id'], 'name' => $l['name'], 'label' => $l['label'], 'sku' => $l['sku'],
            'image_path' => $l['image_path'], 'unit' => $l['unit'], 'compare' => $l['compare'], 'qty' => $l['qty'],
        ])->all();

        return $this->create($tenant, $lines, $data + ['fulfilment' => $fulfilment], [
            'subtotal' => $sum['subtotal'], 'discount' => $sum['discount'], 'shipping' => $sum['shipping'], 'total' => $sum['total'],
            'shipping_label' => $sum['shipping_label'], 'zone_id' => $sum['delivery']['zone_id'] ?? null,
        ], $sum['coupon'], 'web', $account, null, $request, $cart);
    }

    /**
     * A rep takes an order (WhatsApp, phone, Instagram DM…). Prices come from the catalogue; a lower
     * price or a discount above the house limit needs "Override price".
     * $rows: [['variant_id' => , 'quantity' => , 'unit_price' => ?]], $data as checkout plus discount, shipping_fee, confirm.
     */
    public function takeOrder(Tenant $tenant, array $rows, array $data, User $by): ShopOrder
    {
        $variants = ProductVariant::with('product.images')->whereIn('id', collect($rows)->pluck('variant_id')->filter())->get()->keyBy('id');
        $lines = [];
        $sub = 0;
        foreach ($rows as $r) {
            $v = $variants->get((int) ($r['variant_id'] ?? 0));
            $qty = (int) ($r['quantity'] ?? 0);
            if (! $v || $qty <= 0) {
                continue;
            }
            $list = (int) round((float) $v->price * 100);
            $unit = isset($r['unit_price']) && $r['unit_price'] !== '' && $r['unit_price'] !== null ? (int) round((float) $r['unit_price'] * 100) : $list;
            if ($unit < $list && ! $by->can('Override price')) {
                throw ValidationException::withMessages(['items' => "“{$v->product->name}” is below the shop price. Only staff allowed to override prices can do that."]);
            }
            $this->carts->assertStock($tenant, $v, $qty + collect($lines)->where('variant_id', $v->id)->sum('qty'));
            $lines[] = ['variant_id' => $v->id, 'product_id' => $v->product_id, 'name' => $v->product->name, 'label' => $v->label !== 'Standard' ? $v->label : null,
                'sku' => $v->sku, 'image_path' => $v->product->images->first()?->path ?? $v->product->image_path, 'unit' => $unit / 100,
                'compare' => $v->isOnSale() ? (float) $v->compare_at_price : null, 'qty' => $qty];
            $sub += $unit * $qty;
        }
        if (! $lines) {
            throw ValidationException::withMessages(['items' => 'Add at least one item.']);
        }
        $discount = min($sub, max(0, (int) round((float) ($data['discount'] ?? 0) * 100)));
        $limit = (float) ($tenant->setting('discount_limit_percent', 10) ?? 10);
        if ($sub > 0 && $discount / $sub * 100 > $limit + 0.0001 && ! $by->can('Override price')) {
            throw ValidationException::withMessages(['discount' => sprintf('A discount above %s%% needs someone allowed to override prices.', rtrim(rtrim(number_format($limit, 2), '0'), '.'))]);
        }
        $fulfilment = ($data['fulfilment'] ?? 'pickup') === 'delivery' ? 'delivery' : 'pickup';
        $shipping = 0;
        $label = null;
        $zoneId = null;
        if ($fulfilment === 'delivery') {
            $quote = $this->shipping->delivery($tenant, $data['state'] ?? null, ($sub - $discount) / 100);
            $shipping = isset($data['shipping_fee']) && $data['shipping_fee'] !== '' && $data['shipping_fee'] !== null
                ? (int) round((float) $data['shipping_fee'] * 100) : (int) round(($quote['fee'] ?? 0) * 100);
            $label = $quote['label'] ?? 'Delivery';
            $zoneId = $quote['zone_id'] ?? null;
        }
        $this->assertFulfilment($tenant, $fulfilment, $data, ['zone_id' => $zoneId], true);

        $order = $this->create($tenant, $lines, $data + ['fulfilment' => $fulfilment], [
            'subtotal' => $sub / 100, 'discount' => $discount / 100, 'shipping' => max(0, $shipping) / 100,
            'total' => max(0, $sub - $discount + $shipping) / 100, 'shipping_label' => $label, 'zone_id' => $zoneId,
        ], null, $data['source'] ?? 'whatsapp', null, $by);

        return ! empty($data['confirm']) ? $this->accept($order, $by, 'Confirmed by ' . $by->name . ' when taking the order') : $order;
    }

    protected function assertFulfilment(Tenant $tenant, string $fulfilment, array $data, ?array $delivery, bool $staff = false): void
    {
        if ($fulfilment === 'pickup') {
            $branches = $this->settings->pickupBranches($tenant);
            if ($staff && $branches->isEmpty()) {
                $branches = \App\Models\Branch::active()->get();
            }
            if (! $branches->contains('id', (int) ($data['pickup_branch_id'] ?? 0))) {
                throw ValidationException::withMessages(['pickup_branch_id' => 'Choose where to collect your order.']);
            }

            return;
        }
        if (! $staff && ! $delivery) {
            throw ValidationException::withMessages(['state' => 'Sorry, we do not deliver to ' . ($data['state'] ?: 'that address') . ' yet. Choose pickup or another address.']);
        }
        foreach (['address_line1' => 'Enter the delivery address.', 'city' => 'Enter the town or city.'] as $f => $msg) {
            if (! trim((string) ($data[$f] ?? ''))) {
                throw ValidationException::withMessages([$f => $msg]);
            }
        }
    }

    /** @param array $lines [variant_id, product_id, name, label, sku, image_path, unit, compare, qty] (money in major units) */
    protected function create(Tenant $tenant, array $lines, array $data, array $totals, ?Coupon $coupon, string $source, ?StoreAccount $account, ?User $by, ?Request $request = null, ?StoreCart $cart = null): ShopOrder
    {
        $method = $data['payment_method'];
        $card = in_array($method, ['paystack', 'stripe'], true);
        $branch = $this->settings->fulfilmentBranchId($tenant);
        $backorder = (bool) StoreSettings::get($tenant, 'allow_backorder');

        $order = DB::transaction(function () use ($tenant, $lines, $data, $totals, $coupon, $source, $account, $by, $request, $cart, $method, $card, $branch, $backorder) {
            if ($coupon) {
                $coupon = Coupon::lockForUpdate()->find($coupon->id);
                if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
                    throw ValidationException::withMessages(['coupon' => 'That code has just been used up.']);
                }
            }
            $order = ShopOrder::create([
                'number' => DocumentNumber::next('shop_order'), 'token' => Str::random(48), 'source' => $source,
                'account_id' => $account?->id, 'customer_id' => $account?->customer_id,
                'status' => $card ? 'pending_payment' : 'awaiting_confirmation', 'payment_method' => $method, 'payment_status' => 'unpaid',
                'fulfilment' => $data['fulfilment'],
                'first_name' => Str::limit(trim($data['first_name']), 80, ''), 'last_name' => isset($data['last_name']) ? Str::limit(trim($data['last_name']), 80, '') : null,
                'email' => ! empty($data['email']) ? mb_strtolower(trim($data['email'])) : null, 'phone' => trim($data['phone']),
                'address_line1' => $data['fulfilment'] === 'delivery' ? ($data['address_line1'] ?? null) : null,
                'address_line2' => $data['fulfilment'] === 'delivery' ? ($data['address_line2'] ?? null) : null,
                'city' => $data['city'] ?? null, 'state' => $data['state'] ?? null, 'country' => $data['country'] ?? null, 'postal_code' => $data['postal_code'] ?? null,
                'pickup_branch_id' => $data['fulfilment'] === 'pickup' ? (int) $data['pickup_branch_id'] : null,
                'shipping_zone_id' => $totals['zone_id'] ?? null, 'shipping_label' => $data['fulfilment'] === 'delivery' ? ($totals['shipping_label'] ?? 'Delivery') : 'Pickup',
                'subtotal' => $totals['subtotal'], 'discount_total' => $totals['discount'], 'shipping_total' => $totals['shipping'], 'total' => $totals['total'],
                'currency' => $tenant->currency_code ?: 'NGN', 'coupon_code' => $coupon?->code, 'coupon_id' => $coupon?->id,
                'customer_note' => $data['customer_note'] ?? null,
                'expires_at' => $card ? now()->addHours($this->settings->cardHoldHours($tenant)) : now()->addDays($this->settings->manualHoldDays($tenant)),
                'created_by' => $by?->id, 'handled_by' => $by?->id,
                'ip' => $request?->ip(), 'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            ]);

            $items = InventoryItem::whereIn('product_variant_id', collect($lines)->pluck('variant_id'))->where('is_active', true)->get()->keyBy('product_variant_id');
            $tracked = Product::whereIn('id', collect($lines)->pluck('product_id'))->pluck('track_stock', 'id');
            foreach ($lines as $l) {
                $reservation = null;
                $item = $items->get($l['variant_id']);
                if ($item && ($tracked[$l['product_id']] ?? false)) {
                    $reservation = $this->ledger->reserve($item, $branch, $l['qty'], [
                        'reference' => $order->number . ' — online order', 'allow_short' => $backorder,
                        'needed_by' => now()->addDays(3)->toDateString(),
                    ]);
                }
                $order->items()->create([
                    'product_id' => $l['product_id'], 'product_variant_id' => $l['variant_id'], 'name' => $l['name'], 'variant_label' => $l['label'],
                    'sku' => $l['sku'], 'image_path' => $l['image_path'], 'unit_price' => $l['unit'], 'compare_at_price' => $l['compare'],
                    'quantity' => $l['qty'], 'line_total' => round($l['unit'] * $l['qty'], 2), 'reservation_id' => $reservation?->id,
                ]);
            }

            if ($coupon) {
                CouponRedemption::create(['coupon_id' => $coupon->id, 'shop_order_id' => $order->id, 'email' => $order->email,
                    'phone_normalized' => Customer::normalizePhone($order->phone), 'amount' => $order->discount_total]);
                $coupon->increment('used_count');
            }
            $this->event($order, 'status', $order->status, $source === 'web' ? 'Order placed on the shop' : 'Order taken by ' . ($by?->name ?? 'staff') . ' (' . $source . ')', true, $by);
            if ($cart) {
                $cart->items()->delete();
                $cart->update(['coupon_code' => null]);
            }
            AuditLogger::log('shop_order.placed', $order, after: ['number' => $order->number, 'total' => $order->total, 'method' => $method, 'source' => $source]);

            return $order;
        });

        DB::afterCommit(function () use ($tenant, $order) {
            $order->load('items', 'pickupBranch');
            $this->notify->staff($tenant, $order, 'placed');
            if (! in_array($order->payment_method, ['paystack', 'stripe'], true)) {
                $this->notify->customer($tenant, $order, 'placed');
            }
        });

        return $order->fresh('items');
    }

    /* ------------------------------------------------------------ accepting */

    /** Turn the shop order into a confirmed order for a (matched or new) customer; stock leaves the shelf. */
    public function accept(ShopOrder $shop, ?User $by = null, ?string $note = null): ShopOrder
    {
        if ($shop->order_id) {
            return $shop;
        }
        if ($shop->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'This order was cancelled. Reopen it by taking a new order.']);
        }
        $tenant = CurrentTenant::require();
        $branch = $this->settings->fulfilmentBranchId($tenant);
        $backorder = (bool) StoreSettings::get($tenant, 'allow_backorder');

        $shop = DB::transaction(function () use ($shop, $by, $note, $tenant, $branch, $backorder) {
            $shop = ShopOrder::lockForUpdate()->with('items.reservation')->findOrFail($shop->id);
            if ($shop->order_id) {
                return $shop;
            }
            $customer = $this->customerFor($shop);

            $order = Order::create([
                'number' => DocumentNumber::next('order'), 'customer_id' => $customer->id, 'branch_id' => $shop->pickup_branch_id ?: $branch,
                'status' => 'draft', 'title' => 'Online order ' . $shop->number,
                'customer_notes' => $shop->customer_note,
                'internal_notes' => trim('From the online shop (' . $shop->payment_method_label . ', ' . ($shop->fulfilment === 'pickup' ? 'pickup at ' . ($shop->pickupBranch?->name ?? 'branch') : 'delivery to ' . $shop->address) . ').'),
                'owner_id' => $by?->id, 'created_by' => $by?->id,
                'subtotal' => round($shop->subtotal + $shop->shipping_total, 2), 'discount_total' => $shop->discount_total,
                'order_discount' => $shop->discount_total, 'total' => $shop->total, 'deposit_required' => $shop->total,
            ]);
            $i = 0;
            foreach ($shop->items as $it) {
                $line = $order->lines()->create([
                    'kind' => 'ready_made', 'product_variant_id' => $it->product_variant_id,
                    'description' => Str::limit($it->name . ($it->variant_label ? ' — ' . $it->variant_label : ''), 250, ''),
                    'quantity' => $it->quantity, 'unit_price' => $it->unit_price, 'list_price' => $it->unit_price, 'discount' => 0,
                    'line_total' => $it->line_total, 'sort_order' => $i++, 'status' => 'pending',
                ]);
                $it->update(['order_line_id' => $line->id]);
            }
            if ($shop->shipping_total > 0) {
                $order->lines()->create(['kind' => 'charge', 'description' => 'Delivery' . ($shop->shipping_label && $shop->shipping_label !== 'Delivery' ? ' — ' . $shop->shipping_label : ''),
                    'quantity' => 1, 'unit_price' => $shop->shipping_total, 'list_price' => $shop->shipping_total, 'discount' => 0,
                    'line_total' => $shop->shipping_total, 'sort_order' => $i, 'status' => 'pending']);
            }
            $this->orders->transition($order, 'confirmed', 'Online order ' . $shop->number . ($note ? ' — ' . $note : ''));

            // The goods are now the customer's: use up the hold and post the sale to the stock ledger.
            $items = InventoryItem::whereIn('product_variant_id', $shop->items->pluck('product_variant_id')->filter())->get()->keyBy('product_variant_id');
            $tracked = Product::whereIn('id', $shop->items->pluck('product_id')->filter())->pluck('track_stock', 'id');
            foreach ($shop->items as $it) {
                $item = $items->get($it->product_variant_id);
                if (! $item || ! ($tracked[$it->product_id] ?? false)) {
                    continue;
                }
                if ($it->reservation && $it->reservation->status === 'active') {
                    $this->ledger->consume($it->reservation, $it->quantity);
                }
                $this->ledger->post($item, $branch, -$it->quantity, 'sale', [
                    'source_type' => 'shop_order', 'source_id' => $shop->id, 'reference' => $shop->number,
                    'customer_id' => $customer->id, 'order_id' => $order->id, 'allow_negative' => $backorder ?: null,
                    'reason' => 'Online order',
                ]);
            }
            foreach ($shop->items->groupBy('product_id') as $productId => $rows) {
                $productId && Product::whereKey($productId)->increment('sold_count', (int) $rows->sum('quantity'));
            }

            $shop->forceFill(['order_id' => $order->id, 'customer_id' => $customer->id, 'status' => 'confirmed', 'confirmed_at' => now(),
                'expires_at' => null, 'handled_by' => $shop->handled_by ?? $by?->id])->save();
            if ($shop->account_id) {
                StoreAccount::whereKey($shop->account_id)->whereNull('customer_id')->update(['customer_id' => $customer->id]);
            }
            $this->event($shop, 'status', 'confirmed', $note ?: 'Order confirmed', true, $by);
            AuditLogger::log('shop_order.accepted', $shop, after: ['order' => $order->number, 'customer' => $customer->customer_number]);

            return $shop;
        });

        DB::afterCommit(fn () => $this->notify->customer($tenant, $shop->fresh(['items', 'pickupBranch']), 'confirmed'));

        return $shop->fresh(['items', 'order']);
    }

    /** The shopper's customer record: their account's, else matched by phone then e-mail, else a new one. */
    protected function customerFor(ShopOrder $shop): Customer
    {
        if ($shop->customer_id && ($c = Customer::find($shop->customer_id))) {
            return $c;
        }
        if ($shop->account_id && ($id = StoreAccount::whereKey($shop->account_id)->value('customer_id')) && ($c = Customer::find($id))) {
            return $c;
        }
        $phone = Customer::normalizePhone($shop->phone);
        $c = ($phone ? Customer::active()->where('phone_normalized', $phone)->first() : null)
            ?? ($shop->email ? Customer::active()->where('email', $shop->email)->first() : null);
        if ($c) {
            return $c;
        }
        $c = Customer::create([
            'first_name' => $shop->first_name, 'last_name' => $shop->last_name, 'phone' => $shop->phone, 'email' => $shop->email,
            'whatsapp' => $shop->payment_method === 'whatsapp' || $shop->source === 'whatsapp' ? $shop->phone : null,
            'address' => $shop->fulfilment === 'delivery' ? trim($shop->address_line1 . ' ' . $shop->address_line2) : null,
            'city' => $shop->city, 'state' => $shop->state,
            'source' => $shop->source === 'whatsapp' || $shop->payment_method === 'whatsapp' ? 'whatsapp' : 'website',
        ]);
        AuditLogger::log('customer.created', $c, description: 'From online order ' . $shop->number);

        return $c;
    }

    /* ------------------------------------------------------------- payments */

    /**
     * Money received for an online order (accepts the order first if needed).
     * $method: bank_transfer | card | cash | mobile_money | other.
     */
    public function recordPayment(ShopOrder $shop, float $amount, string $method, ?string $reference, ?User $by = null, ?string $notes = null, string $channel = 'online', ?Carbon $receivedAt = null, bool $notifyCustomer = true): Payment
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount received.']);
        }
        $tenant = CurrentTenant::require();

        [$shop, $payment] = DB::transaction(function () use ($shop, $amount, $method, $reference, $by, $notes, $channel, $receivedAt) {
            $shop = $this->accept($shop, $by, 'Payment received');
            $shop = ShopOrder::lockForUpdate()->findOrFail($shop->id);
            $order = Order::findOrFail($shop->order_id);
            $payment = $this->billing->recordPayment([
                'customer_id' => $order->customer_id, 'amount' => $amount, 'method' => $method, 'reference' => $reference ?: $shop->number,
                'order_id' => $order->id, 'branch_id' => $order->branch_id, 'channel' => $channel, 'confirm_duplicate' => true,
                'received_at' => $receivedAt ?? now(), 'notes' => $notes ?: 'Online order ' . $shop->number,
            ]);
            $this->syncPaid($shop, $order);
            $this->event($shop, 'payment', $shop->payment_status, Money::format($amount, $shop->currency) . ' received (' . str_replace('_', ' ', $method) . ($reference ? ', ref ' . $reference : '') . ')', true, $by);

            return [$shop, $payment];
        });

        if ($notifyCustomer) {
            DB::afterCommit(fn () => $this->notify->customer($tenant, $shop->fresh('items'), 'paid', (string) $amount));
        }

        return $payment;
    }

    /** amount_paid / payment_status from the order's real allocations (also counts money taken at the till). */
    public function syncPaid(ShopOrder $shop, ?Order $order = null): ShopOrder
    {
        $order ??= $shop->order_id ? Order::find($shop->order_id) : null;
        if (! $order) {
            return $shop;
        }
        $paid = round(min($shop->total, max(0, $order->paid())), 2);
        $status = $paid >= $shop->total - 0.004 ? 'paid' : ($paid > 0 ? 'partially_paid' : ($shop->payment_proof_path ? 'pending' : 'unpaid'));
        $shop->forceFill(['amount_paid' => $paid, 'payment_status' => $status, 'paid_at' => $status === 'paid' ? ($shop->paid_at ?? now()) : $shop->paid_at])->save();

        return $shop;
    }

    /** The shopper sends a transfer receipt (photo or PDF) and/or the reference. */
    public function uploadProof(ShopOrder $shop, ?UploadedFile $file, ?string $reference): ShopOrder
    {
        if (! $shop->isOpen() || $shop->balance() <= 0.004) {
            throw ValidationException::withMessages(['proof' => 'This order does not need a payment.']);
        }
        if (! $file && ! trim((string) $reference)) {
            throw ValidationException::withMessages(['proof' => 'Attach the receipt or type the transfer reference.']);
        }
        $tenant = CurrentTenant::require();
        $path = $file?->storeAs('tenants/' . $tenant->public_id . '/shop-proofs', $shop->public_id . '-' . Str::lower(Str::random(8)) . '.' . $file->extension(), 'public');
        $shop->forceFill([
            'payment_proof_path' => $path ?? $shop->payment_proof_path, 'payment_reference' => $reference ?: $shop->payment_reference,
            'proof_uploaded_at' => now(), 'payment_status' => in_array($shop->payment_status, ['unpaid', 'pending'], true) ? 'pending' : $shop->payment_status,
            'payment_method' => $shop->payment_method === 'whatsapp' || $shop->payment_method === 'pay_on_delivery' ? $shop->payment_method : 'bank_transfer',
        ])->save();
        $this->event($shop, 'payment', 'pending', 'Transfer receipt sent' . ($reference ? ' (ref ' . $reference . ')' : ''), true);
        $this->notify->staff($tenant, $shop->load('items'), 'proof', 'Transfer receipt for ' . Money::format($shop->balance(), $shop->currency) . ($reference ? ' · ref ' . $reference : ''));

        return $shop;
    }

    public function rejectProof(ShopOrder $shop, string $reason, User $by): ShopOrder
    {
        $shop->forceFill(['payment_status' => $shop->amount_paid > 0 ? 'partially_paid' : 'unpaid', 'proof_uploaded_at' => null])->save();
        $this->event($shop, 'payment', $shop->payment_status, 'Transfer receipt not accepted: ' . $reason, true, $by);
        $this->notify->customer(CurrentTenant::require(), $shop, 'proof_rejected', $reason);

        return $shop;
    }

    /* ---------------------------------------------------------- fulfilment */

    public const FLOW = [
        'confirmed'        => ['processing', 'ready_for_pickup', 'shipped'],
        'processing'       => ['ready_for_pickup', 'shipped'],
        'ready_for_pickup' => ['delivered'],
        'shipped'          => ['delivered'],
    ];

    /** Move along: being prepared → ready for pickup / on its way → delivered. $data: courier, tracking_number, note, recipient_name, outstanding. */
    public function advance(ShopOrder $shop, string $to, array $data, User $by): ShopOrder
    {
        if ($shop->isAwaiting()) {
            throw ValidationException::withMessages(['status' => 'Confirm the order first.']);
        }
        if (! in_array($to, self::FLOW[$shop->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "An order cannot go from “{$shop->status_label}” to “" . (ShopOrder::STATUSES[$to][0] ?? $to) . '”.']);
        }
        if ($to === 'ready_for_pickup' && $shop->fulfilment !== 'pickup') {
            $to = 'shipped';
        }
        if ($to === 'shipped' && $shop->fulfilment === 'pickup') {
            $to = 'ready_for_pickup';
        }
        $tenant = CurrentTenant::require();

        $shop = DB::transaction(function () use ($shop, $to, $data, $by) {
            $order = Order::findOrFail($shop->order_id);
            $production = app(\App\Services\Production\ProductionService::class);
            if (in_array($to, ['ready_for_pickup', 'shipped', 'delivered'], true) && in_array($order->status, ['confirmed', 'in_production', 'fitting'], true)) {
                $production->moveOrder($order, 'ready', 'Online order ' . $shop->number . ' packed');
            }
            if ($to === 'delivered' && ! in_array($order->fresh()->status, ['delivered', 'closed'], true)) {
                $order = $order->fresh();
                $lineIds = $order->lines()->whereNotIn('status', ['delivered', 'cancelled'])->pluck('id')->all();
                if ($lineIds) {
                    app(\App\Services\Production\DeliveryService::class)->handOver($order, [
                        'line_ids' => $lineIds, 'method' => $shop->fulfilment === 'pickup' ? 'collection' : 'delivery',
                        'recipient_name' => $data['recipient_name'] ?? $shop->name, 'recipient_phone' => $shop->phone,
                        'confirmation' => 'none', 'address' => $shop->fulfilment === 'delivery' ? $shop->address : null,
                        'outstanding_conditions' => $data['outstanding'] ?? null,
                    ], $by);
                }
            }
            $stamp = ['shipped' => 'shipped_at', 'ready_for_pickup' => 'shipped_at', 'delivered' => 'delivered_at'][$to] ?? null;
            $shop->forceFill(array_filter([
                'status' => $to, 'courier' => $data['courier'] ?? $shop->courier, 'tracking_number' => $data['tracking_number'] ?? $shop->tracking_number,
            ], fn ($v) => $v !== null) + ($stamp ? [$stamp => now()] : []))->save();
            $this->event($shop, 'status', $to, $data['note'] ?? null, true, $by);
            AuditLogger::log('shop_order.status', $shop, after: ['status' => $to]);

            return $shop;
        });
        $this->notify->customer($tenant, $shop->load('items', 'pickupBranch'), $to === 'processing' ? 'update' : $to,
            $to === 'processing' ? 'Your order is being prepared.' : null);

        return $shop;
    }

    /* ---------------------------------------------------------- cancelling */

    public function cancel(ShopOrder $shop, string $reason, ?User $by = null, bool $byCustomer = false): ShopOrder
    {
        if (! $shop->isOpen()) {
            throw ValidationException::withMessages(['status' => 'This order can no longer be cancelled.']);
        }
        if ($byCustomer && (! $shop->isAwaiting() || $shop->amount_paid > 0)) {
            throw ValidationException::withMessages(['status' => 'Please contact the house to cancel this order.']);
        }
        $tenant = CurrentTenant::require();

        $shop = DB::transaction(function () use ($shop, $reason, $by) {
            $shop = ShopOrder::lockForUpdate()->with('items.reservation')->findOrFail($shop->id);
            foreach ($shop->items as $it) {
                if ($it->reservation && $it->reservation->status === 'active') {
                    $this->ledger->release($it->reservation);
                }
            }
            if ($shop->order_id && ($order = Order::find($shop->order_id)) && in_array($order->status, \App\Support\Orders\OrderStatus::CANCELLABLE, true)) {
                $this->orders->cancel($order, 'Online order ' . $shop->number . ' cancelled: ' . $reason);
            }
            // Put back what was posted as sold.
            foreach (StockMovement::where('source_type', 'shop_order')->where('source_id', $shop->id)->where('type', 'sale')->get() as $m) {
                $this->ledger->post($m->item, $m->branch_id, abs((float) $m->quantity), 'sale_return', [
                    'source_type' => 'shop_order', 'source_id' => $shop->id, 'reference' => $shop->number, 'unit_cost' => $m->unit_cost,
                    'customer_id' => $m->customer_id, 'order_id' => $m->order_id, 'reason' => 'Online order cancelled',
                ]);
                if ($pid = $m->item?->productVariant?->product_id) {
                    Product::whereKey($pid)->where('sold_count', '>=', abs((int) $m->quantity))->decrement('sold_count', abs((int) $m->quantity));
                }
            }
            if ($shop->coupon_id && ! $shop->order_id) {
                CouponRedemption::where('shop_order_id', $shop->id)->delete();
                Coupon::whereKey($shop->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
            }
            $shop->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => Str::limit($reason, 250, ''), 'expires_at' => null])->save();
            $this->event($shop, 'status', 'cancelled', $reason, true, $by);
            if ($shop->amount_paid > 0) {
                $this->event($shop, 'note', null, Money::format($shop->amount_paid, $shop->currency) . ' paid stays on the customer’s account as credit — refund it from Payments if needed.', false, $by);
            }
            AuditLogger::log('shop_order.cancelled', $shop, after: ['reason' => $reason, 'paid' => $shop->amount_paid]);

            return $shop;
        });
        $this->notify->customer($tenant, $shop, 'cancelled', $reason);
        if ($byCustomer) {
            $this->notify->staff($tenant, $shop->load('items'), 'cancelled', $reason);
        }

        return $shop;
    }

    /**
     * A rep changes a waiting order (quantities, items, delivery fee, discount) before confirming it.
     * $rows: [['variant_id' => , 'quantity' => , 'unit_price' => ?]]
     */
    public function revise(ShopOrder $shop, array $rows, ?float $shippingFee, ?float $discount, User $by): ShopOrder
    {
        if (! $shop->isAwaiting()) {
            throw ValidationException::withMessages(['status' => 'Only orders waiting to be confirmed can be changed.']);
        }
        $tenant = CurrentTenant::require();

        return DB::transaction(function () use ($shop, $rows, $shippingFee, $discount, $by, $tenant) {
            $shop = ShopOrder::lockForUpdate()->with('items.reservation')->findOrFail($shop->id);
            foreach ($shop->items as $it) {
                if ($it->reservation && $it->reservation->status === 'active') {
                    $this->ledger->release($it->reservation);
                }
            }
            $before = $shop->items->map(fn ($i) => $i->quantity . ' × ' . $i->name . ($i->variant_label ? ' (' . $i->variant_label . ')' : ''))->implode(', ');
            $prices = $shop->items->pluck('unit_price', 'product_variant_id');
            $shop->items()->delete();

            $variants = ProductVariant::with('product.images')->whereIn('id', collect($rows)->pluck('variant_id'))->get()->keyBy('id');
            $items = InventoryItem::whereIn('product_variant_id', $variants->keys())->where('is_active', true)->get()->keyBy('product_variant_id');
            $branch = $this->settings->fulfilmentBranchId($tenant);
            $backorder = (bool) StoreSettings::get($tenant, 'allow_backorder');
            $sub = 0;
            foreach ($rows as $r) {
                $v = $variants->get((int) ($r['variant_id'] ?? 0));
                $qty = (int) ($r['quantity'] ?? 0);
                if (! $v || $qty <= 0) {
                    continue;
                }
                $list = (int) round((float) ($prices[$v->id] ?? $v->price) * 100);
                $unit = isset($r['unit_price']) && $r['unit_price'] !== '' && $r['unit_price'] !== null ? (int) round((float) $r['unit_price'] * 100) : $list;
                if ($unit < min($list, (int) round((float) $v->price * 100)) && ! $by->can('Override price')) {
                    throw ValidationException::withMessages(['items' => "“{$v->product->name}” is below the shop price. Only staff allowed to override prices can do that."]);
                }
                $this->carts->assertStock($tenant, $v, $qty);
                $item = $items->get($v->id);
                $reservation = $item && $v->product->track_stock
                    ? $this->ledger->reserve($item, $branch, $qty, ['reference' => $shop->number . ' — online order', 'allow_short' => $backorder]) : null;
                $shop->items()->create([
                    'product_id' => $v->product_id, 'product_variant_id' => $v->id, 'name' => $v->product->name, 'variant_label' => $v->label !== 'Standard' ? $v->label : null,
                    'sku' => $v->sku, 'image_path' => $v->product->images->first()?->path ?? $v->product->image_path, 'unit_price' => $unit / 100,
                    'compare_at_price' => $v->isOnSale() ? $v->compare_at_price : null, 'quantity' => $qty, 'line_total' => $unit * $qty / 100,
                    'reservation_id' => $reservation?->id,
                ]);
                $sub += $unit * $qty;
            }
            if ($sub <= 0) {
                throw ValidationException::withMessages(['items' => 'Keep at least one item, or cancel the order.']);
            }
            $disc = min($sub, max(0, (int) round(($discount ?? $shop->discount_total) * 100)));
            $limit = (float) ($tenant->setting('discount_limit_percent', 10) ?? 10);
            if ($disc > (int) round($shop->discount_total * 100) && $disc / $sub * 100 > $limit + 0.0001 && ! $by->can('Override price')) {
                throw ValidationException::withMessages(['discount' => 'That discount needs someone allowed to override prices.']);
            }
            $ship = $shop->fulfilment === 'delivery' ? max(0, (int) round(($shippingFee ?? $shop->shipping_total) * 100)) : 0;
            $shop->forceFill(['subtotal' => $sub / 100, 'discount_total' => $disc / 100, 'shipping_total' => $ship / 100,
                'total' => max(0, $sub - $disc + $ship) / 100, 'handled_by' => $by->id])->save();
            $this->event($shop, 'note', null, 'Order changed by ' . $by->name . '. Before: ' . Str::limit($before, 300), false, $by);
            AuditLogger::log('shop_order.revised', $shop, after: ['total' => $shop->total]);

            return $shop->fresh('items');
        });
    }

    /* -------------------------------------------------------------- upkeep */

    /** Cancel unpaid orders whose hold has run out (card checkouts are checked with the provider first). */
    public function expireStale(): int
    {
        $n = 0;
        $due = ShopOrder::whereIn('status', ['pending_payment', 'awaiting_confirmation'])->whereNull('order_id')
            ->whereNotNull('expires_at')->where('expires_at', '<', now())->where('payment_status', 'unpaid')->get();
        foreach ($due as $shop) {
            if ($shop->status === 'pending_payment') {
                foreach ($shop->intents()->where('status', 'pending')->get() as $intent) {
                    rescue(fn () => app(StorePaymentService::class)->confirm($intent), null, false);
                }
                if ($shop->fresh()->order_id) {
                    continue;
                }
            }
            rescue(function () use ($shop, &$n) {
                $this->cancel($shop->fresh(), 'Not paid or confirmed in time — the items were put back on sale.');
                $n++;
            }, null, false);
        }

        return $n;
    }

    public function event(ShopOrder $shop, string $type, ?string $status, ?string $note, bool $visible = true, ?User $by = null): ShopOrderEvent
    {
        return ShopOrderEvent::create([
            'shop_order_id' => $shop->id, 'type' => $type, 'status' => $status, 'note' => $note ? Str::limit($note, 495) : null,
            'customer_visible' => $visible, 'user_id' => $by?->id ?? auth()->id(),
        ]);
    }
}
