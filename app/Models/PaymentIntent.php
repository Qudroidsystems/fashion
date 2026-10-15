<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An online payment attempt through a provider (SRS FR-ORD-007/008). */
class PaymentIntent extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = [
        'tenant_id', 'provider', 'reference', 'customer_id', 'order_id', 'invoice_id', 'amount', 'currency', 'status',
        'authorization_url', 'provider_status', 'provider_channel', 'provider_payload', 'payment_id', 'paid_at', 'verified_at', 'created_by',
    ];

    protected $hidden = ['provider_payload'];

    protected $casts = ['amount' => 'float', 'provider_payload' => 'array', 'paid_at' => 'datetime', 'verified_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
