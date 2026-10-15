<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Services\Pos\PosService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * SRS §8.2 Point of sale:
 *   GET  /pos/shifts/current · POST /pos/shifts/open · POST /pos/shifts/{id}/close
 *   POST /pos/sales (idempotent by client_ref, scenario 35) · GET /pos/sales/{id}
 *
 * Sale lines: {"sku":"PRD-00001-M","quantity":1} or {"order":"<order id>","amount":25000}.
 * Prices are always set by the server; a unit_price is honoured only with "Override price".
 */
class PosController extends Controller
{
    public function __construct(protected PosService $pos)
    {
        $this->middleware('permission:Use POS');
        $this->middleware('permission:Open cash shift', ['only' => ['open']]);
        $this->middleware('permission:Close cash shift', ['only' => ['close']]);
    }

    public function current(Request $request): JsonResponse
    {
        $shift = $this->pos->currentShift($request->user());

        return response()->json(['success' => true, 'data' => $shift ? $this->shiftData($shift, true) : null]);
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch'        => 'nullable|string',
            'opening_float' => 'required|numeric|min:0|max:100000000',
            'notes'         => 'nullable|string|max:500',
        ]);
        $branchId = ! empty($data['branch'])
            ? (Branch::active()->where('code', $data['branch'])->value('id') ?? throw ValidationException::withMessages(['branch' => 'Unknown branch.']))
            : (CurrentTenant::membership()?->branch_id ?? Branch::where('is_main', true)->value('id'));
        $shift = $this->pos->openShift($request->user(), (int) $branchId, (float) $data['opening_float'], $data['notes'] ?? null);

        return response()->json(['success' => true, 'data' => $this->shiftData($shift, true)], 201);
    }

    public function close(Request $request, CashShift $shift): JsonResponse
    {
        abort_unless($shift->user_id === $request->user()->id || $request->user()->can('Void sale'), 403);
        $data = $request->validate(['counted_cash' => 'required|numeric|min:0', 'variance_reason' => 'nullable|string|max:255']);
        $shift = $this->pos->closeShift($shift, (float) $data['counted_cash'], $data['variance_reason'] ?? null, $request->user());

        return response()->json(['success' => true, 'data' => $this->shiftData($shift, false)]);
    }

    public function storeSale(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_ref' => 'required|string|min:8|max:64',
            'customer'   => 'nullable|string',
            'note'       => 'nullable|string|max:255',
            'lines'      => 'required|array|min:1',
            'lines.*.sku' => 'nullable|string',
            'lines.*.order' => 'nullable|string',
            'lines.*.quantity' => 'nullable|numeric|min:0.01|max:9999',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.discount' => 'nullable|numeric|min:0',
            'lines.*.amount' => 'nullable|numeric|min:0',
            'tenders'    => 'required|array|min:1',
            'tenders.*.method' => 'required|in:cash,card,bank_transfer,mobile_money',
            'tenders.*.amount' => 'required|numeric|min:0',
            'tenders.*.reference' => 'nullable|string|max:120',
        ]);
        $shift = $this->pos->currentShift($request->user());
        abort_unless($shift, 409, 'No open shift. Open a shift before selling.');

        $existing = Sale::where('client_ref', $data['client_ref'])->first();
        if (! empty($data['customer'])) {
            $data['customer_id'] = Customer::where('public_id', $data['customer'])->orWhere('customer_number', $data['customer'])->value('id')
                ?? throw ValidationException::withMessages(['customer' => 'Unknown customer.']);
        }
        $data['lines'] = collect($data['lines'])->map(function ($l, $i) {
            if (! empty($l['order'])) {
                $id = Order::where('public_id', $l['order'])->orWhere('number', $l['order'])->value('id')
                    ?? throw ValidationException::withMessages(['lines' => 'Unknown order on line ' . ($i + 1) . '.']);

                return ['type' => 'order', 'order_id' => $id, 'amount' => $l['amount'] ?? 0];
            }
            $vid = ProductVariant::where('sku', $l['sku'] ?? '')->orWhere('barcode', $l['sku'] ?? '')->value('id')
                ?? throw ValidationException::withMessages(['lines' => 'Unknown SKU on line ' . ($i + 1) . '.']);

            return ['type' => 'product', 'variant_id' => $vid] + array_intersect_key($l, array_flip(['quantity', 'unit_price', 'discount']));
        })->all();

        $sale = $this->pos->completeSale($shift, $data, $request->user());

        return response()->json(['success' => true, 'replayed' => (bool) $existing, 'data' => $this->saleData($sale->load('lines', 'tenders'))], $existing ? 200 : 201);
    }

    public function showSale(Sale $sale): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->saleData($sale->load('lines', 'tenders'))]);
    }

    protected function shiftData(CashShift $s, bool $summary): array
    {
        return [
            'id' => $s->public_id, 'number' => $s->number, 'status' => $s->status, 'branch' => $s->branch?->name,
            'opened_at' => $s->opened_at?->toIso8601String(), 'opening_float' => $s->opening_float,
            'closed_at' => $s->closed_at?->toIso8601String(), 'expected_cash' => $s->expected_cash,
            'counted_cash' => $s->counted_cash, 'variance' => $s->variance,
        ] + ($summary && $s->isOpen() ? ['summary' => $this->blind($this->pos->summary($s))] : []);
    }

    /** Blind count: only supervisors see the expected cash while a shift is open. */
    protected function blind(array $summary): array
    {
        if (! request()->user()->can('Void sale')) {
            unset($summary['expected_cash'], $summary['tenders']['cash']);
        }

        return $summary;
    }

    protected function saleData(Sale $s): array
    {
        return [
            'id' => $s->public_id, 'number' => $s->number, 'client_ref' => $s->client_ref, 'status' => $s->status,
            'total' => $s->total, 'discount_total' => $s->discount_total, 'change' => $s->change_given,
            'created_at' => $s->created_at->toIso8601String(),
            'lines' => $s->lines->map(fn ($l) => ['kind' => $l->kind, 'description' => $l->description, 'quantity' => $l->quantity,
                'unit_price' => $l->unit_price, 'discount' => $l->discount, 'line_total' => $l->line_total]),
            'tenders' => $s->tenders->map(fn ($t) => ['method' => $t->method, 'amount' => $t->amount, 'received' => $t->received, 'reference' => $t->reference]),
            'receipt_url' => route('pos.sales.receipt', $s),
        ];
    }
}
