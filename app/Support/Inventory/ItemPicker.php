<?php

namespace App\Support\Inventory;

use App\Models\InventoryItem;
use App\Models\StockBalance;
use App\Models\StockLot;

/**
 * Data for the line editors on stock forms: items with unit, precision, lots
 * and per-location balances, so the form can show "12.5 yd free here" and
 * offer only lots that are in stock.
 */
class ItemPicker
{
    /** @param string[]|null $types */
    public static function data(?array $types = null, bool $customerMaterial = true, ?int $onlyCustomerId = null): array
    {
        $items = InventoryItem::active()
            ->when($types, fn ($q) => $q->whereIn('type', $types))
            ->when(! $customerMaterial, fn ($q) => $q->where('type', '!=', 'customer_material'))
            ->when($onlyCustomerId, fn ($q) => $q->where(fn ($w) => $w->where('type', '!=', 'customer_material')->orWhere('customer_id', $onlyCustomerId)))
            ->with('customer:id,first_name,last_name')
            ->orderBy('type')->orderBy('name')->get();

        $balances = StockBalance::whereIn('item_id', $items->pluck('id'))->get()->groupBy('item_id');
        $lots = StockLot::whereIn('item_id', $items->pluck('id'))->orderBy('lot_no')->get()->groupBy('item_id');

        return $items->map(function (InventoryItem $i) use ($balances, $lots) {
            $stock = [];
            $reserved = [];
            foreach ($balances[$i->id] ?? [] as $b) {
                $stock[$b->branch_id][$b->lot_key] = (float) $b->on_hand;
                $reserved[$b->branch_id] = ($reserved[$b->branch_id] ?? 0) + (float) $b->reserved;
            }

            return [
                'id'        => $i->id,
                'code'      => $i->code,
                'name'      => $i->name . ($i->colour && ! str_contains($i->name, $i->colour) ? ' · ' . $i->colour : '')
                    . ($i->isCustomerOwned() && $i->customer ? ' (' . $i->customer->name . ')' : ''),
                'type'      => $i->type_label,
                'unit'      => $i->unit,
                'decimals'  => $i->decimals,
                'lots'      => (bool) $i->track_lots,
                'cost'      => round($i->average_cost, 2),
                'last_cost' => $i->last_cost !== null ? round($i->last_cost, 2) : null,
                'stock'     => $stock,
                'reserved'  => $reserved,
                'lot_list'  => ($lots[$i->id] ?? collect())->map(fn ($l) => ['id' => $l->id, 'label' => $l->label . ($l->colour ? ' · ' . $l->colour : '')])->values(),
            ];
        })->values()->all();
    }
}
