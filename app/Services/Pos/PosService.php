<?php

namespace App\Services\Pos;

use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Inventory\StockLedger;
use App\Services\Numbering\DocumentNumber;
use App\Services\Orders\BillingService;
use App\Support\Money;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Point of sale (SRS FR-POS-001…006, §6.3).
 *
 * completeSale() writes the sale, its tenders, its stock movements and any
 * order payments in ONE transaction, so either everything is recorded or
 * nothing is (FR-POS-005). A sale is identified by the client's `client_ref`:
 * sending the same sale again returns the first one instead of selling twice
 * (scenario 35).
 */
class PosService
{
    public function __construct(protected StockLedger $ledger, protected BillingService $billing)
    {
    }

    /* -------------------------------- shifts ------------------------------- */

    public function currentShift(User $user): ?CashShift
    {
        return CashShift::where('user_id', $user->id)->where('status', 'open')->latest('id')->first();
    }

    public function openShift(User $user, int $branchId, float $float, ?string $notes = null): CashShift
    {
        if ($this->currentShift($user)) {
            throw ValidationException::withMessages(['shift' => 'You already have an open shift. Close it first.']);
        }
        $shift = CashShift::create([
            'number' => DocumentNumber::next('cash_shift'), 'branch_id' => $branchId, 'user_id' => $user->id, 'status' => 'open',
            'opened_at' => now(), 'opening_float' => round(max(0, $float), 2), 'notes' => $notes,
        ]);
        AuditLogger::log('pos.shift_opened', $shift, after: ['float' => $shift->opening_float, 'branch_id' => $branchId]);

        return $shift;
    }

    public function cashMovement(CashShift $shift, string $type, float $amount, string $reason): CashMovement
    {
        $this->assertOpen($shift);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount.']);
        }
        if ($type === 'pay_out' && $amount > $this->summary($shift)['expected_cash'] + 0.005) {
            throw ValidationException::withMessages(['amount' => 'There is not that much cash in the drawer.']);
        }
        $m = CashMovement::create(['shift_id' => $shift->id, 'type' => $type, 'amount' => round($amount, 2), 'reason' => $reason, 'user_id' => auth()->id()]);
        AuditLogger::log('pos.cash_' . $type, $shift, after: ['amount' => $m->amount], description: $reason);

        return $m;
    }

    /** Takings and the cash that should be in the drawer (FR-POS-003). */
    public function summary(CashShift $shift): array
    {
        $sales = $shift->sales()->where('status', 'completed');
        $tenders = DB::table('sale_tenders')->join('sales', 'sales.id', '=', 'sale_tenders.sale_id')
            ->where('sales.shift_id', $shift->id)->where('sales.status', 'completed')
            ->selectRaw('sale_tenders.method, sum(sale_tenders.amount) total')->groupBy('sale_tenders.method')->pluck('total', 'method')
            ->map(fn ($v) => round((float) $v, 2));
        $refunds = SaleReturn::where('shift_id', $shift->id)->selectRaw('refund_method, sum(amount) total')->groupBy('refund_method')->pluck('total', 'refund_method')
            ->map(fn ($v) => round((float) $v, 2));
        $payIn = (float) $shift->movements()->where('type', 'pay_in')->sum('amount');
        $payOut = (float) $shift->movements()->where('type', 'pay_out')->sum('amount');

        $expected = round($shift->opening_float + ($tenders['cash'] ?? 0) - ($refunds['cash'] ?? 0) + $payIn - $payOut, 2);

        return [
            'sales_count'   => (clone $sales)->count(),
            'sales_total'   => round((float) (clone $sales)->sum('total'), 2),
            'voided_count'  => $shift->sales()->where('status', 'voided')->count(),
            'tenders'       => $tenders,
            'refunds'       => $refunds,
            'pay_in'        => round($payIn, 2),
            'pay_out'       => round($payOut, 2),
            'expected_cash' => $expected,
        ];
    }

    public function closeShift(CashShift $shift, float $counted, ?string $reason, User $by): CashShift
    {
        $this->assertOpen($shift);
        $expected = $this->summary($shift)['expected_cash'];
        $variance = round($counted - $expected, 2);
        if ($variance != 0.0 && ! trim((string) $reason)) {
            throw ValidationException::withMessages(['variance_reason' => sprintf(
                'The drawer is %s %s. Say why before closing.', $variance > 0 ? 'over by' : 'short by', Money::format(abs($variance))
            )]);
        }
        $shift->update([
            'status' => 'closed', 'closed_at' => now(), 'expected_cash' => $expected, 'counted_cash' => round($counted, 2),
            'variance' => $variance, 'variance_reason' => $reason, 'closed_by' => $by->id,
        ]);
        AuditLogger::log('pos.shift_closed', $shift, after: ['expected' => $expected, 'counted' => $counted, 'variance' => $variance], description: $reason);

        return $shift;
    }

    /* --------------------------------- sales ------------------------------- */

    /**
     * $data = [
     *   'client_ref'  => 'uuid from the till',
     *   'customer_id' => ?int,
     *   'lines'   => [['type' => 'product', 'variant_id' =>, 'quantity' =>, 'unit_price' => ?, 'discount' => ?],
     *                 ['type' => 'order', 'order_id' =>, 'amount' =>]],
     *   'tenders' => [['method' => 'cash', 'amount' => 20000], ['method' => 'card', 'amount' => 5000, 'reference' => '…']],
     *   'note' => ?string,
     * ]
     */
    public function completeSale(CashShift $shift, array $data, User $cashier): Sale
    {
        $ref = trim((string) ($data['client_ref'] ?? ''));
        if ($ref === '') {
            throw ValidationException::withMessages(['client_ref' => 'Missing sale reference.']);
        }
        if ($existing = Sale::where('client_ref', $ref)->first()) {
            return $existing;   // retried request: the sale already happened
        }
        $this->assertOpen($shift);
        if ($shift->user_id !== $cashier->id) {
            throw ValidationException::withMessages(['shift' => 'This is not your shift.']);
        }

        try {
            return DB::transaction(fn () => $this->postSale($shift, $data, $cashier, $ref));
        } catch (QueryException $e) {
            // Two identical requests raced: the unique client_ref let only one through.
            if ($existing = Sale::where('client_ref', $ref)->first()) {
                return $existing;
            }
            throw $e;
        }
    }

    protected function postSale(CashShift $shift, array $data, User $cashier, string $ref): Sale
    {
        $customer = ! empty($data['customer_id']) ? Customer::findOrFail($data['customer_id']) : null;
        [$lines, $sub, $disc] = $this->priceLines($data['lines'] ?? [], $cashier, $customer);
        $total = $sub - $disc;   // kobo
        if ($total <= 0 && ! collect($lines)->contains('kind', 'product')) {
            throw ValidationException::withMessages(['lines' => 'The sale is empty.']);
        }
        $customer ??= collect($lines)->firstWhere('kind', 'order_payment')['order']->customer ?? null;

        [$tenders, $tendered, $change] = $this->tenders($data['tenders'] ?? [], $total);

        $sale = Sale::create([
            'number' => DocumentNumber::next('sale'), 'client_ref' => $ref, 'shift_id' => $shift->id, 'branch_id' => $shift->branch_id,
            'customer_id' => $customer?->id, 'status' => 'completed', 'subtotal' => $sub / 100, 'discount_total' => $disc / 100,
            'total' => $total / 100, 'tendered' => $tendered / 100, 'change_given' => $change / 100, 'cashier_id' => $cashier->id,
            'note' => $data['note'] ?? null,
        ]);
        foreach ($tenders as $t) {
            $sale->tenders()->create($t);
        }
        $method = count(array_unique(array_column($tenders, 'method'))) === 1 ? $tenders[0]['method'] : 'cash';

        foreach ($lines as $l) {
            $row = collect($l)->except(['order', 'item'])->all();
            if ($l['kind'] === 'product' && $l['item']) {
                $move = $this->ledger->post($l['item'], $shift->branch_id, -$l['quantity'], 'sale', [
                    'source' => $sale, 'reference' => $sale->number, 'customer_id' => $customer?->id,
                ]);
                $row['unit_cost'] = $move->unit_cost;
            }
            if ($l['kind'] === 'order_payment') {
                $payment = $this->billing->recordPayment([
                    'customer_id' => $l['order']->customer_id, 'amount' => $l['line_total'], 'method' => $method,
                    'reference' => $sale->number, 'order_id' => $l['order']->id, 'branch_id' => $shift->branch_id,
                    'sale_id' => $sale->id, 'channel' => 'pos', 'confirm_duplicate' => true, 'notes' => count($tenders) > 1 ? 'Paid at the till with ' . implode(' + ', array_column($tenders, 'method')) : null,
                ]);
                $row['payment_id'] = $payment->id;
            }
            $sale->lines()->create($row);
        }

        AuditLogger::log('pos.sale_completed', $sale, after: ['number' => $sale->number, 'total' => $sale->total, 'tenders' => array_column($tenders, 'amount', 'method')]);

        return $sale->fresh(['lines', 'tenders']);
    }

    /** Void a sale in the same open shift (FR-POS-004): stock goes back, order payments are reversed. */
    public function voidSale(Sale $sale, string $reason, User $by): Sale
    {
        if ($sale->status !== 'completed') {
            throw ValidationException::withMessages(['sale' => 'This sale was already voided.']);
        }
        if (! $sale->shift->isOpen()) {
            throw ValidationException::withMessages(['sale' => 'The shift for this sale is closed. Use a return instead.']);
        }
        if ($sale->returns()->exists()) {
            throw ValidationException::withMessages(['sale' => 'Items from this sale were already returned. Return the rest instead.']);
        }

        return DB::transaction(function () use ($sale, $reason, $by) {
            $sale = Sale::lockForUpdate()->with('lines.item', 'shift')->findOrFail($sale->id);
            foreach ($sale->lines as $l) {
                if ($l->kind === 'product' && $l->item) {
                    $this->ledger->post($l->item, $sale->branch_id, $l->quantity, 'sale_return', [
                        'source' => $sale, 'reference' => $sale->number, 'unit_cost' => $l->unit_cost, 'reason' => 'Void: ' . $reason,
                    ]);
                }
                if ($l->kind === 'order_payment' && $l->payment_id && ($p = \App\Models\Payment::find($l->payment_id)) && $p->status === 'confirmed') {
                    $this->billing->reverse($p, 'POS sale ' . $sale->number . ' voided: ' . $reason, $by);
                }
            }
            $sale->update(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => $reason]);
            AuditLogger::log('pos.sale_voided', $sale, after: ['total' => $sale->total], description: $reason);

            return $sale;
        });
    }

    /**
     * Return goods (FR-POS-004). $quantities: [sale_line_id => qty]. Cash refunds come out
     * of the returning cashier's open shift; store credit becomes customer credit.
     */
    public function returnItems(Sale $sale, array $quantities, string $method, bool $restock, string $reason, User $by): SaleReturn
    {
        if ($sale->status !== 'completed') {
            throw ValidationException::withMessages(['sale' => 'Voided sales cannot be returned.']);
        }
        $shift = $this->currentShift($by);
        if ($method === 'cash' && ! $shift) {
            throw ValidationException::withMessages(['refund_method' => 'Open your shift to give a cash refund.']);
        }
        if ($method === 'store_credit' && ! $sale->customer_id) {
            throw ValidationException::withMessages(['refund_method' => 'Store credit needs a customer on the sale.']);
        }

        return DB::transaction(function () use ($sale, $quantities, $method, $restock, $reason, $by, $shift) {
            $sale = Sale::lockForUpdate()->findOrFail($sale->id);
            $lines = SaleLine::where('sale_id', $sale->id)->where('kind', 'product')->lockForUpdate()->get()->keyBy('id');
            $rows = [];
            $amount = 0;
            foreach ($quantities as $lineId => $qty) {
                $qty = round((float) $qty, 2);
                if ($qty <= 0) {
                    continue;
                }
                $line = $lines->get((int) $lineId) ?? throw ValidationException::withMessages(['lines' => 'That item is not on this sale.']);
                if ($qty > $line->returnable + 0.001) {
                    throw ValidationException::withMessages(['lines' => sprintf('Only %s of “%s” can still be returned.', rtrim(rtrim(number_format($line->returnable, 2), '0'), '.'), $line->description)]);
                }
                // The last unit takes whatever is left, so rounding never refunds more than was paid.
                $isLast = abs($qty - $line->returnable) < 0.001;
                $lineAmount = $isLast
                    ? round($line->line_total - round($line->returned_quantity * $line->net_unit, 2), 2)
                    : round($qty * $line->net_unit, 2);
                $rows[] = [$line, $qty, $lineAmount];
                $amount += (int) round($lineAmount * 100);
            }
            if (! $rows) {
                throw ValidationException::withMessages(['lines' => 'Choose what is being returned.']);
            }
            if ($method === 'cash' && $amount / 100 > $this->summary($shift)['expected_cash'] + 0.005) {
                throw ValidationException::withMessages(['refund_method' => 'There is not enough cash in your drawer for this refund.']);
            }

            $ret = SaleReturn::create([
                'number' => DocumentNumber::next('sale_return'), 'sale_id' => $sale->id, 'shift_id' => $shift?->id,
                'refund_method' => $method, 'amount' => $amount / 100, 'restock' => $restock, 'reason' => $reason, 'user_id' => $by->id,
            ]);
            foreach ($rows as [$line, $qty, $lineAmount]) {
                $ret->lines()->create(['sale_line_id' => $line->id, 'quantity' => $qty, 'amount' => $lineAmount]);
                $line->increment('returned_quantity', $qty);
                if ($restock && $line->item_id && ($item = InventoryItem::find($line->item_id))) {
                    $this->ledger->post($item, $sale->branch_id, $qty, 'sale_return', [
                        'source' => $sale, 'reference' => $ret->number, 'unit_cost' => $line->unit_cost, 'reason' => $reason,
                        'customer_id' => $sale->customer_id,
                    ]);
                }
            }
            if ($method === 'store_credit') {
                $this->billing->recordPayment([
                    'customer_id' => $sale->customer_id, 'amount' => $amount / 100, 'method' => 'other', 'channel' => 'pos',
                    'reference' => $ret->number, 'confirm_duplicate' => true, 'notes' => 'Store credit for returned items (' . $sale->number . ')',
                ]);
            }
            AuditLogger::log('pos.sale_returned', $sale, after: ['return' => $ret->number, 'amount' => $ret->amount, 'method' => $method, 'restock' => $restock], description: $reason);

            return $ret->fresh('lines');
        });
    }

    /* ------------------------------- helpers ------------------------------- */

    /** Server-side prices (FR-POS-001, §6.3 step 18). Returns [rows, subtotal kobo, discount kobo]. */
    protected function priceLines(array $input, User $cashier, ?Customer $customer): array
    {
        $variants = ProductVariant::with('product', 'stockItem')->whereIn('id', collect($input)->pluck('variant_id')->filter())->get()->keyBy('id');
        $rows = [];
        $sub = 0;
        $disc = 0;
        $canOverride = $cashier->can('Override price');

        foreach ($input as $l) {
            if (($l['type'] ?? 'product') === 'order') {
                $order = Order::find($l['order_id'] ?? 0);
                if (! $order || in_array($order->status, ['draft', 'cancelled'], true)) {
                    throw ValidationException::withMessages(['lines' => 'Choose a confirmed order to pay toward.']);
                }
                if ($customer && $order->customer_id !== $customer->id) {
                    throw ValidationException::withMessages(['lines' => "Order {$order->number} belongs to another customer."]);
                }
                $amt = (int) round(((float) ($l['amount'] ?? 0)) * 100);
                $balance = (int) round($order->balance() * 100);
                if ($amt <= 0 || $amt > $balance) {
                    throw ValidationException::withMessages(['lines' => sprintf('Order %s has %s left to pay.', $order->number, Money::format($balance / 100))]);
                }
                $rows[] = ['kind' => 'order_payment', 'order_id' => $order->id, 'order' => $order, 'item' => null,
                    'description' => 'Payment toward order ' . $order->number . ($order->title ? ' — ' . $order->title : ''),
                    'quantity' => 1, 'unit_price' => $amt / 100, 'list_price' => null, 'discount' => 0, 'line_total' => $amt / 100];
                $sub += $amt;

                continue;
            }

            $v = $variants->get((int) ($l['variant_id'] ?? 0));
            if (! $v || ! $v->is_active || $v->product->status !== 'active') {
                throw ValidationException::withMessages(['lines' => 'An item in the basket is no longer for sale.']);
            }
            $qty = round((float) ($l['quantity'] ?? 1), 2);
            if ($qty <= 0) {
                continue;
            }
            $list = (int) round((float) $v->price * 100);
            $unit = isset($l['unit_price']) && $l['unit_price'] !== '' && $l['unit_price'] !== null ? (int) round((float) $l['unit_price'] * 100) : $list;
            if ($unit < $list && ! $canOverride) {
                throw ValidationException::withMessages(['lines' => "Only staff allowed to override prices can sell {$v->product->name} below " . Money::format($list / 100) . '.']);
            }
            $gross = (int) round($unit * $qty);
            $d = min(max(0, (int) round(((float) ($l['discount'] ?? 0)) * 100)), $gross);
            $item = $v->product->track_stock ? $v->stockItem : null;
            $rows[] = ['kind' => 'product', 'product_variant_id' => $v->id, 'item_id' => $item?->id, 'item' => $item, 'order' => null,
                'description' => $v->product->name . ($v->label ? ' — ' . $v->label : ''), 'quantity' => $qty,
                'unit_price' => $unit / 100, 'list_price' => $list / 100, 'discount' => $d / 100, 'line_total' => ($gross - $d) / 100];
            $sub += $gross;
            $disc += $d;
        }

        // House discount limit applies to goods only (order payments are not discountable here).
        $goods = collect($rows)->where('kind', 'product')->sum(fn ($r) => (int) round($r['unit_price'] * 100 * $r['quantity']));
        $limit = (float) (CurrentTenant::get()?->setting('discount_limit_percent', 10) ?? 10);
        if ($goods > 0 && $disc / $goods * 100 > $limit + 0.0001 && ! $canOverride) {
            throw ValidationException::withMessages(['lines' => sprintf('Discounts of %.1f%% are above the %s%% limit.', $disc / $goods * 100, rtrim(rtrim(number_format($limit, 2), '0'), '.'))]);
        }
        if (! $rows) {
            throw ValidationException::withMessages(['lines' => 'The basket is empty.']);
        }

        return [$rows, $sub, $disc];
    }

    /** Validate tenders: non-cash can't exceed what is owed; change comes only from cash. */
    protected function tenders(array $input, int $total): array
    {
        $cash = 0;
        $other = [];
        $cashIn = 0;
        foreach ($input as $t) {
            $amt = (int) round(((float) ($t['amount'] ?? 0)) * 100);
            if ($amt <= 0) {
                continue;
            }
            $method = $t['method'] ?? 'cash';
            if (! array_key_exists($method, \App\Models\SaleTender::METHODS)) {
                throw ValidationException::withMessages(['tenders' => 'Unknown payment method.']);
            }
            if ($method === 'cash') {
                $cashIn += $amt;
            } else {
                $other[] = ['method' => $method, 'amount' => $amt, 'reference' => $t['reference'] ?? null];
            }
        }
        $nonCash = array_sum(array_column($other, 'amount'));
        if ($nonCash > $total) {
            throw ValidationException::withMessages(['tenders' => 'Card, transfer and mobile money can’t be more than the total — only cash gives change.']);
        }
        if ($nonCash + $cashIn < $total) {
            throw ValidationException::withMessages(['tenders' => sprintf('%s still to pay.', Money::format(($total - $nonCash - $cashIn) / 100))]);
        }
        $change = $nonCash + $cashIn - $total;
        $rows = array_map(fn ($o) => ['method' => $o['method'], 'amount' => $o['amount'] / 100, 'received' => null, 'reference' => $o['reference']], $other);
        if ($cashIn > 0) {
            array_unshift($rows, ['method' => 'cash', 'amount' => ($cashIn - $change) / 100, 'received' => $cashIn / 100, 'reference' => null]);
        }

        return [$rows, $nonCash + $cashIn, $change];
    }

    protected function assertOpen(CashShift $shift): void
    {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => 'This shift is closed.']);
        }
    }
}
