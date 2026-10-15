<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\PurchaseOrderLine;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Notifications\LowStock;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only way stock changes (SRS FR-INV-004, §7 "stock movement is append-only").
 *
 * Every post() writes one immutable ledger row and updates the cached balance
 * for item / location / lot in the same transaction, under a row lock, so
 * concurrent posts cannot lose updates. Balances can always be rebuilt from
 * the ledger (see reconcile()).
 *
 * Costing (FR-INV-009): weighted average per item across all locations.
 * Incoming stock with a cost (receipts, opening balances) re-averages the
 * item; outgoing stock is valued at the current average. Customer-owned
 * material carries no cost and is excluded from valuation (FR-INV-010).
 */
class StockLedger
{
    /** Movement types that bring stock in at a purchase-like cost and re-average the item. */
    public const COSTED_IN = ['receipt', 'opening'];

    /**
     * @param array{lot_id?: ?int, unit_cost?: ?float, source?: ?Model, source_type?: ?string, source_id?: ?int,
     *              reference?: ?string, reason?: ?string, customer_id?: ?int, order_id?: ?int, project_id?: ?int,
     *              occurred_at?: mixed, allow_negative?: bool} $ctx
     */
    public function post(InventoryItem $item, int $branchId, float $quantity, string $type, array $ctx = []): StockMovement
    {
        if (! array_key_exists($type, StockMovement::TYPES)) {
            throw new \InvalidArgumentException("Unknown movement type [$type].");
        }
        $quantity = $item->roundQty($quantity);
        if ($quantity == 0.0) {
            throw ValidationException::withMessages(['quantity' => "Quantity for {$item->name} must not be zero."]);
        }

        return DB::transaction(function () use ($item, $branchId, $quantity, $type, $ctx) {
            $lotId = $ctx['lot_id'] ?? null;
            $balance = $this->lockBalance($item, $branchId, $lotId);
            $newOnHand = round($balance->on_hand + $quantity, 3);

            $allowNegative = $ctx['allow_negative'] ?? (bool) CurrentTenant::get()?->setting('allow_negative_stock', false);
            if ($newOnHand < 0 && ! $allowNegative) {
                $where = Branch::whereKey($branchId)->value('name');
                throw ValidationException::withMessages(['quantity' => sprintf(
                    'Not enough %s at %s%s: %s in stock, %s needed.',
                    $item->name, $where, $lotId ? ' (' . \App\Models\StockLot::whereKey($lotId)->value('lot_no') . ')' : '',
                    $item->qty($balance->on_hand), $item->qty(abs($quantity))
                )]);
            }

            $unitCost = $this->unitCost($item, $quantity, $type, $ctx);
            if ($quantity > 0 && in_array($type, self::COSTED_IN, true) && ! $item->isCustomerOwned()) {
                $this->reaverage($item, $quantity, $unitCost);
            }

            $source = $ctx['source'] ?? null;
            $movement = StockMovement::create([
                'item_id'        => $item->id,
                'branch_id'      => $branchId,
                'lot_id'         => $lotId,
                'type'           => $type,
                'quantity'       => $quantity,
                'unit_cost'      => $unitCost,
                'balance_after'  => $newOnHand,
                'source_type'    => $source ? $this->sourceType($source) : ($ctx['source_type'] ?? null),
                'source_id'      => $source?->getKey() ?? ($ctx['source_id'] ?? null),
                'reference'      => $ctx['reference'] ?? ($source->number ?? null),
                'reason'         => $ctx['reason'] ?? null,
                'customer_id'    => $ctx['customer_id'] ?? null,
                'order_id'       => $ctx['order_id'] ?? null,
                'project_id'     => $ctx['project_id'] ?? null,
                'user_id'        => auth()->id(),
                'correlation_id' => request()?->attributes->get('correlation_id'),
                'occurred_at'    => $ctx['occurred_at'] ?? now(),
            ]);

            $balance->on_hand = $newOnHand;
            $balance->save();

            $this->checkLowStock($item, $branchId);

            return $movement;
        });
    }

    /* ----------------------------- reservations ----------------------------- */

    public function reserve(InventoryItem $item, int $branchId, float $quantity, array $attrs = []): StockReservation
    {
        $quantity = $item->roundQty($quantity);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Reserve a quantity above zero.']);
        }

        return DB::transaction(function () use ($item, $branchId, $quantity, $attrs) {
            $row = $this->lockBalance($item, $branchId, null);
            $available = $this->availableAt($item, $branchId);
            if ($quantity > $available + 0.0005 && ! ($attrs['allow_short'] ?? false)) {
                throw ValidationException::withMessages(['quantity' => sprintf(
                    'Only %s of %s is free to reserve at %s.', $item->qty(max(0, $available)), $item->name, Branch::whereKey($branchId)->value('name')
                )]);
            }

            $reservation = StockReservation::create([
                'item_id' => $item->id, 'branch_id' => $branchId, 'quantity' => $quantity, 'status' => 'active',
                'customer_id' => $attrs['customer_id'] ?? null, 'order_id' => $attrs['order_id'] ?? null,
                'project_id' => $attrs['project_id'] ?? null, 'reference' => $attrs['reference'] ?? null,
                'needed_by' => $attrs['needed_by'] ?? null, 'created_by' => auth()->id(),
            ]);
            $row->reserved = round($row->reserved + $quantity, 3);
            $row->save();
            $this->checkLowStock($item, $branchId);

            return $reservation;
        });
    }

    public function release(StockReservation $reservation): void
    {
        DB::transaction(function () use ($reservation) {
            $reservation = StockReservation::lockForUpdate()->findOrFail($reservation->id);
            if ($reservation->status !== 'active') {
                return;
            }
            $open = $reservation->open_quantity;
            $reservation->update(['status' => 'released', 'released_by' => auth()->id(), 'released_at' => now()]);
            $this->adjustReserved($reservation, -$open);
        });
    }

    /** Use up part of a reservation (called when its material is issued). */
    public function consume(StockReservation $reservation, float $quantity): void
    {
        DB::transaction(function () use ($reservation, $quantity) {
            $reservation = StockReservation::lockForUpdate()->findOrFail($reservation->id);
            if ($reservation->status !== 'active') {
                return;
            }
            $take = min($reservation->open_quantity, $quantity);
            $reservation->consumed = round($reservation->consumed + $take, 3);
            if ($reservation->consumed >= $reservation->quantity - 0.0005) {
                $reservation->status = 'consumed';
            }
            $reservation->save();
            $this->adjustReserved($reservation, -$take);
        });
    }

    protected function adjustReserved(StockReservation $reservation, float $delta): void
    {
        if ($delta == 0.0) {
            return;
        }
        $row = $this->lockBalance($reservation->item, $reservation->branch_id, null);
        $row->reserved = max(0, round($row->reserved + $delta, 3));
        $row->save();
        $this->checkLowStock($reservation->item, $reservation->branch_id);
    }

    /* ------------------------------ queries -------------------------------- */

    /** on_hand, reserved, available, incoming (open POs) and available-to-promise for an item, optionally at one location. */
    public function availability(InventoryItem $item, ?int $branchId = null): array
    {
        $b = StockBalance::where('item_id', $item->id)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('coalesce(sum(on_hand),0) on_hand, coalesce(sum(reserved),0) reserved')->first();
        $incoming = (float) PurchaseOrderLine::where('item_id', $item->id)
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['approved', 'partially_received'])
                ->when($branchId, fn ($w) => $w->where('branch_id', $branchId)))
            ->selectRaw('coalesce(sum(quantity - received_quantity),0) q')->value('q');

        $onHand = round((float) $b->on_hand, 3);
        $reserved = round((float) $b->reserved, 3);

        return [
            'on_hand'   => $onHand,
            'reserved'  => $reserved,
            'available' => round($onHand - $reserved, 3),
            'incoming'  => round(max(0, $incoming), 3),
            'atp'       => round($onHand - $reserved + max(0, $incoming), 3),
        ];
    }

    public function availableAt(InventoryItem $item, int $branchId): float
    {
        return $this->availability($item, $branchId)['available'];
    }

    /** Value of business-owned stock at weighted-average cost. */
    public function valuation(?int $branchId = null): float
    {
        return (float) StockBalance::query()
            ->join('inventory_items', 'inventory_items.id', '=', 'stock_balances.item_id')
            ->where('inventory_items.type', '!=', 'customer_material')
            ->when($branchId, fn ($q) => $q->where('stock_balances.branch_id', $branchId))
            ->selectRaw('coalesce(sum(stock_balances.on_hand * inventory_items.average_cost),0) v')->value('v');
    }

    /**
     * Compare cached balances with the ledger and with active reservations.
     * Returns the differences; with $fix the cache is rebuilt from the source of truth.
     */
    public function reconcile(bool $fix = false): Collection
    {
        $ledger = StockMovement::query()
            ->selectRaw('item_id, branch_id, coalesce(lot_id,0) lot_key, max(lot_id) lot_id, sum(quantity) qty')
            ->groupBy('item_id', 'branch_id', DB::raw('coalesce(lot_id,0)'))->get()
            ->keyBy(fn ($r) => "{$r->item_id}:{$r->branch_id}:{$r->lot_key}");
        $reserved = StockReservation::where('status', 'active')
            ->selectRaw('item_id, branch_id, sum(quantity - consumed) qty')->groupBy('item_id', 'branch_id')->get()
            ->keyBy(fn ($r) => "{$r->item_id}:{$r->branch_id}:0");
        $balances = StockBalance::all()->keyBy(fn ($b) => "{$b->item_id}:{$b->branch_id}:{$b->lot_key}");

        $keys = $ledger->keys()->merge($reserved->keys())->merge($balances->keys())->unique();
        $diffs = collect();
        foreach ($keys as $key) {
            [$itemId, $branchId, $lotKey] = array_map('intval', explode(':', $key));
            $wantOnHand = round((float) ($ledger[$key]->qty ?? 0), 3);
            $wantReserved = round((float) ($reserved[$key]->qty ?? 0), 3);
            $b = $balances[$key] ?? null;
            $haveOnHand = round((float) ($b->on_hand ?? 0), 3);
            $haveReserved = round((float) ($b->reserved ?? 0), 3);

            if (abs($wantOnHand - $haveOnHand) > 0.0005 || abs($wantReserved - $haveReserved) > 0.0005) {
                $diffs->push(compact('itemId', 'branchId', 'lotKey', 'wantOnHand', 'haveOnHand', 'wantReserved', 'haveReserved'));
                if ($fix) {
                    StockBalance::updateOrCreate(
                        ['item_id' => $itemId, 'branch_id' => $branchId, 'lot_key' => $lotKey],
                        ['lot_id' => $lotKey ?: null, 'on_hand' => $wantOnHand, 'reserved' => $wantReserved]
                    );
                }
            }
        }

        return $diffs;
    }

    /* ------------------------------ internals ------------------------------ */

    /** Get-or-create the balance row and lock it for this transaction. */
    protected function lockBalance(InventoryItem $item, int $branchId, ?int $lotId): StockBalance
    {
        $tenantId = $item->tenant_id;
        $lotKey = $lotId ?? 0;
        DB::table('stock_balances')->insertOrIgnore([
            'tenant_id' => $tenantId, 'item_id' => $item->id, 'branch_id' => $branchId, 'lot_key' => $lotKey,
            'lot_id' => $lotId, 'on_hand' => 0, 'reserved' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return StockBalance::where(['item_id' => $item->id, 'branch_id' => $branchId, 'lot_key' => $lotKey])
            ->lockForUpdate()->firstOrFail();
    }

    protected function unitCost(InventoryItem $item, float $quantity, string $type, array $ctx): float
    {
        if ($item->isCustomerOwned()) {
            return 0.0;
        }
        if (isset($ctx['unit_cost']) && $ctx['unit_cost'] !== null && ($quantity > 0 || $type === 'supplier_return')) {
            return round((float) $ctx['unit_cost'], 4);
        }

        return round((float) $item->fresh()->average_cost, 4);
    }

    protected function reaverage(InventoryItem $item, float $quantity, float $unitCost): void
    {
        $item = InventoryItem::lockForUpdate()->find($item->id);
        $current = max(0, (float) StockBalance::where('item_id', $item->id)->sum('on_hand'));
        $avg = $current > 0
            ? (($current * $item->average_cost) + ($quantity * $unitCost)) / ($current + $quantity)
            : $unitCost;
        $item->forceFill(['average_cost' => round($avg, 4), 'last_cost' => $unitCost])->save();
    }

    /**
     * FR-INV-008 / §10: alert once when available stock at a location drops
     * below the item's reorder level; re-arm when it recovers.
     */
    protected function checkLowStock(InventoryItem $item, int $branchId): void
    {
        $level = $item->reorder_level;
        if ($level === null || $level <= 0 || ! $item->is_active || $item->isCustomerOwned()) {
            return;
        }
        $flagRow = StockBalance::where(['item_id' => $item->id, 'branch_id' => $branchId, 'lot_key' => 0])->first()
            ?? $this->lockBalance($item, $branchId, null);
        $available = $this->availableAt($item, $branchId);

        if ($available < $level && ! $flagRow->low_stock_alerted_at) {
            $flagRow->forceFill(['low_stock_alerted_at' => now()])->save();
            $branch = Branch::find($branchId);
            DB::afterCommit(function () use ($item, $branch, $available) {
                $this->stockManagers()->each(fn (User $u) => $u->notify(new LowStock($item, $branch, $available)));
            });
        } elseif ($available >= $level && $flagRow->low_stock_alerted_at) {
            $flagRow->forceFill(['low_stock_alerted_at' => null])->save();
        }
    }

    protected function stockManagers(): Collection
    {
        return User::inCurrentTenant()->get()->filter(fn (User $u) => $u->can('Manage inventory'));
    }

    protected function sourceType(Model $source): string
    {
        return \Illuminate\Support\Str::snake(class_basename($source));
    }
}
