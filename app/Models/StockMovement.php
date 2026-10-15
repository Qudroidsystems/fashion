<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the append-only stock ledger (SRS FR-INV-004). Created only by
 * App\Services\Inventory\StockLedger; never updated or deleted — corrections
 * are new movements.
 */
class StockMovement extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const TYPES = [
        'opening'           => 'Opening balance',
        'receipt'           => 'Received',
        'supplier_return'   => 'Returned to supplier',
        'issue'             => 'Issued to production',
        'production_return' => 'Returned from production',
        'wastage'           => 'Wastage',
        'transfer_out'      => 'Transfer out',
        'transfer_in'       => 'Transfer in',
        'adjustment'        => 'Adjustment',
        'count'             => 'Stock count',
        'sale'              => 'Sold',
        'sale_return'       => 'Sale return',
        'customer_material' => 'Customer material in',
    ];

    /** source_type => route name for the document page */
    public const SOURCE_ROUTES = [
        'goods_receipt'    => 'goods-receipts.show',
        'stock_transfer'   => 'stock-transfers.show',
        'stock_adjustment' => 'stock-adjustments.show',
        'material_issue'   => 'material-issues.show',
    ];

    protected $fillable = [
        'tenant_id', 'item_id', 'branch_id', 'lot_id', 'type', 'quantity', 'unit_cost', 'balance_after',
        'source_type', 'source_id', 'reference', 'reason', 'customer_id', 'order_id', 'project_id',
        'user_id', 'correlation_id', 'occurred_at',
    ];

    protected $casts = [
        'quantity'      => 'float',
        'unit_cost'     => 'float',
        'balance_after' => 'float',
        'occurred_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Stock movements are append-only. Post a correcting movement instead.'));
        static::deleting(fn () => throw new \LogicException('Stock movements are append-only. Post a correcting movement instead.'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** Link to the document that caused this movement, if it has a page. */
    public function sourceUrl(): ?string
    {
        $route = self::SOURCE_ROUTES[$this->source_type] ?? null;
        if (! $route || ! $this->source_id) {
            return null;
        }
        $class = ['goods_receipt' => GoodsReceipt::class, 'stock_transfer' => StockTransfer::class,
            'stock_adjustment' => StockAdjustment::class, 'material_issue' => MaterialIssue::class][$this->source_type];
        $publicId = $class::whereKey($this->source_id)->value('public_id');

        return $publicId ? route($route, $publicId) : null;
    }
}
