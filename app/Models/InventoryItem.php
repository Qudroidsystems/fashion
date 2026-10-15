<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Anything the house keeps in stock (SRS FR-INV-001/002): fabric, accessories,
 * consumables, ready-made product variants, equipment and customer-owned material.
 */
class InventoryItem extends Model
{
    use BelongsToTenant, HasPublicId;

    public const TYPES = [
        'fabric'            => 'Fabric',
        'accessory'         => 'Accessory / trim',
        'consumable'        => 'Consumable',
        'product'           => 'Ready-made product',
        'equipment'         => 'Equipment',
        'customer_material' => 'Customer’s material',
    ];

    public const TYPE_ICONS = [
        'fabric' => 'ri-stack-line', 'accessory' => 'ri-scissors-2-line', 'consumable' => 'ri-flask-line',
        'product' => 'ri-t-shirt-line', 'equipment' => 'ri-tools-line', 'customer_material' => 'ri-user-shared-line',
    ];

    /** unit => default decimals */
    public const UNITS = [
        'yd' => 2, 'm' => 2, 'pcs' => 0, 'roll' => 0, 'bundle' => 0, 'pack' => 0, 'spool' => 0,
        'cone' => 0, 'card' => 0, 'pair' => 0, 'set' => 0, 'kg' => 3, 'g' => 0, 'l' => 2,
    ];

    protected $fillable = [
        'tenant_id', 'code', 'name', 'type', 'category', 'unit', 'decimals', 'barcode', 'colour', 'width',
        'description', 'average_cost', 'last_cost', 'sale_price', 'reorder_level', 'reorder_quantity',
        'preferred_supplier_id', 'product_variant_id', 'customer_id', 'track_lots', 'is_active', 'created_by',
    ];

    protected $casts = [
        'decimals'         => 'integer',
        'average_cost'     => 'float',
        'last_cost'        => 'float',
        'sale_price'       => 'float',
        'reorder_level'    => 'float',
        'reorder_quantity' => 'float',
        'track_lots'       => 'boolean',
        'is_active'        => 'boolean',
    ];

    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'item_id');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(StockLot::class, 'item_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class, 'item_id');
    }

    public function preferredSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'preferred_supplier_id');
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function scopeSearch($q, ?string $term)
    {
        $term = trim((string) $term);

        return $term === '' ? $q : $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
            ->orWhere('code', 'like', "%{$term}%")->orWhere('barcode', $term)->orWhere('colour', 'like', "%{$term}%")
            ->orWhere('category', 'like', "%{$term}%"));
    }

    public function isCustomerOwned(): bool
    {
        return $this->type === 'customer_material';
    }

    /** Round a quantity to this item's precision. */
    public function roundQty(float|int|string|null $q): float
    {
        return round((float) $q, $this->decimals);
    }

    /** "12.5 yd" with this item's precision, trailing zeros trimmed. */
    public function qty(float|int|string|null $q, bool $withUnit = true): string
    {
        $s = number_format((float) $q, $this->decimals, '.', ',');
        if ($this->decimals > 0) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        return $withUnit ? $s . ' ' . $this->unit : $s;
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getLabelAttribute(): string
    {
        return $this->name . ($this->colour && ! str_contains($this->name, $this->colour) ? ' · ' . $this->colour : '');
    }
}
