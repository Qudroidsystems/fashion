<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A checkout on the online shop (or an order a rep took on WhatsApp).
 * Holds a frozen copy of what was bought. Once paid or accepted it is linked to the
 * house's normal confirmed order (order_id), which carries payments and fulfilment.
 */
class ShopOrder extends Model
{
    use BelongsToTenant, HasPublicId;

    /** status => [staff label, pill class, customer label] */
    public const STATUSES = [
        'pending_payment'       => ['Waiting for card payment', 'st-muted', 'Waiting for your payment'],
        'awaiting_confirmation' => ['Needs confirming', 'st-warning', 'Received — the house will confirm it shortly'],
        'confirmed'             => ['Confirmed', 'st-info', 'Confirmed'],
        'processing'            => ['Being prepared', 'st-violet', 'Being prepared'],
        'ready_for_pickup'      => ['Ready for pickup', 'st-success', 'Ready for pickup'],
        'shipped'               => ['Out for delivery', 'st-info', 'On its way'],
        'delivered'             => ['Delivered', 'st-success', 'Delivered'],
        'cancelled'             => ['Cancelled', 'st-danger', 'Cancelled'],
    ];

    public const PAYMENT_METHODS = [
        'paystack'        => 'Card / bank (Paystack)',
        'stripe'          => 'Card (Stripe)',
        'bank_transfer'   => 'Bank transfer',
        'whatsapp'        => 'Arrange on WhatsApp',
        'pay_on_delivery' => 'Pay on delivery / pickup',
    ];

    public const PAYMENT_STATUSES = [
        'unpaid'         => ['Not paid', 'st-muted'],
        'pending'        => ['Payment to check', 'st-warning'],
        'partially_paid' => ['Part paid', 'st-info'],
        'paid'           => ['Paid', 'st-success'],
        'refunded'       => ['Refunded', 'st-danger'],
    ];

    /** Still open: stock is held, staff may act on it. */
    public const OPEN = ['pending_payment', 'awaiting_confirmation', 'confirmed', 'processing', 'ready_for_pickup', 'shipped'];

    protected $fillable = [
        'tenant_id', 'number', 'token', 'source', 'account_id', 'customer_id', 'order_id', 'status', 'payment_method', 'payment_status',
        'fulfilment', 'first_name', 'last_name', 'email', 'phone', 'address_line1', 'address_line2', 'city', 'state', 'country', 'postal_code',
        'pickup_branch_id', 'shipping_zone_id', 'shipping_label', 'subtotal', 'discount_total', 'shipping_total', 'total', 'amount_paid',
        'currency', 'coupon_code', 'coupon_id', 'customer_note', 'internal_note', 'payment_proof_path', 'payment_reference',
        'proof_uploaded_at', 'courier', 'tracking_number', 'expires_at', 'confirmed_at', 'paid_at', 'shipped_at', 'delivered_at',
        'cancelled_at', 'cancel_reason', 'whatsapp_notified_at', 'handled_by', 'created_by', 'ip', 'user_agent',
    ];

    protected $hidden = ['token', 'ip', 'user_agent'];

    protected $casts = [
        'subtotal' => 'float', 'discount_total' => 'float', 'shipping_total' => 'float', 'total' => 'float', 'amount_paid' => 'float',
        'proof_uploaded_at' => 'datetime', 'expires_at' => 'datetime', 'confirmed_at' => 'datetime', 'paid_at' => 'datetime',
        'shipped_at' => 'datetime', 'delivered_at' => 'datetime', 'cancelled_at' => 'datetime', 'whatsapp_notified_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ShopOrderItem::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShopOrderEvent::class)->orderBy('id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(StoreAccount::class, 'account_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function pickupBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'pickup_branch_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function intents(): HasMany
    {
        return $this->hasMany(PaymentIntent::class)->latest('id');
    }

    public function getNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    public function getCustomerStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status][2] ?? $this->status_label;
    }

    public function getStatusPillAttribute(): string
    {
        return self::STATUSES[$this->status][1] ?? 'st-muted';
    }

    public function getPaymentStatusLabelAttribute(): string
    {
        return self::PAYMENT_STATUSES[$this->payment_status][0] ?? $this->payment_status;
    }

    public function getPaymentStatusPillAttribute(): string
    {
        return self::PAYMENT_STATUSES[$this->payment_status][1] ?? 'st-muted';
    }

    public function getPaymentMethodLabelAttribute(): string
    {
        return self::PAYMENT_METHODS[$this->payment_method] ?? $this->payment_method;
    }

    public function getAddressAttribute(): string
    {
        return collect([$this->address_line1, $this->address_line2, $this->city, $this->state, $this->country])->filter()->implode(', ');
    }

    public function getProofUrlAttribute(): ?string
    {
        return $this->payment_proof_path ? asset('storage/' . $this->payment_proof_path) : null;
    }

    public function balance(): float
    {
        return round(max(0, $this->total - $this->amount_paid), 2);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** Not yet turned into a confirmed order (staff still have to accept it, or the card payment is pending). */
    public function isAwaiting(): bool
    {
        return in_array($this->status, ['pending_payment', 'awaiting_confirmation'], true);
    }

    public function canPayOnline(): bool
    {
        return $this->isOpen() && $this->balance() > 0.004 && $this->status !== 'cancelled';
    }

    public function itemCount(): int
    {
        return (int) $this->items->sum('quantity');
    }
}
