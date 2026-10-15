<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Goods-received note: posting it adds stock (SRS FR-INV-005, §7 "purchase receipts create stock movements"). */
class GoodsReceipt extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = [
        'tenant_id', 'number', 'purchase_order_id', 'supplier_id', 'branch_id', 'supplier_reference',
        'received_at', 'total', 'notes', 'received_by',
    ];

    protected $casts = ['received_at' => 'datetime', 'total' => 'float'];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
