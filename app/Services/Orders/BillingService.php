<?php

namespace App\Services\Orders;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Refund;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Inventory\Approvals;
use App\Services\Numbering\DocumentNumber;
use App\Support\Money;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invoices, payments, allocations, refunds and reversals (SRS FR-ORD-006…010, scenario 37).
 *
 * Balances are derived, never stored:
 *   order balance   = order total (0 if draft/cancelled) − net allocations to the order and its invoices
 *   invoice balance = invoice total − net allocations to the invoice
 *   customer credit = payment money not applied anywhere (and not refunded)
 * Allocations are append-only; refunds and reversals add negative allocations.
 */
class BillingService
{
    /* ------------------------------- invoices ------------------------------ */

    /**
     * Draft an invoice for an order. $kind: deposit | balance | full.
     * $amount overrides the suggested amount for deposit/balance.
     */
    public function invoiceOrder(Order $order, string $kind, ?float $amount = null, array $extra = []): Invoice
    {
        if (in_array($order->status, ['draft', 'cancelled'], true)) {
            throw ValidationException::withMessages(['invoice' => 'Confirm the order before invoicing it.']);
        }

        return DB::transaction(function () use ($order, $kind, $amount, $extra) {
            $order->load('lines');
            $outstanding = $this->unbilled($order);

            $lines = [];
            if ($kind === 'full') {
                foreach ($order->lines->where('status', '!=', 'cancelled') as $l) {
                    $lines[] = ['order_line_id' => $l->id, 'description' => $l->description, 'quantity' => $l->quantity,
                        'unit_price' => $l->quantity ? round($l->line_total / $l->quantity, 2) : $l->line_total, 'line_total' => $l->line_total];
                }
                if ($order->order_discount > 0) {
                    $lines[] = ['description' => 'Discount', 'quantity' => 1, 'unit_price' => -$order->order_discount, 'line_total' => -$order->order_discount];
                }
                $paid = $order->paid();
                if ($paid > 0) {
                    $lines[] = ['description' => 'Less: already paid', 'quantity' => 1, 'unit_price' => -$paid, 'line_total' => -$paid];
                }
            } else {
                $suggested = $kind === 'deposit'
                    ? max(0, round($order->deposit_required - $order->paid(), 2))
                    : $outstanding;
                $value = round($amount ?? $suggested, 2);
                $lines[] = ['description' => ($kind === 'deposit' ? 'Deposit' : 'Balance') . ' for order ' . $order->number . ($order->title ? ' — ' . $order->title : ''),
                    'quantity' => 1, 'unit_price' => $value, 'line_total' => $value];
            }

            $total = round(collect($lines)->sum('line_total'), 2);
            if ($total <= 0) {
                throw ValidationException::withMessages(['invoice' => 'Nothing left to invoice on this order.']);
            }
            if ($total > $outstanding + 0.005) {
                throw ValidationException::withMessages(['invoice' => sprintf('Only %s of this order is not yet paid or invoiced.', Money::format($outstanding))]);
            }

            $invoice = Invoice::create([
                'customer_id' => $order->customer_id, 'order_id' => $order->id, 'kind' => $kind, 'status' => 'draft',
                'due_date' => $extra['due_date'] ?? ($kind === 'deposit' ? now()->addDays(3)->toDateString() : ($order->promised_date?->toDateString() ?? now()->addDays(7)->toDateString())),
                'notes' => $extra['notes'] ?? null, 'total' => $total, 'created_by' => auth()->id(),
            ]);
            foreach ($lines as $l) {
                $invoice->lines()->create($l);
            }

            return $invoice->fresh('lines');
        });
    }

    /** A stand-alone invoice (alterations, fees…) — a charge in its own right. */
    public function invoiceCustomer(Customer $customer, array $lines, array $extra = []): Invoice
    {
        $rows = [];
        foreach ($lines as $l) {
            $qty = round((float) ($l['quantity'] ?? 1), 2);
            $price = round((float) ($l['unit_price'] ?? 0), 2);
            if (trim((string) ($l['description'] ?? '')) === '' || $qty <= 0) {
                continue;
            }
            $rows[] = ['description' => trim($l['description']), 'quantity' => $qty, 'unit_price' => $price, 'line_total' => round($qty * $price, 2)];
        }
        $total = round(collect($rows)->sum('line_total'), 2);
        if (! $rows || $total <= 0) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line with an amount.']);
        }

        return DB::transaction(function () use ($customer, $rows, $total, $extra) {
            $invoice = Invoice::create([
                'customer_id' => $customer->id, 'kind' => 'other', 'status' => 'draft', 'total' => $total,
                'due_date' => $extra['due_date'] ?? now()->addDays(7)->toDateString(), 'notes' => $extra['notes'] ?? null, 'created_by' => auth()->id(),
            ]);
            foreach ($rows as $r) {
                $invoice->lines()->create($r);
            }

            return $invoice->fresh('lines');
        });
    }

    public function issue(Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Already issued.']);
        }

        return DB::transaction(function () use ($invoice) {
            $invoice->update(['status' => 'issued', 'number' => DocumentNumber::next('invoice'), 'issue_date' => now(CurrentTenant::get()->timezone)->toDateString(), 'issued_at' => now()]);
            AuditLogger::log('invoice.issued', $invoice, after: ['number' => $invoice->number, 'total' => $invoice->total]);

            return $invoice;
        });
    }

    /** Void (FR-ORD-009). Money already paid on it moves to its order, or back to the customer's credit. */
    public function void(Invoice $invoice, string $reason): Invoice
    {
        if ($invoice->status !== 'issued') {
            throw ValidationException::withMessages(['status' => 'Only issued invoices can be voided.']);
        }

        return DB::transaction(function () use ($invoice, $reason) {
            $byPayment = PaymentAllocation::where('invoice_id', $invoice->id)->selectRaw('payment_id, sum(amount) amt')->groupBy('payment_id')->get();
            foreach ($byPayment as $row) {
                if ((float) $row->amt == 0.0) {
                    continue;
                }
                $this->allocationRow($row->payment_id, -(float) $row->amt, invoiceId: $invoice->id, note: 'Invoice ' . $invoice->number . ' voided');
                if ($invoice->order_id) {
                    $this->allocationRow($row->payment_id, (float) $row->amt, orderId: $invoice->order_id, note: 'Moved from voided invoice ' . $invoice->number);
                }
            }
            $invoice->update(['status' => 'void', 'void_reason' => $reason, 'voided_at' => now()]);
            AuditLogger::log('invoice.voided', $invoice, description: $reason);

            return $invoice;
        });
    }

    /** What is left to bill on an order: its balance less what open invoices already ask for. */
    public function unbilled(Order $order): float
    {
        $openInvoices = Invoice::where('order_id', $order->id)->where('status', 'issued')->get()->sum(fn ($i) => max(0, $i->balance()));
        $drafts = (float) Invoice::where('order_id', $order->id)->where('status', 'draft')->sum('total');

        return max(0, round($order->balance() - $openInvoices - $drafts, 2));
    }

    /* ------------------------------- payments ------------------------------ */

    /**
     * Record money received. $data: customer_id, amount, method, reference, received_at, notes, branch_id,
     * order_id | invoice_id (apply to), confirm_duplicate.
     * Applies to the chosen order / invoice; anything left stays as the customer's credit.
     */
    public function recordPayment(array $data): Payment
    {
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount received.']);
        }
        $ref = trim((string) ($data['reference'] ?? '')) ?: null;
        if ($ref && empty($data['confirm_duplicate'])
            && ($dupe = Payment::where('method', $data['method'])->where('reference', $ref)->where('status', 'confirmed')->first())) {
            throw ValidationException::withMessages(['reference' => sprintf(
                'Reference %s was already recorded on receipt %s (%s). Tick “record anyway” if this really is a second payment.', $ref, $dupe->number, Money::format($dupe->amount)
            )]);
        }

        return DB::transaction(function () use ($data, $amount, $ref) {
            $payment = Payment::create([
                'number'      => DocumentNumber::next('receipt'),
                'customer_id' => $data['customer_id'],
                'branch_id'   => $data['branch_id'] ?? CurrentTenant::membership()?->branch_id ?? \App\Models\Branch::where('is_main', true)->value('id'),
                'amount'      => $amount,
                'method'      => $data['method'],
                'reference'   => $ref,
                'received_at' => $data['received_at'] ?? now(),
                'notes'       => $data['notes'] ?? null,
                'received_by' => auth()->id(),
                'status'      => 'confirmed',
                'sale_id'     => $data['sale_id'] ?? null,
                'channel'     => $data['channel'] ?? 'counter',
            ]);

            if (! empty($data['invoice_id'])) {
                $this->applyToInvoice($payment, Invoice::findOrFail($data['invoice_id']), $amount);
            } elseif (! empty($data['order_id'])) {
                $this->applyToOrder($payment, Order::findOrFail($data['order_id']), $amount);
            } elseif (! empty($data['auto_apply'])) {
                $this->autoApply($payment);
            }
            AuditLogger::log('payment.recorded', $payment, after: ['number' => $payment->number, 'amount' => $amount, 'method' => $payment->method, 'applied' => $payment->allocated()]);

            return $payment->fresh();
        });
    }

    /** Apply credit from a payment to an order or invoice. */
    public function allocate(Payment $payment, ?Order $order, ?Invoice $invoice, ?float $amount = null): Payment
    {
        if ($payment->status !== 'confirmed') {
            throw ValidationException::withMessages(['payment' => 'This payment was reversed.']);
        }

        return DB::transaction(function () use ($payment, $order, $invoice, $amount) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            $free = $payment->unallocated();
            $amount = round(min($amount ?? $free, $free), 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Nothing left on this payment to apply.']);
            }
            if ($payment->customer_id !== ($invoice?->customer_id ?? $order?->customer_id)) {
                throw ValidationException::withMessages(['payment' => 'That belongs to another customer.']);
            }
            $applied = $invoice ? $this->applyToInvoice($payment, $invoice, $amount) : $this->applyToOrder($payment, $order, $amount);
            if ($applied <= 0) {
                throw ValidationException::withMessages(['amount' => 'That order or invoice has nothing left to pay.']);
            }
            AuditLogger::log('payment.allocated', $payment, after: ['amount' => $applied, 'order' => $order?->number, 'invoice' => $invoice?->number]);

            return $payment->fresh();
        });
    }

    /** A payment bounced or was recorded in error: take back everything it paid (FR-ORD-009). */
    public function reverse(Payment $payment, string $reason, User $by): Payment
    {
        if ($payment->status !== 'confirmed') {
            throw ValidationException::withMessages(['payment' => 'Already reversed.']);
        }
        if ($payment->refunded() > 0) {
            throw ValidationException::withMessages(['payment' => 'Money from this payment was already refunded; reverse is not possible.']);
        }

        return DB::transaction(function () use ($payment, $reason, $by) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            $rows = PaymentAllocation::where('payment_id', $payment->id)
                ->selectRaw('order_id, invoice_id, sum(amount) amt')->groupBy('order_id', 'invoice_id')->get();
            foreach ($rows as $r) {
                if ((float) $r->amt != 0.0) {
                    $this->allocationRow($payment->id, -(float) $r->amt, $r->order_id, $r->invoice_id, note: 'Payment reversed: ' . $reason);
                }
            }
            $payment->refunds()->where('status', 'pending_approval')->update(['status' => 'rejected', 'rejection_reason' => 'Payment reversed']);
            $payment->update(['status' => 'reversed', 'reversed_at' => now(), 'reversed_by' => $by->id, 'reversal_reason' => $reason]);
            AuditLogger::log('payment.reversed', $payment, after: ['amount' => $payment->amount], description: $reason);

            return $payment;
        });
    }

    /* ------------------------------- refunds ------------------------------- */

    /** Ask to give money back (scenario 37: never more than is refundable). */
    public function requestRefund(Payment $payment, array $data): Refund
    {
        if ($payment->status !== 'confirmed') {
            throw ValidationException::withMessages(['payment' => 'This payment was reversed.']);
        }
        $amount = round((float) $data['amount'], 2);
        $orderId = $data['order_id'] ?? null;
        $invoiceId = $data['invoice_id'] ?? null;
        $max = $this->refundable($payment, $orderId, $invoiceId);
        if ($amount <= 0 || $amount > $max + 0.005) {
            throw ValidationException::withMessages(['amount' => sprintf('You can refund at most %s from this payment%s.', Money::format($max), $orderId || $invoiceId ? ' for that order' : ' (its unapplied credit)')]);
        }

        $refund = Refund::create([
            'payment_id' => $payment->id, 'order_id' => $orderId, 'invoice_id' => $invoiceId, 'amount' => $amount,
            'method' => $data['method'] ?? $payment->method, 'reason' => $data['reason'], 'reference' => $data['reference'] ?? null,
            'status' => 'pending_approval', 'requested_by' => auth()->id(),
        ]);
        AuditLogger::log('refund.requested', $payment, after: ['amount' => $amount], description: $data['reason']);

        return $refund;
    }

    public function approveRefund(Refund $refund, User $approver): Refund
    {
        if ($refund->status !== 'pending_approval') {
            throw ValidationException::withMessages(['status' => 'This refund was already handled.']);
        }
        $limit = (float) (CurrentTenant::get()?->setting('refund_approval_threshold', 0) ?? 0);
        if (! Approvals::allowed($approver, $refund->requested_by, $refund->amount, $limit, 'Approve refund')) {
            throw ValidationException::withMessages(['approval' => 'Someone other than the person who asked for this refund must approve it.']);
        }

        return DB::transaction(function () use ($refund, $approver) {
            $refund = Refund::lockForUpdate()->findOrFail($refund->id);
            $payment = Payment::lockForUpdate()->findOrFail($refund->payment_id);
            // Re-check: other refunds may have been paid since the request.
            $max = $this->refundable($payment, $refund->order_id, $refund->invoice_id, $refund->id);
            if ($refund->amount > $max + 0.005) {
                throw ValidationException::withMessages(['amount' => 'Only ' . Money::format($max) . ' can still be refunded from this payment.']);
            }
            if ($refund->order_id || $refund->invoice_id) {
                $this->allocationRow($payment->id, -$refund->amount, $refund->order_id, $refund->invoice_id, $refund->id, 'Refund: ' . $refund->reason);
            }
            $refund->update(['status' => 'paid', 'approved_by' => $approver->id, 'approved_at' => now()]);
            $self = $refund->requested_by === $approver->id;
            AuditLogger::log('refund.paid', $payment, after: ['amount' => $refund->amount, 'self_approved' => $self], description: $refund->reason);

            return $refund;
        });
    }

    public function rejectRefund(Refund $refund, User $by, string $reason): Refund
    {
        if ($refund->status !== 'pending_approval') {
            throw ValidationException::withMessages(['status' => 'This refund was already handled.']);
        }
        $refund->update(['status' => 'rejected', 'approved_by' => $by->id, 'approved_at' => now(), 'rejection_reason' => $reason]);
        AuditLogger::log('refund.rejected', $refund->payment, description: $reason);

        return $refund;
    }

    /** Most that can still be refunded from a payment, for a target or from its credit. Pending refunds count against it. */
    public function refundable(Payment $payment, ?int $orderId = null, ?int $invoiceId = null, ?int $ignoreRefundId = null): float
    {
        $pending = $payment->refunds()->where('status', 'pending_approval')->when($ignoreRefundId, fn ($q) => $q->whereKeyNot($ignoreRefundId));
        if ($orderId || $invoiceId) {
            // For an order, money applied through its invoices counts too.
            $applied = (float) PaymentAllocation::where('payment_id', $payment->id)
                ->when($orderId, fn ($q) => $q->where(fn ($w) => $w->where('order_id', $orderId)
                    ->orWhereIn('invoice_id', Invoice::where('order_id', $orderId)->select('id'))))
                ->when(! $orderId && $invoiceId, fn ($q) => $q->where('invoice_id', $invoiceId))->sum('amount');
            $pendingHere = (float) (clone $pending)->where('order_id', $orderId)->where('invoice_id', $invoiceId)->sum('amount');

            return max(0, round($applied - $pendingHere, 2));
        }
        $pendingCredit = (float) (clone $pending)->whereNull('order_id')->whereNull('invoice_id')->sum('amount');

        return max(0, round($payment->unallocated() - $pendingCredit, 2));
    }

    /* ---------------------------- customer view ---------------------------- */

    /** owed (+) across open orders and stand-alone invoices, and unapplied credit. */
    public function customerSummary(Customer $customer): array
    {
        $orders = Order::where('customer_id', $customer->id)->whereNotIn('status', ['draft'])->get();
        $owedOrders = $orders->sum(fn ($o) => max(0, $o->balance()));
        $overpaid = $orders->sum(fn ($o) => max(0, -$o->balance()));
        $standalone = Invoice::where('customer_id', $customer->id)->whereNull('order_id')->where('status', 'issued')->get()->sum(fn ($i) => max(0, $i->balance()));
        $credit = Payment::where('customer_id', $customer->id)->where('status', 'confirmed')->get()->sum(fn ($p) => max(0, $p->unallocated()));

        return [
            'owed'     => round($owedOrders + $standalone, 2),
            'credit'   => round($credit + $overpaid, 2),
            'paid'     => round((float) Payment::where('customer_id', $customer->id)->where('status', 'confirmed')->sum('amount'), 2),
            'open'     => $orders->whereIn('status', \App\Support\Orders\OrderStatus::OPEN)->count(),
        ];
    }

    /* ------------------------------ internals ------------------------------ */

    /** Pay an order: its open invoices first (oldest), then the order itself. Returns amount applied. */
    protected function applyToOrder(Payment $payment, Order $order, float $amount): float
    {
        if ($order->customer_id !== $payment->customer_id) {
            throw ValidationException::withMessages(['order_id' => 'That order belongs to another customer.']);
        }
        $left = round(min($amount, max(0, $order->balance())), 2);
        $applied = 0.0;
        foreach (Invoice::where('order_id', $order->id)->where('status', 'issued')->orderBy('issue_date')->orderBy('id')->get() as $inv) {
            if ($left <= 0) {
                break;
            }
            $take = round(min($left, max(0, $inv->balance())), 2);
            if ($take > 0) {
                $this->allocationRow($payment->id, $take, invoiceId: $inv->id);
                $left -= $take;
                $applied += $take;
            }
        }
        if ($left > 0) {
            $this->allocationRow($payment->id, $left, orderId: $order->id);
            $applied += $left;
        }

        return round($applied, 2);
    }

    protected function applyToInvoice(Payment $payment, Invoice $invoice, float $amount): float
    {
        if ($invoice->customer_id !== $payment->customer_id || $invoice->status !== 'issued') {
            throw ValidationException::withMessages(['invoice_id' => 'Choose an issued invoice of this customer.']);
        }
        $take = round(min($amount, max(0, $invoice->balance())), 2);
        if ($take > 0) {
            $this->allocationRow($payment->id, $take, invoiceId: $invoice->id);
        }
        // Overpaying an order's invoice: the rest still goes to that order.
        if ($amount - $take > 0 && $invoice->order_id) {
            $take += $this->applyToOrder($payment, $invoice->order, round($amount - $take, 2));
        }

        return $take;
    }

    /** Oldest debts first: orders by promised/confirmed date, then stand-alone invoices. */
    protected function autoApply(Payment $payment): void
    {
        $left = $payment->amount;
        $orders = Order::where('customer_id', $payment->customer_id)->whereNotIn('status', ['draft', 'cancelled'])->orderBy('confirmed_at')->orderBy('id')->get();
        foreach ($orders as $o) {
            if ($left <= 0) {
                return;
            }
            $left -= $this->applyToOrder($payment, $o, $left);
        }
        foreach (Invoice::where('customer_id', $payment->customer_id)->whereNull('order_id')->where('status', 'issued')->orderBy('issue_date')->get() as $inv) {
            if ($left <= 0) {
                return;
            }
            $left -= $this->applyToInvoice($payment, $inv, $left);
        }
    }

    protected function allocationRow(int $paymentId, float $amount, ?int $orderId = null, ?int $invoiceId = null, ?int $refundId = null, ?string $note = null): PaymentAllocation
    {
        return PaymentAllocation::create([
            'payment_id' => $paymentId, 'order_id' => $orderId, 'invoice_id' => $invoiceId, 'refund_id' => $refundId,
            'amount' => round($amount, 2), 'note' => $note, 'user_id' => auth()->id(),
        ]);
    }
}
