<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FashionStyle;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Services\Orders\BillingService;
use App\Services\Orders\OrderService;
use App\Support\Orders\OrderStatus;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * SRS §8.2 Orders & finance:
 *   GET/POST /quotations · POST /quotations/{id}/accept
 *   GET/POST /orders · GET/PATCH /orders/{id} · POST /orders/{id}/cancel · POST /orders/{id}/status-events
 *   POST /invoices · POST /invoices/{id}/issue
 *   POST /payments · POST /payments/{id}/allocate · POST /payments/{id}/refund
 *
 * Lines reference catalogue items by public id: {"kind":"bespoke","style":"<style id>","options":["Hand beading"]},
 * {"kind":"ready_made","sku":"PRD-00001-M"}, {"kind":"service","description":"Hemming","unit_price":3000}.
 * Prices are always recalculated on the server. Money is hidden without "View payment".
 */
class OrderController extends Controller
{
    public function __construct(protected OrderService $orders, protected BillingService $billing)
    {
        $this->middleware('permission:Manage quotation', ['only' => ['quotations', 'storeQuotation', 'accept']]);
        $this->middleware('permission:View order', ['only' => ['index', 'show']]);
        $this->middleware('permission:Create order', ['only' => ['store', 'accept']]);
        $this->middleware('permission:Update order', ['only' => ['update', 'statusEvent']]);
        $this->middleware('permission:Cancel order', ['only' => ['cancel']]);
        $this->middleware('permission:Manage invoice', ['only' => ['storeInvoice', 'issueInvoice']]);
        $this->middleware('permission:Record payment', ['only' => ['storePayment', 'allocate', 'refund']]);
    }

    /* ------------------------------ quotations ----------------------------- */

    public function quotations(Request $request): JsonResponse
    {
        $page = Quotation::with('customer:id,public_id,first_name,last_name')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))->latest('id')->paginate((int) $request->input('per_page', 25));

        return $this->page($page, fn ($q) => $this->quoteData($q, false));
    }

    public function storeQuotation(Request $request): JsonResponse
    {
        $data = $this->validatedDoc($request, true);
        $q = $this->orders->saveQuotation(null, $data, $this->mapLines($data['lines']));
        if ($request->boolean('send')) {
            $this->orders->sendQuotation($q);
        }

        return response()->json(['success' => true, 'data' => $this->quoteData($q->fresh('lines', 'customer'), true)], 201);
    }

    public function accept(Request $request, Quotation $quotation): JsonResponse
    {
        $data = $request->validate(['promised_date' => 'nullable|date']);
        $order = $this->orders->acceptQuotation($quotation, $data);

        return response()->json(['success' => true, 'data' => $this->orderData($order->fresh(['lines', 'customer']), $request)], 201);
    }

    /* -------------------------------- orders ------------------------------- */

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => 'nullable|string', 'customer' => 'nullable|string', 'per_page' => 'nullable|integer|min:1|max:100']);
        $page = Order::with('customer:id,public_id,first_name,last_name')
            ->when($request->status === 'open', fn ($q) => $q->whereIn('status', OrderStatus::OPEN))
            ->when($request->status && $request->status !== 'open', fn ($q) => $q->where('status', $request->status))
            ->when($request->customer, fn ($q, $c) => $q->whereHas('customer', fn ($w) => $w->where('public_id', $c)))
            ->latest('id')->paginate((int) $request->input('per_page', 25));

        return $this->page($page, fn ($o) => $this->orderData($o, $request, false));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedDoc($request, true);
        $order = $this->orders->saveOrder(null, $data, $this->mapLines($data['lines']));
        if ($request->boolean('confirm')) {
            $order = $this->orders->transition($order, 'confirmed');
        }

        return response()->json(['success' => true, 'data' => $this->orderData($order->fresh(['lines', 'customer']), $request)], 201);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->orderData($order->load('lines', 'customer', 'events'), $request)]);
    }

    /** PATCH: header fields and/or a full replacement of lines (needs revision_reason once confirmed). */
    public function update(Request $request, Order $order): JsonResponse
    {
        $data = $this->validatedDoc($request, false);
        $lines = array_key_exists('lines', $data)
            ? $this->mapLines($data['lines'])
            : $order->lines()->where('status', '!=', 'cancelled')->get()->map(fn ($l) => $l->copyable() + ['id' => $l->id, 'options' => collect($l->options)->pluck('label')->all()])->all();
        $merged = $data + $order->only(['title', 'event_date', 'promised_date', 'priority', 'customer_notes', 'internal_notes', 'order_discount', 'deposit_required']);
        $merged['event_date'] = $merged['event_date'] instanceof \DateTimeInterface ? $merged['event_date']->format('Y-m-d') : $merged['event_date'];
        $merged['promised_date'] = $merged['promised_date'] instanceof \DateTimeInterface ? $merged['promised_date']->format('Y-m-d') : $merged['promised_date'];
        $order = $this->orders->saveOrder($order, $merged, $lines, $data['revision_reason'] ?? null);

        return response()->json(['success' => true, 'data' => $this->orderData($order->fresh(['lines', 'customer']), $request)]);
    }

    public function statusEvent(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(OrderStatus::STATUSES))], 'note' => 'nullable|string|max:500', 'customer_visible' => 'nullable|boolean']);
        $order = $this->orders->transition($order, $data['status'], $data['note'] ?? null, $request->has('customer_visible') ? $request->boolean('customer_visible') : null);

        return response()->json(['success' => true, 'data' => $this->orderData($order->fresh(['lines', 'customer', 'events']), $request)]);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $order = $this->orders->cancel($order, $data['reason']);

        return response()->json(['success' => true, 'data' => $this->orderData($order->fresh(['lines', 'customer']), $request)]);
    }

    /* ------------------------------- invoices ------------------------------ */

    public function storeInvoice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', Rule::exists('orders', 'public_id')->where('tenant_id', CurrentTenant::id())],
            'kind'     => 'required|in:deposit,balance,full',
            'amount'   => 'nullable|numeric|min:0.01',
            'due_date' => 'nullable|date',
            'issue'    => 'nullable|boolean',
        ]);
        $invoice = $this->billing->invoiceOrder(Order::where('public_id', $data['order_id'])->firstOrFail(), $data['kind'], isset($data['amount']) ? (float) $data['amount'] : null, $data);
        if ($request->boolean('issue')) {
            $invoice = $this->billing->issue($invoice);
        }

        return response()->json(['success' => true, 'data' => $this->invoiceData($invoice->fresh('lines'))], 201);
    }

    public function issueInvoice(Invoice $invoice): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->invoiceData($this->billing->issue($invoice)->fresh('lines'))]);
    }

    /* ------------------------------- payments ------------------------------ */

    public function storePayment(Request $request): JsonResponse
    {
        $tenant = CurrentTenant::id();
        $data = $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'public_id')->where('tenant_id', $tenant)],
            'amount'      => 'required|numeric|min:0.01',
            'method'      => ['required', Rule::in(array_keys(Payment::METHODS))],
            'reference'   => 'nullable|string|max:120',
            'received_at' => 'nullable|date|before_or_equal:now',
            'notes'       => 'nullable|string|max:2000',
            'order_id'    => ['nullable', Rule::exists('orders', 'public_id')->where('tenant_id', $tenant)],
            'invoice_id'  => ['nullable', Rule::exists('invoices', 'public_id')->where('tenant_id', $tenant)],
            'auto_apply'  => 'nullable|boolean',
            'confirm_duplicate' => 'nullable|boolean',
        ]);
        $data['customer_id'] = Customer::where('public_id', $data['customer_id'])->value('id');
        $data['order_id'] = ! empty($data['order_id']) ? Order::where('public_id', $data['order_id'])->value('id') : null;
        $data['invoice_id'] = ! empty($data['invoice_id']) ? Invoice::where('public_id', $data['invoice_id'])->value('id') : null;
        $payment = $this->billing->recordPayment($data);

        return response()->json(['success' => true, 'data' => $this->paymentData($payment)], 201);
    }

    public function allocate(Request $request, Payment $payment): JsonResponse
    {
        $tenant = CurrentTenant::id();
        $data = $request->validate([
            'order_id'   => ['nullable', 'required_without:invoice_id', Rule::exists('orders', 'public_id')->where('tenant_id', $tenant)],
            'invoice_id' => ['nullable', Rule::exists('invoices', 'public_id')->where('tenant_id', $tenant)],
            'amount'     => 'nullable|numeric|min:0.01',
        ]);
        $payment = $this->billing->allocate($payment,
            ! empty($data['order_id']) ? Order::where('public_id', $data['order_id'])->first() : null,
            ! empty($data['invoice_id']) ? Invoice::where('public_id', $data['invoice_id'])->first() : null,
            isset($data['amount']) ? (float) $data['amount'] : null);

        return response()->json(['success' => true, 'data' => $this->paymentData($payment)]);
    }

    /** Request a refund; it is paid when someone with "Approve refund" approves it (web). */
    public function refund(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate([
            'amount'   => 'required|numeric|min:0.01',
            'method'   => ['nullable', Rule::in(array_keys(Payment::METHODS))],
            'reason'   => 'required|string|max:255',
            'order_id' => ['nullable', Rule::exists('orders', 'public_id')->where('tenant_id', CurrentTenant::id())],
        ]);
        $data['order_id'] = ! empty($data['order_id']) ? Order::where('public_id', $data['order_id'])->value('id') : null;
        $refund = $this->billing->requestRefund($payment, $data);

        return response()->json(['success' => true, 'data' => ['id' => $refund->public_id, 'amount' => $refund->amount, 'status' => $refund->status]], 201);
    }

    /* ------------------------------- helpers ------------------------------- */

    protected function validatedDoc(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'customer_id'     => [$creating ? 'required' : 'prohibited', Rule::exists('customers', 'public_id')->where('tenant_id', CurrentTenant::id())],
            'title'           => 'nullable|string|max:150',
            'event_date'      => 'nullable|date',
            'promised_date'   => 'nullable|date',
            'valid_until'     => 'nullable|date',
            'priority'        => 'nullable|in:normal,rush',
            'order_discount'  => 'nullable|numeric|min:0',
            'deposit_required'=> 'nullable|numeric|min:0',
            'deposit_percent' => 'nullable|numeric|min:0|max:100',
            'customer_notes'  => 'nullable|string|max:2000',
            'internal_notes'  => 'nullable|string|max:2000',
            'revision_reason' => 'nullable|string|max:255',
            'lines'           => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'lines.*.id'      => 'nullable|integer',
            'lines.*.kind'    => 'required_with:lines|in:bespoke,ready_made,service,charge',
            'lines.*.style'   => 'nullable|string',
            'lines.*.sku'     => 'nullable|string',
            'lines.*.options' => 'nullable|array',
            'lines.*.description' => 'nullable|string|max:255',
            'lines.*.instructions' => 'nullable|string|max:2000',
            'lines.*.quantity'=> 'nullable|numeric|min:0.01',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.discount'=> 'nullable|numeric|min:0',
            'send'            => 'nullable|boolean',
            'confirm'         => 'nullable|boolean',
        ]);
        if (isset($data['customer_id'])) {
            $data['customer_id'] = Customer::where('public_id', $data['customer_id'])->value('id');
        }

        return $data;
    }

    /** Public references → internal ids for Pricing. */
    protected function mapLines(array $lines): array
    {
        return collect($lines)->map(function ($l, $i) {
            if (! empty($l['style'])) {
                $l['style_id'] = FashionStyle::where('public_id', $l['style'])->orWhere('code', $l['style'])->value('id')
                    ?? throw ValidationException::withMessages(['lines' => "Unknown style on line " . ($i + 1) . '.']);
            }
            if (! empty($l['sku'])) {
                $l['product_variant_id'] = ProductVariant::where('sku', $l['sku'])->orWhere('barcode', $l['sku'])->value('id')
                    ?? throw ValidationException::withMessages(['lines' => "Unknown SKU on line " . ($i + 1) . '.']);
            }

            return $l;
        })->all();
    }

    protected function page($page, callable $map): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $page->getCollection()->map($map),
            'meta'    => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    protected function lineData($l, bool $money): array
    {
        return [
            'id' => $l->id, 'kind' => $l->kind, 'description' => $l->description, 'quantity' => $l->quantity,
            'style_version_id' => $l->style_version_id, 'options' => collect($l->options)->pluck('label'),
            'instructions' => $l->instructions, 'status' => $l->status ?? null,
            'measurement_session_id' => $l->measurementSession?->public_id ?? null,
        ] + ($money ? ['unit_price' => $l->unit_price, 'discount' => $l->discount, 'line_total' => $l->line_total] : []);
    }

    protected function orderData(Order $o, Request $request, bool $full = true): array
    {
        $money = $request->user()->can('View payment');
        $out = [
            'id' => $o->public_id, 'number' => $o->number, 'status' => $o->status, 'status_label' => $o->status_label,
            'customer' => ['id' => $o->customer?->public_id, 'name' => $o->customer?->name],
            'title' => $o->title, 'event_date' => $o->event_date?->toDateString(), 'promised_date' => $o->promised_date?->toDateString(),
            'priority' => $o->priority, 'revision' => $o->revision,
        ];
        if ($money) {
            $out += ['subtotal' => $o->subtotal, 'discount_total' => $o->discount_total, 'total' => $o->total,
                'deposit_required' => $o->deposit_required, 'paid' => $o->paid(), 'balance' => $o->balance()];
        }
        if ($full) {
            $out['lines'] = $o->lines->map(fn ($l) => $this->lineData($l, $money))->values();
            if ($o->relationLoaded('events')) {
                $out['events'] = $o->events->map(fn ($e) => ['status' => $e->to_status, 'note' => $e->note, 'customer_visible' => $e->customer_visible, 'at' => $e->created_at->toIso8601String()]);
            }
        }

        return $out;
    }

    protected function quoteData(Quotation $q, bool $full): array
    {
        return [
            'id' => $q->public_id, 'number' => $q->number, 'status' => $q->effectiveStatus(), 'title' => $q->title,
            'customer' => ['id' => $q->customer?->public_id, 'name' => $q->customer?->name],
            'valid_until' => $q->valid_until?->toDateString(), 'subtotal' => $q->subtotal, 'discount_total' => $q->discount_total, 'total' => $q->total,
            'order_id' => $q->order?->public_id,
        ] + ($full ? ['lines' => $q->lines->map(fn ($l) => $this->lineData($l, true))->values()] : []);
    }

    protected function invoiceData(Invoice $i): array
    {
        return [
            'id' => $i->public_id, 'number' => $i->number, 'kind' => $i->kind, 'status' => $i->status, 'total' => $i->total,
            'balance' => $i->balance(), 'due_date' => $i->due_date?->toDateString(),
            'lines' => $i->lines->map(fn ($l) => $l->only(['description', 'quantity', 'unit_price', 'line_total'])),
        ];
    }

    protected function paymentData(Payment $p): array
    {
        $p->loadMissing('allocations.order:id,public_id,number', 'allocations.invoice:id,public_id,number');

        return [
            'id' => $p->public_id, 'number' => $p->number, 'amount' => $p->amount, 'method' => $p->method, 'reference' => $p->reference,
            'status' => $p->status, 'received_at' => $p->received_at->toIso8601String(), 'unallocated' => $p->unallocated(),
            'allocations' => $p->allocations->map(fn ($a) => ['order' => $a->order?->number, 'invoice' => $a->invoice?->number, 'amount' => $a->amount]),
        ];
    }
}
