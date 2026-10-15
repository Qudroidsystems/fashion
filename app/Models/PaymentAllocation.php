<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only: + applies payment money to an order / invoice, − takes it back (refund, reversal). */
class PaymentAllocation extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'payment_id', 'order_id', 'invoice_id', 'refund_id', 'amount', 'note', 'user_id'];

    protected $casts = ['amount' => 'float'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Payment allocations are append-only.'));
        static::deleting(fn () => throw new \LogicException('Payment allocations are append-only.'));
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
