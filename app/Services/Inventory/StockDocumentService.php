<?php

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueLine;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\StockLot;
use App\Models\StockReservation;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Numbering\DocumentNumber;
use App\Support\Money;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transfers, adjustments / wastage / counts / opening balances, material issues
 * and returns from production (SRS FR-INV-006, FR-INV-010, scenarios 38 & 41).
 * Every posting goes through StockLedger.
 */
class StockDocumentService
{
    public function __construct(protected StockLedger $ledger, protected ItemService $items)
    {
    }

    /* ------------------------------ transfers ------------------------------ */

    /** $lines: [['item_id' =>, 'lot_id' => ?, 'quantity' =>], …] */
    public function transfer(int $fromBranchId, int $toBranchId, array $lines, ?string $notes = null): StockTransfer
    {
        if ($fromBranchId === $toBranchId) {
            throw ValidationException::withMessages(['to_branch_id' => 'Choose a different destination.']);
        }
        $lines = $this->lines($lines);

        return DB::transaction(function () use ($fromBranchId, $toBranchId, $lines, $notes) {
            $t = StockTransfer::create([
                'number' => DocumentNumber::next('stock_transfer'), 'from_branch_id' => $fromBranchId, 'to_branch_id' => $toBranchId,
                'notes' => $notes, 'created_by' => auth()->id(), 'transferred_at' => now(),
            ]);
            foreach ($lines as [$item, $lotId, $qty]) {
                $t->lines()->create(['item_id' => $item->id, 'lot_id' => $lotId, 'quantity' => $qty]);
                $out = $this->ledger->post($item, $fromBranchId, -$qty, 'transfer_out', ['lot_id' => $lotId, 'source' => $t, 'reason' => 'To ' . $t->toBranch->name]);
                $this->ledger->post($item, $toBranchId, $qty, 'transfer_in', ['lot_id' => $lotId, 'source' => $t, 'unit_cost' => $out->unit_cost, 'reason' => 'From ' . $t->fromBranch->name]);
            }
            AuditLogger::log('stock_transfer.posted', $t, after: ['number' => $t->number, 'lines' => count($lines)]);

            return $t->fresh('lines');
        });
    }

    /* -------------------- adjustments, wastage, counts --------------------- */

    /**
     * Save a draft. $lines per kind:
     *   adjustment:        quantity_change (signed)
     *   wastage:           quantity (positive, removed)
     *   opening / customer_material: quantity (positive) [+ unit_cost for opening] [+ lot_no]
     *   count:             counted_quantity (expected is snapshotted now)
     */
    public function saveAdjustment(?StockAdjustment $adj, array $data, array $lines): StockAdjustment
    {
        if ($adj && ! $adj->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Only drafts can be changed.']);
        }
        $kind = $adj?->kind ?? $data['kind'];
        if (! array_key_exists($kind, StockAdjustment::KINDS)) {
            throw ValidationException::withMessages(['kind' => 'Unknown adjustment type.']);
        }
        $branchId = (int) ($adj?->branch_id ?? $data['branch_id']);
        if ($kind === 'customer_material' && empty($data['customer_id'] ?? $adj?->customer_id)) {
            throw ValidationException::withMessages(['customer_id' => 'Choose whose material this is.']);
        }

        return DB::transaction(function () use ($adj, $data, $lines, $kind, $branchId) {
            $adj ??= StockAdjustment::create([
                'number' => DocumentNumber::next('stock_adjustment'), 'kind' => $kind, 'branch_id' => $branchId,
                'status' => 'draft', 'created_by' => auth()->id(),
            ]);
            $adj->update(['reason' => $data['reason'] ?? $adj->reason, 'customer_id' => $data['customer_id'] ?? $adj->customer_id]);

            $adj->lines()->delete();
            $value = 0.0;
            $items = InventoryItem::whereIn('id', collect($lines)->pluck('item_id')->filter())->get()->keyBy('id');
            foreach ($lines as $l) {
                $item = $items->get((int) ($l['item_id'] ?? 0));
                if (! $item) {
                    continue;
                }
                $this->assertItemFits($item, $kind, $adj);
                $lotId = $this->resolveLot($item, $l, $kind, $adj);

                $row = ['item_id' => $item->id, 'lot_id' => $lotId, 'note' => $l['note'] ?? null];
                if ($kind === 'count') {
                    if (($l['counted_quantity'] ?? '') === '' || $l['counted_quantity'] === null) {
                        continue;
                    }
                    $expected = $this->onHand($item, $branchId, $lotId);
                    $counted = $item->roundQty(max(0, (float) $l['counted_quantity']));
                    $row += ['expected_quantity' => $expected, 'counted_quantity' => $counted, 'quantity_change' => round($counted - $expected, 3)];
                } elseif ($kind === 'adjustment') {
                    $row['quantity_change'] = $item->roundQty($l['quantity_change'] ?? 0);
                } else {
                    $q = $item->roundQty(abs((float) ($l['quantity'] ?? 0)));
                    $row['quantity_change'] = $kind === 'wastage' ? -$q : $q;
                    if ($kind === 'opening') {
                        $row['unit_cost'] = round(max(0, (float) ($l['unit_cost'] ?? $item->average_cost)), 4);
                    }
                }
                if ($row['quantity_change'] == 0.0 && $kind !== 'count') {
                    continue;
                }
                $adj->lines()->create($row);
                $value += $row['quantity_change'] * ($row['unit_cost'] ?? ($item->isCustomerOwned() ? 0 : $item->average_cost));
            }

            if (! $adj->lines()->exists()) {
                throw ValidationException::withMessages(['lines' => 'Add at least one item with a quantity.']);
            }
            $adj->update(['value_change' => round($value, 2)]);

            return $adj->fresh('lines');
        });
    }

    public function submitAdjustment(StockAdjustment $adj, User $user): StockAdjustment
    {
        if ($adj->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Already submitted.']);
        }
        $adj->update(['status' => 'pending_approval', 'submitted_by' => $user->id, 'submitted_at' => now()]);
        AuditLogger::log('stock_adjustment.submitted', $adj, after: ['kind' => $adj->kind, 'value_change' => $adj->value_change]);

        if ($user->can('Approve stock adjustment') && Approvals::allowed($user, $user->id, $adj->value_change, $this->adjustmentLimit(), 'Approve stock adjustment')) {
            return $this->approveAdjustment($adj, $user);
        }

        return $adj;
    }

    public function approveAdjustment(StockAdjustment $adj, User $approver): StockAdjustment
    {
        if ($adj->status !== 'pending_approval') {
            throw ValidationException::withMessages(['status' => 'Only submitted adjustments can be approved.']);
        }
        if (! Approvals::allowed($approver, $adj->submitted_by, $adj->value_change, $this->adjustmentLimit(), 'Approve stock adjustment')) {
            throw ValidationException::withMessages(['approval' => sprintf(
                'This change is worth more than the %s limit, so someone other than %s must approve it.',
                Money::format($this->adjustmentLimit()), $adj->submitter?->name ?? 'the submitter'
            )]);
        }

        return DB::transaction(function () use ($adj, $approver) {
            $adj = StockAdjustment::lockForUpdate()->with('lines.item')->findOrFail($adj->id);
            if ($adj->status !== 'pending_approval') {
                throw ValidationException::withMessages(['status' => 'This adjustment was handled meanwhile.']);
            }
            $type = ['adjustment' => 'adjustment', 'wastage' => 'wastage', 'count' => 'count', 'opening' => 'opening', 'customer_material' => 'customer_material'][$adj->kind];

            foreach ($adj->lines as $line) {
                if ($line->quantity_change == 0.0) {
                    continue;
                }
                $this->ledger->post($line->item, $adj->branch_id, $line->quantity_change, $type, [
                    'lot_id' => $line->lot_id, 'unit_cost' => $line->unit_cost, 'source' => $adj,
                    'reason' => trim(($adj->reason ?? $adj->kind_label) . ($line->note ? ' — ' . $line->note : '')),
                    'customer_id' => $adj->customer_id,
                ]);
            }
            $adj->update(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now()]);
            $self = $adj->submitted_by === $approver->id && abs($adj->value_change) > $this->adjustmentLimit();
            AuditLogger::log('stock_adjustment.approved', $adj, after: ['number' => $adj->number, 'kind' => $adj->kind, 'value_change' => $adj->value_change, 'self_approved' => $self],
                description: $self ? 'Self-approved above the limit: no other approver in this house' : null);

            return $adj;
        });
    }

    public function rejectAdjustment(StockAdjustment $adj, User $approver, string $reason): StockAdjustment
    {
        if ($adj->status !== 'pending_approval') {
            throw ValidationException::withMessages(['status' => 'Only submitted adjustments can be rejected.']);
        }
        $adj->update(['status' => 'rejected', 'approved_by' => $approver->id, 'approved_at' => now(), 'rejection_reason' => $reason]);
        AuditLogger::log('stock_adjustment.rejected', $adj, description: $reason);

        return $adj;
    }

    /** Items and system quantities for a count sheet at a location. */
    public function countSheet(int $branchId, ?string $type = null, ?string $category = null): \Illuminate\Support\Collection
    {
        $items = InventoryItem::active()->when($type, fn ($q) => $q->where('type', $type))
            ->when($category, fn ($q) => $q->where('category', $category))->orderBy('name')->get();
        $balances = StockBalance::where('branch_id', $branchId)->whereIn('item_id', $items->pluck('id'))->with('lot')->get()->groupBy('item_id');

        return $items->flatMap(function ($item) use ($balances) {
            $rows = ($balances[$item->id] ?? collect())->filter(fn ($b) => $b->lot_key === 0 ? $b->on_hand != 0 || ! $item->track_lots : $b->on_hand != 0);
            if ($rows->isEmpty()) {
                return [['item' => $item, 'lot' => null, 'on_hand' => 0.0]];
            }

            return $rows->map(fn ($b) => ['item' => $item, 'lot' => $b->lot, 'on_hand' => $b->on_hand])->values();
        });
    }

    /* ------------------------ material issue & return ---------------------- */

    /**
     * Issue material to production for a job (scenario 38).
     * $lines: [['item_id' =>, 'lot_id' => ?, 'quantity' =>, 'reservation_id' => ?], …]
     */
    public function issue(array $data, array $lines): MaterialIssue
    {
        $lines = $this->lines($lines, keepExtra: true);
        $branchId = (int) $data['branch_id'];
        // Material for a project's order is that project's cost too (scenario 38, FR-PRJ-007).
        if (empty($data['project_id']) && ! empty($data['order_id'])) {
            $data['project_id'] = \App\Models\Order::whereKey($data['order_id'])->value('project_id');
        }

        return DB::transaction(function () use ($data, $lines, $branchId) {
            $issue = MaterialIssue::create([
                'number' => DocumentNumber::next('material_issue'), 'branch_id' => $branchId,
                'customer_id' => $data['customer_id'] ?? null, 'order_id' => $data['order_id'] ?? null,
                'order_line_id' => $data['order_line_id'] ?? null, 'task_id' => $data['task_id'] ?? null,
                'project_id' => $data['project_id'] ?? null, 'job_reference' => $data['job_reference'],
                'issued_to' => $data['issued_to'] ?? null, 'issued_by' => auth()->id(),
                'issued_at' => now(), 'notes' => $data['notes'] ?? null,
            ]);

            $total = 0.0;
            foreach ($lines as [$item, $lotId, $qty, $extra]) {
                if ($item->isCustomerOwned() && $issue->customer_id && $item->customer_id && $item->customer_id !== $issue->customer_id) {
                    throw ValidationException::withMessages(['lines' => "{$item->name} belongs to another customer."]);
                }
                $reservation = $this->matchReservation($item, $branchId, $issue, $extra['reservation_id'] ?? null);
                $covered = $reservation ? min($reservation->open_quantity, $qty) : 0;

                // Don't eat into stock set aside for other jobs (FR-INV-006/008).
                $free = $this->ledger->availableAt($item, $branchId);
                if ($qty - $covered > $free + 0.0005 && empty($data['allow_reserved'])) {
                    throw ValidationException::withMessages(['lines' => sprintf(
                        'Only %s of %s is free at %s; the rest is reserved for other jobs. Tick “use reserved stock” to issue it anyway.',
                        $item->qty(max(0, $free)), $item->name, $issue->branch->name
                    )]);
                }

                $move = $this->ledger->post($item, $branchId, -$qty, 'issue', [
                    'lot_id' => $lotId, 'source' => $issue, 'reason' => $issue->job_reference,
                    'customer_id' => $issue->customer_id, 'order_id' => $issue->order_id, 'project_id' => $issue->project_id,
                ]);
                if ($reservation && $covered > 0) {
                    $this->ledger->consume($reservation, $covered);
                }
                $issue->lines()->create([
                    'item_id' => $item->id, 'lot_id' => $lotId, 'reservation_id' => $reservation?->id,
                    'quantity' => $qty, 'unit_cost' => $move->unit_cost,
                ]);
                $total += $qty * $move->unit_cost;
            }
            $issue->update(['total_cost' => round($total, 2)]);
            AuditLogger::log('material_issue.posted', $issue, after: ['number' => $issue->number, 'job' => $issue->job_reference, 'total_cost' => $issue->total_cost]);

            return $issue->fresh('lines');
        });
    }

    /**
     * Unused material comes back from production. With $asRemnant the
     * returned pieces become a remnant lot (FR-INV-007) so they can be found and reused.
     * $returns: [material_issue_line_id => quantity]
     */
    public function returnFromProduction(MaterialIssue $issue, array $returns, bool $asRemnant = false, ?string $note = null): MaterialIssue
    {
        if (! collect($returns)->filter(fn ($q) => (float) $q > 0)->count()) {
            throw ValidationException::withMessages(['returns' => 'Enter a quantity to return.']);
        }

        return DB::transaction(function () use ($issue, $returns, $asRemnant, $note) {
            $n = 0;
            foreach ($returns as $lineId => $qty) {
                $qty = (float) $qty;
                if ($qty <= 0) {
                    continue;
                }
                $line = MaterialIssueLine::where('material_issue_id', $issue->id)->lockForUpdate()->findOrFail($lineId);
                $item = $line->item;
                $qty = $item->roundQty($qty);
                if ($qty > $line->net_quantity + 0.0005) {
                    throw ValidationException::withMessages(['returns' => sprintf('Only %s of %s is still out on this issue.', $item->qty($line->net_quantity), $item->name)]);
                }

                $lotId = $line->lot_id;
                if ($asRemnant) {
                    $lot = $this->items->lot($item, $issue->number . '-R' . (++$n), [
                        'kind' => 'remnant', 'parent_lot_id' => $line->lot_id, 'notes' => $note ?: 'Left over from ' . $issue->job_reference,
                    ]);
                    $lotId = $lot->id;
                }
                $this->ledger->post($item, $issue->branch_id, $qty, 'production_return', [
                    'lot_id' => $lotId, 'unit_cost' => $line->unit_cost, 'source' => $issue,
                    'reason' => ($asRemnant ? 'Remnant from ' : 'Returned from ') . $issue->job_reference,
                    'customer_id' => $issue->customer_id, 'order_id' => $issue->order_id,
                ]);
                $line->increment('returned_quantity', $qty);
            }
            $issue->load('lines');
            $issue->update(['total_cost' => round($issue->lines->sum(fn ($l) => $l->net_quantity * $l->unit_cost), 2)]);
            AuditLogger::log('material_issue.returned', $issue, description: $asRemnant ? 'Remnants returned to stock' : 'Material returned to stock');

            return $issue;
        });
    }

    /* ------------------------------ helpers -------------------------------- */

    /** Normalise posted lines into [item, lotId, qty, extra]; drops empty rows. */
    protected function lines(array $lines, bool $keepExtra = false): array
    {
        $items = InventoryItem::whereIn('id', collect($lines)->pluck('item_id')->filter())->get()->keyBy('id');
        $out = [];
        foreach ($lines as $l) {
            $item = $items->get((int) ($l['item_id'] ?? 0));
            $qty = $item ? $item->roundQty(abs((float) ($l['quantity'] ?? 0))) : 0;
            if (! $item || $qty <= 0) {
                continue;
            }
            $lotId = ! empty($l['lot_id']) ? StockLot::where('item_id', $item->id)->whereKey($l['lot_id'])->value('id') : null;
            if ($item->track_lots && ! $lotId) {
                throw ValidationException::withMessages(['lines' => "Choose which roll / lot of {$item->name}."]);
            }
            $out[] = $keepExtra ? [$item, $lotId, $qty, $l] : [$item, $lotId, $qty];
        }
        if (! $out) {
            throw ValidationException::withMessages(['lines' => 'Add at least one item with a quantity.']);
        }

        return $out;
    }

    protected function assertItemFits(InventoryItem $item, string $kind, StockAdjustment $adj): void
    {
        if ($kind === 'customer_material') {
            if (! $item->isCustomerOwned()) {
                throw ValidationException::withMessages(['lines' => "{$item->name} is not customer material."]);
            }
            if ($item->customer_id && $adj->customer_id && $item->customer_id !== $adj->customer_id) {
                throw ValidationException::withMessages(['lines' => "{$item->name} belongs to another customer."]);
            }
        } elseif ($kind === 'opening' && $item->isCustomerOwned()) {
            throw ValidationException::withMessages(['lines' => 'Record customer material as “customer material received”.']);
        }
    }

    protected function resolveLot(InventoryItem $item, array $l, string $kind, StockAdjustment $adj): ?int
    {
        if (in_array($kind, ['opening', 'customer_material'], true) && ! empty($l['lot_no'])) {
            return $this->items->lot($item, $l['lot_no'])?->id;
        }
        $lotId = ! empty($l['lot_id']) ? StockLot::where('item_id', $item->id)->whereKey($l['lot_id'])->value('id') : null;
        if ($item->track_lots && ! $lotId) {
            throw ValidationException::withMessages(['lines' => "Choose or enter the roll / lot for {$item->name}."]);
        }

        return $lotId;
    }

    protected function onHand(InventoryItem $item, int $branchId, ?int $lotId): float
    {
        return round((float) StockBalance::where(['item_id' => $item->id, 'branch_id' => $branchId, 'lot_key' => $lotId ?? 0])->value('on_hand'), 3);
    }

    /** The reservation an issue line draws from: the one chosen, else the customer's own active one for this item. */
    protected function matchReservation(InventoryItem $item, int $branchId, MaterialIssue $issue, $reservationId): ?StockReservation
    {
        $q = StockReservation::where('item_id', $item->id)->where('branch_id', $branchId)->where('status', 'active');
        if ($reservationId) {
            return (clone $q)->whereKey($reservationId)->first();
        }
        if ($issue->order_id) {
            return (clone $q)->where('order_id', $issue->order_id)->oldest()->first();
        }

        return $issue->customer_id ? (clone $q)->where('customer_id', $issue->customer_id)->oldest()->first() : null;
    }

    protected function adjustmentLimit(): float
    {
        return (float) (CurrentTenant::get()?->setting('stock_approval_threshold', 20000) ?? 20000);
    }
}
