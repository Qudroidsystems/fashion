<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Orders\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Status history; customer_visible events may be shown to the customer (SRS §10). */
class OrderStatusEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'order_id', 'from_status', 'to_status', 'note', 'customer_visible', 'user_id'];

    protected $casts = ['customer_visible' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getLabelAttribute(): string
    {
        return OrderStatus::label($this->to_status);
    }
}
