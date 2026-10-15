<?php

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Services\Audit\AuditLogger;
use App\Services\Numbering\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Stock items, lots and the link to ready-made product variants (SRS FR-INV-001/002/003). */
class ItemService
{
    public function create(array $data): InventoryItem
    {
        return DB::transaction(function () use ($data) {
            $data['code'] = trim((string) ($data['code'] ?? '')) ?: DocumentNumber::next('item');
            $data['decimals'] ??= InventoryItem::UNITS[$data['unit'] ?? 'pcs'] ?? 0;
            if (($data['type'] ?? null) === 'customer_material') {
                $data['average_cost'] = 0;
                $data['sale_price'] = null;
            } elseif (isset($data['opening_cost'])) {
                $data['average_cost'] = (float) $data['opening_cost'];
            }
            unset($data['opening_cost']);

            $item = InventoryItem::create($data + ['created_by' => auth()->id()]);
            AuditLogger::log('inventory_item.created', $item, after: $item->only(['code', 'name', 'type', 'unit']));

            return $item;
        });
    }

    public function update(InventoryItem $item, array $data): InventoryItem
    {
        $before = $item->only(['code', 'name', 'type', 'unit', 'decimals', 'reorder_level', 'sale_price', 'is_active']);

        if (isset($data['type']) && $data['type'] !== $item->type && $item->movements()->exists()) {
            if ($data['type'] === 'customer_material' || $item->type === 'customer_material') {
                throw ValidationException::withMessages(['type' => 'An item with stock history cannot be switched to or from customer material.']);
            }
        }
        if (isset($data['unit']) && $data['unit'] !== $item->unit && $item->movements()->exists()) {
            throw ValidationException::withMessages(['unit' => 'The unit cannot change once stock has moved — create a new item instead.']);
        }
        unset($data['opening_cost']);

        $item->update($data);
        AuditLogger::log('inventory_item.updated', $item, $before, $item->only(array_keys($before)));

        return $item;
    }

    /** Find or create a lot for an item ("R-104" roll, dye lot, remnant). */
    public function lot(InventoryItem $item, ?string $lotNo, array $attrs = []): ?StockLot
    {
        $lotNo = trim((string) $lotNo);
        if ($lotNo === '') {
            return null;
        }

        return StockLot::firstOrCreate(['item_id' => $item->id, 'lot_no' => $lotNo], array_filter([
            'kind'        => $attrs['kind'] ?? ($item->type === 'fabric' ? 'roll' : 'lot'),
            'colour'      => $attrs['colour'] ?? $item->colour,
            'width'       => $attrs['width'] ?? $item->width,
            'supplier_id' => $attrs['supplier_id'] ?? null,
            'parent_lot_id' => $attrs['parent_lot_id'] ?? null,
            'expires_on'  => $attrs['expires_on'] ?? null,
            'notes'       => $attrs['notes'] ?? null,
        ], fn ($v) => $v !== null));
    }

    /**
     * Keep one stock item per variant of a stock-tracked ready-made product (FR-POS-007).
     * Called after the product is saved. Variants that disappear keep their item
     * (history) but it is switched off.
     */
    public function syncProduct(Product $product): void
    {
        $product->loadMissing('variants', 'category');

        DB::transaction(function () use ($product) {
            $keep = [];
            if ($product->track_stock && $product->status === 'active') {
                foreach ($product->variants as $variant) {
                    $item = InventoryItem::where('product_variant_id', $variant->id)->first();
                    $name = $product->name . (($variant->size || $variant->colour) ? ' — ' . collect([$variant->size, $variant->colour])->filter()->implode(' / ') : '');
                    $fields = [
                        'name'          => mb_substr($name, 0, 150),
                        'type'          => 'product',
                        'category'      => $product->category?->name,
                        'colour'        => $variant->colour,
                        'barcode'       => $this->freeBarcode($variant->barcode, $item),
                        'sale_price'    => $variant->price,
                        'reorder_level' => $variant->reorder_level,
                        'is_active'     => (bool) $variant->is_active,
                    ];

                    if ($item) {
                        $item->update($fields + ['code' => $this->freeCode($variant->sku, $item)]);
                    } else {
                        $item = InventoryItem::create($fields + [
                            'code' => $this->freeCode($variant->sku, null), 'unit' => 'pcs', 'decimals' => 0,
                            'product_variant_id' => $variant->id, 'average_cost' => (float) ($variant->cost_price ?? 0),
                            'created_by' => auth()->id(),
                        ]);
                    }
                    $keep[] = $item->id;
                }
            }

            // Tracking switched off or product archived: switch its stock items off (history stays).
            InventoryItem::whereIn('product_variant_id', $product->variants->pluck('id'))
                ->whereNotIn('id', $keep)->update(['is_active' => false]);
        });
    }

    protected function freeCode(string $wanted, ?InventoryItem $self): string
    {
        $code = $wanted;
        $n = 2;
        while (InventoryItem::where('code', $code)->when($self, fn ($q) => $q->whereKeyNot($self->id))->exists()) {
            $code = $wanted . '-' . $n++;
        }

        return $code;
    }

    protected function freeBarcode(?string $barcode, ?InventoryItem $self): ?string
    {
        if (! $barcode) {
            return null;
        }
        $taken = InventoryItem::where('barcode', $barcode)->when($self, fn ($q) => $q->whereKeyNot($self->id))->exists();

        return $taken ? null : $barcode;
    }
}
