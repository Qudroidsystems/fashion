<?php

namespace App\Services\Orders;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\MeasurementSession;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderMaterialRequirement;
use App\Models\OrderRevision;
use App\Models\OrderStatusEvent;
use App\Models\Quotation;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Inventory\StockLedger;
use App\Services\Numbering\DocumentNumber;
use App\Support\Orders\OrderStatus;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quotations and orders (SRS FR-ORD-001…003, FR-ORD-011, §6.2).
 */
class OrderService
{
    public function __construct(protected Pricing $pricing, protected StockLedger $ledger)
    {
    }

    /* ------------------------------ quotations ----------------------------- */

    public function saveQuotation(?Quotation $q, array $data, array $lines): Quotation
    {
        if ($q && ! $q->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Only draft or sent quotations can be changed.']);
        }
        $rows = $this->pricing->lines($lines);
        $totals = $this->pricing->totals($rows, (float) ($data['order_discount'] ?? 0));

        return DB::transaction(function () use ($q, $data, $rows, $totals) {
            $creating = ! $q;
            $q ??= new Quotation(['number' => DocumentNumber::next('quotation'), 'status' => 'draft', 'created_by' => auth()->id()]);
            $q->fill([
                'customer_id'     => $data['customer_id'] ?? $q->customer_id,
                'branch_id'       => $data['branch_id'] ?? $q->branch_id ?? $this->defaultBranch(),
                'title'           => $data['title'] ?? null,
                'event_date'      => $data['event_date'] ?? null,
                'valid_until'     => $data['valid_until'] ?? now()->addDays((int) CurrentTenant::get()->setting('quotation_valid_days', 14))->toDateString(),
                'deposit_percent' => $data['deposit_percent'] ?? null,
                'customer_notes'  => $data['customer_notes'] ?? null,
                'internal_notes'  => $data['internal_notes'] ?? null,
            ] + $totals)->save();

            $q->lines()->delete();
            foreach ($rows as $r) {
                $q->lines()->create(collect($r)->except(['id', 'measurement_session_id', 'wearer', 'assigned_to'])->all());
            }
            AuditLogger::log($creating ? 'quotation.created' : 'quotation.updated', $q, after: ['number' => $q->number, 'total' => $q->total]);

            return $q->fresh('lines');
        });
    }

    public function sendQuotation(Quotation $q): Quotation
    {
        if (! in_array($q->status, ['draft', 'sent'], true)) {
            throw ValidationException::withMessages(['status' => 'This quotation was already decided.']);
        }
        $q->update(['status' => 'sent', 'sent_at' => $q->sent_at ?? now()]);
        AuditLogger::log('quotation.sent', $q);

        return $q;
    }

    public function declineQuotation(Quotation $q, ?string $reason): Quotation
    {
        if (! in_array($q->status, ['draft', 'sent'], true)) {
            throw ValidationException::withMessages(['status' => 'This quotation was already decided.']);
        }
        $q->update(['status' => 'declined', 'decided_at' => now(), 'decline_reason' => $reason]);
        AuditLogger::log('quotation.declined', $q, description: $reason);

        return $q;
    }

    /** Customer accepted: create a confirmed order with the same lines and pinned style versions (§6.2 step 10). */
    public function acceptQuotation(Quotation $q, array $extra = []): Order
    {
        if (! in_array($q->status, ['draft', 'sent'], true)) {
            throw ValidationException::withMessages(['status' => 'This quotation was already decided.']);
        }

        return DB::transaction(function () use ($q, $extra) {
            $q = Quotation::lockForUpdate()->with('lines')->findOrFail($q->id);
            $order = Order::create([
                'number'         => DocumentNumber::next('order'),
                'customer_id'    => $q->customer_id,
                'branch_id'      => $q->branch_id,
                'quotation_id'   => $q->id,
                'status'         => 'draft',
                'title'          => $q->title,
                'event_date'     => $q->event_date,
                'promised_date'  => $extra['promised_date'] ?? null,
                'customer_notes' => $q->customer_notes,
                'internal_notes' => $q->internal_notes,
                'owner_id'       => auth()->id(),
                'created_by'     => auth()->id(),
            ] + $q->only(['subtotal', 'discount_total', 'order_discount', 'total']));
            foreach ($q->lines as $l) {
                $order->lines()->create($l->copyable() + ['status' => 'pending']);
            }
            $order->update(['deposit_required' => $this->depositFor($order->total, $q->deposit_percent)]);
            $q->update(['status' => 'converted', 'decided_at' => now(), 'order_id' => $order->id]);
            AuditLogger::log('quotation.accepted', $q, after: ['order' => $order->number]);

            return $this->transition($order, 'confirmed', 'From quotation ' . $q->number);
        });
    }

    /* -------------------------------- orders ------------------------------- */

    public function saveOrder(?Order $order, array $data, array $lines, ?string $revisionReason = null): Order
    {
        if ($order && ! $order->isEditable()) {
            throw ValidationException::withMessages(['status' => 'This order can no longer be changed.']);
        }
        if ($order && $order->status !== 'draft' && ! trim((string) $revisionReason)) {
            throw ValidationException::withMessages(['revision_reason' => 'Say why the confirmed order is changing — it is kept in the order history.']);
        }

        // Existing lines keep the style version they were priced on.
        $keep = [];
        if ($order) {
            $existing = $order->lines()->get()->keyBy('id');
            foreach ($lines as $l) {
                $old = $existing->get((int) ($l['id'] ?? 0));
                if ($old && $old->style_version_id && (int) ($l['style_id'] ?? 0) === $old->style_id) {
                    $keep[$old->id] = $old->style_version_id;
                }
            }
        }
        $rows = $this->pricing->lines($lines, null, $keep);
        $totals = $this->pricing->totals($rows, (float) ($data['order_discount'] ?? 0));
        $customerId = (int) ($data['customer_id'] ?? $order?->customer_id);
        $this->assertSessions($rows, $customerId);

        return DB::transaction(function () use ($order, $data, $rows, $totals, $revisionReason, $customerId) {
            $creating = ! $order;
            if ($order && $order->status !== 'draft') {
                $order->load('lines');
                $order->increment('revision');
                OrderRevision::create([
                    'order_id' => $order->id, 'revision' => $order->revision, 'reason' => $revisionReason,
                    'total_before' => $order->total, 'total_after' => $totals['total'],
                    'lines_before' => $order->lines->map(fn ($l) => $l->only(['description', 'quantity', 'unit_price', 'discount', 'line_total', 'style_version_id']))->all(),
                    'user_id' => auth()->id(),
                ]);
            }

            $order ??= new Order(['number' => DocumentNumber::next('order'), 'status' => 'draft', 'created_by' => auth()->id(), 'owner_id' => auth()->id()]);
            $order->fill([
                'customer_id'    => $customerId,
                'branch_id'      => $data['branch_id'] ?? $order->branch_id ?? $this->defaultBranch(),
                'title'          => $data['title'] ?? $order->title,
                'event_date'     => $data['event_date'] ?? null,
                'promised_date'  => $data['promised_date'] ?? null,
                'priority'       => $data['priority'] ?? $order->priority ?? 'normal',
                'customer_notes' => $data['customer_notes'] ?? null,
                'internal_notes' => $data['internal_notes'] ?? null,
                'owner_id'       => $data['owner_id'] ?? $order->owner_id,
            ] + $totals);
            $order->deposit_required = array_key_exists('deposit_required', $data) && $data['deposit_required'] !== null && $data['deposit_required'] !== ''
                ? min($totals['total'], max(0, round((float) $data['deposit_required'], 2)))
                : $this->depositFor($totals['total']);
            $order->save();

            // Update lines in place (keeps material requirements and invoice links), add new, cancel/remove dropped.
            $keepIds = [];
            foreach ($rows as $r) {
                $line = ! empty($r['id']) ? $order->lines()->whereKey($r['id'])->first() : null;
                $attrs = collect($r)->except('id')->all();
                if ($line) {
                    $line->update($attrs);
                } else {
                    $line = $order->lines()->create($attrs + ['status' => 'pending']);
                }
                $keepIds[] = $line->id;
            }
            $order->lines()->whereNotIn('id', $keepIds)->get()->each(function (OrderLine $l) {
                $hasHistory = \App\Models\InvoiceLine::where('order_line_id', $l->id)->exists()
                    || OrderMaterialRequirement::where('order_line_id', $l->id)->exists();
                $hasHistory ? $l->update(['status' => 'cancelled', 'line_total' => 0, 'discount' => 0, 'unit_price' => 0]) : $l->delete();
            });

            AuditLogger::log($creating ? 'order.created' : ($order->status === 'draft' ? 'order.updated' : 'order.revised'), $order,
                after: ['number' => $order->number, 'total' => $order->total, 'revision' => $order->revision], description: $revisionReason);

            return $order->fresh('lines');
        });
    }

    /** Move an order along its life cycle (FR-ORD-003). */
    public function transition(Order $order, string $to, ?string $note = null, ?bool $customerVisible = null): Order
    {
        $from = $order->status;
        if (! OrderStatus::canMove($from, $to)) {
            throw ValidationException::withMessages(['status' => sprintf('An order cannot go from %s to %s.', OrderStatus::label($from), OrderStatus::label($to))]);
        }
        if ($to === 'confirmed' && ! $order->lines()->where('status', '!=', 'cancelled')->exists()) {
            throw ValidationException::withMessages(['status' => 'Add at least one garment before confirming.']);
        }
        if ($to === 'ready' && ($open = \App\Models\ProductionTask::where('order_id', $order->id)->open()->count())) {
            throw ValidationException::withMessages(['status' => "{$open} production task(s) are still open. Finish them on the production board first."]);
        }

        return DB::transaction(function () use ($order, $from, $to, $note, $customerVisible) {
            $changes = ['status' => $to];
            if ($to === 'confirmed' && ! $order->confirmed_at) {
                $changes['confirmed_at'] = now();
            }
            if ($to === 'delivered') {
                $changes['delivered_at'] = now();
                $order->lines()->whereNotIn('status', ['cancelled'])->update(['status' => 'delivered']);
            }
            if ($to === 'in_production') {
                $order->lines()->where('status', 'pending')->update(['status' => 'in_production']);
            }
            if ($to === 'ready') {
                $order->lines()->whereIn('status', ['pending', 'in_production'])->update(['status' => 'ready']);
            }
            $order->update($changes);
            $this->event($order, $from, $to, $note, $customerVisible);
            AuditLogger::log('order.status_changed', $order, ['status' => $from], ['status' => $to], description: $note);

            return $order;
        });
    }

    /**
     * Cancel (FR-ORD-011): reservations are released, lines cancelled. Material
     * already issued stays issued (its cost is shown on the order) and money
     * paid becomes the customer's credit to refund or move to another order.
     */
    public function cancel(Order $order, string $reason): Order
    {
        if (! in_array($order->status, OrderStatus::CANCELLABLE, true)) {
            throw ValidationException::withMessages(['status' => 'Delivered or closed orders cannot be cancelled.']);
        }

        return DB::transaction(function () use ($order, $reason) {
            $from = $order->status;
            StockReservation::where('order_id', $order->id)->where('status', 'active')->get()->each(fn ($r) => $this->ledger->release($r));
            app(\App\Services\Production\ProductionService::class)->cancelOpenWork($order, $reason);
            $order->lines()->where('status', '!=', 'delivered')->update(['status' => 'cancelled']);
            $order->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            $this->event($order, $from, 'cancelled', $reason, true);
            AuditLogger::log('order.cancelled', $order, ['status' => $from], ['status' => 'cancelled', 'paid' => $order->paid()], description: $reason);

            return $order;
        });
    }

    public function reopen(Order $order, string $reason): Order
    {
        $to = OrderStatus::REOPEN[$order->status] ?? null;
        if (! $to) {
            throw ValidationException::withMessages(['status' => 'Only cancelled or closed orders can be reopened.']);
        }

        return DB::transaction(function () use ($order, $to, $reason) {
            $from = $order->status;
            if ($from === 'cancelled') {
                $order->lines()->where('status', 'cancelled')->where('line_total', '>', 0)->update(['status' => 'pending']);
            }
            $order->update(['status' => $to, 'cancelled_at' => null, 'cancel_reason' => null]);
            $this->event($order, $from, $to, 'Reopened: ' . $reason, false);
            AuditLogger::log('order.reopened', $order, ['status' => $from], ['status' => $to], description: $reason);

            return $order;
        });
    }

    public function updateLine(OrderLine $line, array $data): OrderLine
    {
        $order = $line->order;
        if (isset($data['measurement_session_id']) && $data['measurement_session_id']) {
            $this->assertSessions([['measurement_session_id' => $data['measurement_session_id'], 'participant_id' => $line->participant_id]], $order->customer_id);
        }
        $before = $line->only(array_keys($data));
        $line->update($data);
        AuditLogger::log('order.line_updated', $order, $before, $line->only(array_keys($data)), description: $line->description);

        return $line;
    }

    /* ------------------------------ materials ------------------------------ */

    /** Add a material need and reserve it (§6.2 step 12). */
    public function addMaterial(Order $order, InventoryItem $item, float $qty, int $branchId, ?int $lineId = null, ?string $note = null, bool $allowShort = false): OrderMaterialRequirement
    {
        if (! in_array($order->status, ['draft', ...OrderStatus::OPEN], true)) {
            throw ValidationException::withMessages(['status' => 'Materials can only be planned for open orders.']);
        }

        return DB::transaction(function () use ($order, $item, $qty, $branchId, $lineId, $note, $allowShort) {
            $reservation = $this->ledger->reserve($item, $branchId, $qty, [
                'customer_id' => $order->customer_id, 'order_id' => $order->id,
                'reference' => $order->number . ($order->title ? ' — ' . $order->title : ''), 'needed_by' => $order->promised_date,
                'allow_short' => $allowShort,
            ]);
            $req = OrderMaterialRequirement::create([
                'order_id' => $order->id, 'order_line_id' => $lineId ?: null, 'item_id' => $item->id,
                'quantity' => $item->roundQty($qty), 'reservation_id' => $reservation->id, 'note' => $note,
            ]);
            AuditLogger::log('order.material_reserved', $order, after: ['item' => $item->code, 'quantity' => $qty]);

            return $req;
        });
    }

    public function removeMaterial(OrderMaterialRequirement $req): void
    {
        DB::transaction(function () use ($req) {
            if ($req->reservation && $req->reservation->status === 'active') {
                $this->ledger->release($req->reservation);
            }
            $req->delete();
        });
    }

    /* ------------------------------ helpers -------------------------------- */

    /** The user's branch, else the house's main branch. */
    protected function defaultBranch(): ?int
    {
        return CurrentTenant::membership()?->branch_id ?? \App\Models\Branch::where('is_main', true)->value('id');
    }

    public function depositFor(float $total, ?float $percent = null): float
    {
        $percent ??= (float) (CurrentTenant::get()?->setting('deposit_percent', 50) ?? 50);

        return round($total * max(0, min(100, $percent)) / 100, 2);
    }

    protected function event(Order $order, ?string $from, string $to, ?string $note, ?bool $visible): void
    {
        OrderStatusEvent::create([
            'order_id' => $order->id, 'from_status' => $from, 'to_status' => $to, 'note' => $note,
            'customer_visible' => $visible ?? OrderStatus::customerVisible($to), 'user_id' => auth()->id(),
        ]);
        \App\Services\Messaging\CustomerUpdates::safely(fn ($u) => $u->orderStatusChanged($order, $to));
    }

    /** A garment can only use approved measurements of this order's customer (§6.1 step 7). */
    /**
     * Only approved measurements of the person wearing it: the order's customer,
     * or — on a project order — the participant the garment is for.
     */
    protected function assertSessions(array $rows, int $customerId): void
    {
        $rows = collect($rows)->filter(fn ($r) => ! empty($r['measurement_session_id']));
        if ($rows->isEmpty()) {
            return;
        }
        $wearers = \App\Models\ProjectParticipant::whereIn('id', $rows->pluck('participant_id')->filter())->pluck('customer_id', 'id');
        foreach ($rows as $r) {
            $allowed = array_filter([$customerId, $wearers[$r['participant_id'] ?? 0] ?? null]);
            $ok = MeasurementSession::whereKey($r['measurement_session_id'])->whereIn('customer_id', $allowed)
                ->whereIn('status', ['approved', 'superseded'])->exists();
            if (! $ok) {
                throw ValidationException::withMessages(['lines' => 'Link only approved measurements of the person wearing the garment.']);
            }
        }
    }
}
