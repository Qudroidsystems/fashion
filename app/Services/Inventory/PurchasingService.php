<?php

namespace App\Services\Inventory;

use App\Models\GoodsReceipt;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Numbering\DocumentNumber;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Suppliers, purchase orders and goods received (SRS FR-INV-005, §7 procurement).
 *
 * PO life cycle: draft → submit → (auto-approved if the submitter may approve and
 * the total is within the house limit) or pending_approval → approved →
 * partially_received → received. Above the limit the approver must be a
 * different person from the submitter (§9 separation of duties).
 */
class PurchasingService
{
    public function __construct(protected StockLedger $ledger, protected ItemService $items)
    {
    }

    /* ------------------------------ suppliers ------------------------------ */

    public function saveSupplier(?Supplier $supplier, array $data): Supplier
    {
        if (! $supplier) {
            $data['code'] = trim((string) ($data['code'] ?? '')) ?: DocumentNumber::next('supplier');
            $supplier = Supplier::create($data + ['created_by' => auth()->id()]);
            AuditLogger::log('supplier.created', $supplier, after: $supplier->only(['code', 'name']));

            return $supplier;
        }
        $before = $supplier->only(['name', 'phone', 'email', 'is_active']);
        unset($data['code']);
        $supplier->update($data);
        AuditLogger::log('supplier.updated', $supplier, $before, $supplier->only(array_keys($before)));

        return $supplier;
    }

    /* --------------------------- purchase orders --------------------------- */

    /** $lines: [['item_id' =>, 'quantity' =>, 'unit_cost' =>, 'description' =>], …] */
    public function savePurchaseOrder(?PurchaseOrder $po, array $data, array $lines): PurchaseOrder
    {
        if ($po && ! $po->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Only draft purchase orders can be changed.']);
        }
        $lines = $this->cleanLines($lines);
        if (! $lines) {
            throw ValidationException::withMessages(['lines' => 'Add at least one item.']);
        }

        return DB::transaction(function () use ($po, $data, $lines) {
            $creating = ! $po;
            $po ??= new PurchaseOrder(['status' => 'draft', 'created_by' => auth()->id(), 'number' => DocumentNumber::next('purchase_order')]);
            $po->fill([
                'supplier_id'   => $data['supplier_id'],
                'branch_id'     => $data['branch_id'],
                'order_date'    => $data['order_date'] ?? now(CurrentTenant::get()?->timezone)->toDateString(),
                'expected_date' => $data['expected_date'] ?? null,
                'notes'         => $data['notes'] ?? null,
            ])->save();

            $po->lines()->delete();
            $total = 0;
            foreach ($lines as $i => $l) {
                $po->lines()->create($l + ['sort_order' => $i]);
                $total += round($l['quantity'] * $l['unit_cost'], 2);
            }
            $po->update(['total' => round($total, 2)]);

            AuditLogger::log($creating ? 'purchase_order.created' : 'purchase_order.updated', $po, after: ['number' => $po->number, 'total' => $po->total]);

            return $po->fresh('lines');
        });
    }

    public function submit(PurchaseOrder $po, User $user): PurchaseOrder
    {
        if ($po->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'This purchase order has already been submitted.']);
        }
        $po->update(['status' => 'pending_approval', 'submitted_by' => $user->id, 'submitted_at' => now()]);
        AuditLogger::log('purchase_order.submitted', $po, after: ['total' => $po->total]);

        if ($user->can('Approve purchase order') && Approvals::allowed($user, $user->id, $po->total, $this->poLimit(), 'Approve purchase order')) {
            return $this->approve($po, $user);
        }

        return $po;
    }

    public function approve(PurchaseOrder $po, User $approver): PurchaseOrder
    {
        if ($po->status !== 'pending_approval') {
            throw ValidationException::withMessages(['status' => 'Only purchase orders awaiting approval can be approved.']);
        }
        if (! Approvals::allowed($approver, $po->submitted_by, $po->total, $this->poLimit(), 'Approve purchase order')) {
            throw ValidationException::withMessages(['approval' => sprintf(
                'This order is above the %s approval limit, so someone other than the requester must approve it.',
                \App\Support\Money::format($this->poLimit())
            )]);
        }
        $po->update(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now()]);
        $self = $po->submitted_by === $approver->id && $po->total > $this->poLimit();
        AuditLogger::log('purchase_order.approved', $po, after: ['total' => $po->total, 'self_approved' => $self],
            description: $self ? 'Self-approved above the limit: no other approver in this house' : null);

        return $po;
    }

    /** Send back to draft (from approval queue) so it can be changed. */
    public function reopen(PurchaseOrder $po): PurchaseOrder
    {
        if (! in_array($po->status, ['pending_approval', 'approved'], true) || $po->receipts()->exists()) {
            throw ValidationException::withMessages(['status' => 'Only unreceived orders can be sent back to draft.']);
        }
        $po->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null, 'submitted_by' => null, 'submitted_at' => null]);
        AuditLogger::log('purchase_order.reopened', $po);

        return $po;
    }

    public function cancel(PurchaseOrder $po, string $reason): PurchaseOrder
    {
        if (in_array($po->status, ['received', 'cancelled', 'closed'], true)) {
            throw ValidationException::withMessages(['status' => 'This purchase order can no longer be cancelled.']);
        }
        // Part-received orders are closed (what arrived stays); untouched ones are cancelled.
        $status = $po->status === 'partially_received' ? 'closed' : 'cancelled';
        $po->update(['status' => $status, 'cancel_reason' => $reason]);
        AuditLogger::log('purchase_order.' . $status, $po, description: $reason);

        return $po;
    }

    /* ---------------------------- goods received --------------------------- */

    /**
     * Receive stock, against a PO or directly from a supplier.
     * $lines: [['purchase_order_line_id' => ?, 'item_id' =>, 'quantity' =>, 'unit_cost' =>, 'lot_no' => ?, 'colour' => ?, 'width' => ?], …]
     */
    public function receive(array $data, array $lines, ?PurchaseOrder $po = null): GoodsReceipt
    {
        if ($po && ! $po->isReceivable()) {
            throw ValidationException::withMessages(['status' => 'Only approved purchase orders can be received.']);
        }
        $lines = array_values(array_filter($lines, fn ($l) => (float) ($l['quantity'] ?? 0) > 0));
        if (! $lines) {
            throw ValidationException::withMessages(['lines' => 'Enter the quantity received for at least one item.']);
        }

        return DB::transaction(function () use ($data, $lines, $po) {
            if ($po) {
                $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
                if (! $po->isReceivable()) {
                    throw ValidationException::withMessages(['status' => 'This purchase order was received or closed meanwhile.']);
                }
            }
            $branchId = (int) ($po?->branch_id ?? $data['branch_id']);
            $grn = GoodsReceipt::create([
                'number'             => DocumentNumber::next('goods_receipt'),
                'purchase_order_id'  => $po?->id,
                'supplier_id'        => $po?->supplier_id ?? ($data['supplier_id'] ?? null),
                'branch_id'          => $branchId,
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'received_at'        => $data['received_at'] ?? now(),
                'notes'              => $data['notes'] ?? null,
                'received_by'        => auth()->id(),
            ]);

            $total = 0;
            foreach ($lines as $l) {
                $poLine = null;
                if ($po) {
                    $poLine = PurchaseOrderLine::where('purchase_order_id', $po->id)->whereKey($l['purchase_order_line_id'] ?? 0)->first();
                    if (! $poLine) {
                        throw ValidationException::withMessages(['lines' => 'A received line does not belong to this purchase order.']);
                    }
                }
                $item = InventoryItem::findOrFail($poLine?->item_id ?? $l['item_id']);
                if ($item->isCustomerOwned()) {
                    throw ValidationException::withMessages(['lines' => "{$item->name} is customer material — record it as customer material received instead."]);
                }
                $qty = $item->roundQty($l['quantity']);
                $cost = round((float) ($l['unit_cost'] ?? $poLine?->unit_cost ?? $item->average_cost), 4);
                $lot = $item->track_lots || ! empty($l['lot_no'])
                    ? $this->items->lot($item, $l['lot_no'] ?? null, ['colour' => $l['colour'] ?? null, 'width' => $l['width'] ?? null, 'supplier_id' => $grn->supplier_id])
                    : null;
                if ($item->track_lots && ! $lot) {
                    throw ValidationException::withMessages(['lines' => "Enter a roll / lot number for {$item->name}."]);
                }

                $grn->lines()->create([
                    'purchase_order_line_id' => $poLine?->id, 'item_id' => $item->id, 'lot_id' => $lot?->id,
                    'quantity' => $qty, 'unit_cost' => $cost,
                ]);
                $this->ledger->post($item, $branchId, $qty, 'receipt', [
                    'lot_id' => $lot?->id, 'unit_cost' => $cost, 'source' => $grn,
                    'reason' => $grn->supplier ? 'From ' . $grn->supplier->name : null,
                ]);
                if ($poLine) {
                    $poLine->increment('received_quantity', $qty);
                }
                $total += round($qty * $cost, 2);
            }
            $grn->update(['total' => round($total, 2)]);

            if ($po) {
                $po->load('lines');
                $done = $po->lines->every(fn ($l) => $l->received_quantity >= $l->quantity - 0.0005);
                $po->update(['status' => $done ? 'received' : 'partially_received']);
            }

            AuditLogger::log('goods_receipt.posted', $grn, after: ['number' => $grn->number, 'total' => $grn->total, 'po' => $po?->number]);

            return $grn->fresh('lines');
        });
    }

    /* ------------------------------ helpers -------------------------------- */

    protected function cleanLines(array $lines): array
    {
        $out = [];
        $items = InventoryItem::whereIn('id', collect($lines)->pluck('item_id')->filter())->get()->keyBy('id');
        foreach ($lines as $l) {
            $item = $items->get((int) ($l['item_id'] ?? 0));
            $qty = (float) ($l['quantity'] ?? 0);
            if (! $item || $qty <= 0) {
                continue;
            }
            if ($item->isCustomerOwned()) {
                throw ValidationException::withMessages(['lines' => "{$item->name} is customer material and cannot be purchased."]);
            }
            $out[] = [
                'item_id'     => $item->id,
                'description' => $l['description'] ?? null,
                'quantity'    => $item->roundQty($qty),
                'unit_cost'   => round(max(0, (float) ($l['unit_cost'] ?? 0)), 4),
            ];
        }

        return $out;
    }

    protected function poLimit(): float
    {
        return (float) (CurrentTenant::get()?->setting('po_approval_threshold', 100000) ?? 100000);
    }
}
