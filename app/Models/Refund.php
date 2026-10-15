<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money given back to a customer; needs approval (SRS FR-ORD-010, scenario 37). */
class Refund extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = [
        'tenant_id', 'payment_id', 'order_id', 'invoice_id', 'amount', 'method', 'reason', 'status', 'reference',
        'requested_by', 'approved_by', 'approved_at', 'rejection_reason',
    ];

    protected $casts = ['amount' => 'float', 'approved_at' => 'datetime'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
