<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Services\Pos\PosService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The till (SRS FR-POS-001/002/006, §6.3). */
class PosController extends Controller
{
    public function __construct(protected PosService $pos)
    {
        $this->middleware('permission:Use POS');
    }

    public function terminal(Request $request): View
    {
        $shift = $this->pos->currentShift($request->user());
        if (! $shift) {
            return view('pos.open-shift', [
                'pagetitle' => 'Point of sale',
                'branches'  => Branch::active()->orderByDesc('is_main')->orderBy('name')->get(),
                'branchId'  => CurrentTenant::membership()?->branch_id ?? Branch::where('is_main', true)->value('id'),
            ]);
        }

        return view('pos.terminal', [
            'pagetitle' => 'Point of sale',
            'shift'     => $shift->load('branch'),
            'summary'   => $this->pos->summary($shift),
            'canOverride' => $request->user()->can('Override price'),
            'discountLimit' => (float) CurrentTenant::get()->setting('discount_limit_percent', 10),
            'currency'  => \App\Support\Money::symbol(),
        ]);
    }

    /** Scan or search sellable items with stock at the till's location (FR-POS-002). */
    public function products(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $shift = $this->pos->currentShift($request->user());
        abort_unless($shift, 409, 'Open a shift first.');

        $variants = ProductVariant::with('product:id,name,image_path,track_stock,status', 'stockItem:id,product_variant_id')
            ->where('is_active', true)->whereHas('product', fn ($p) => $p->where('status', 'active'))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('barcode', $q)->orWhere('sku', $q)
                ->orWhere('sku', 'like', "%{$q}%")->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$q}%"))))
            ->limit(40)->get();
        $exact = $q !== '' ? $variants->first(fn ($v) => $v->barcode === $q || strcasecmp($v->sku, $q) === 0) : null;

        $stock = StockBalance::where('branch_id', $shift->branch_id)->whereIn('item_id', $variants->pluck('stockItem.id')->filter())
            ->selectRaw('item_id, sum(on_hand - reserved) free')->groupBy('item_id')->pluck('free', 'item_id');

        return response()->json([
            'exact' => $exact?->id,
            'items' => $variants->map(fn ($v) => [
                'id' => $v->id, 'name' => $v->product->name, 'variant' => $v->label, 'sku' => $v->sku, 'barcode' => $v->barcode,
                'price' => (float) $v->price, 'image' => $v->product->image_url,
                'stock' => $v->product->track_stock ? (float) ($stock[$v->stockItem?->id] ?? 0) : null,
            ])->values(),
        ]);
    }

    public function customers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        return response()->json(Customer::active()->search($q)->limit(10)->get(['id', 'first_name', 'last_name', 'phone', 'customer_number'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'number' => $c->customer_number]));
    }

    /** Open orders with money owing, for deposits / balances at the till (FR-POS-006). */
    public function orders(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        return response()->json(Order::with('customer:id,first_name,last_name')
            ->whereNotIn('status', ['draft', 'cancelled', 'closed'])
            ->when($request->integer('customer'), fn ($w, $c) => $w->where('customer_id', $c))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('number', 'like', "%{$q}%")->orWhere('title', 'like', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->search($q))))
            ->latest('id')->limit(15)->get()
            ->map(fn ($o) => ['id' => $o->id, 'number' => $o->number, 'title' => $o->title, 'customer_id' => $o->customer_id,
                'customer' => $o->customer->name, 'balance' => $o->balance(),
                'deposit_due' => max(0, round($o->deposit_required - $o->paid(), 2))])
            ->filter(fn ($o) => $o['balance'] > 0)->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_ref'  => 'required|string|min:8|max:64',
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('tenant_id', CurrentTenant::id())],
            'note'        => 'nullable|string|max:255',
            'lines'       => 'required|array|min:1',
            'lines.*.type' => 'required|in:product,order',
            'lines.*.variant_id' => 'nullable|integer',
            'lines.*.order_id' => 'nullable|integer',
            'lines.*.quantity' => 'nullable|numeric|min:0.01|max:9999',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.discount' => 'nullable|numeric|min:0',
            'lines.*.amount' => 'nullable|numeric|min:0',
            'tenders'     => 'required|array|min:1',
            'tenders.*.method' => 'required|in:cash,card,bank_transfer,mobile_money',
            'tenders.*.amount' => 'required|numeric|min:0',
            'tenders.*.reference' => 'nullable|string|max:120',
        ]);
        $shift = $this->pos->currentShift($request->user());
        abort_unless($shift, 409, 'Your shift is closed. Open a new shift to sell.');
        $sale = $this->pos->completeSale($shift, $data, $request->user());

        return response()->json([
            'success' => true,
            'sale' => ['id' => $sale->public_id, 'number' => $sale->number, 'total' => $sale->total, 'change' => $sale->change_given,
                'receipt_url' => route('pos.sales.receipt', $sale), 'url' => route('pos.sales.show', $sale)],
            'summary' => \Illuminate\Support\Arr::only($this->pos->summary($shift->fresh()), ['sales_count', 'sales_total']),
        ]);
    }

    /* -------------------------------- sales -------------------------------- */

    public function sales(Request $request): View
    {
        $tz = CurrentTenant::get()->timezone;
        $date = $request->date('date') ?? now($tz);

        return view('pos.sales', [
            'pagetitle' => 'Sales',
            'sales'     => Sale::with('cashier:id,name', 'customer:id,first_name,last_name', 'branch:id,name')->withCount('lines')
                ->when($request->filled('q'), fn ($w) => $w->where('number', 'like', "%{$request->q}%"))
                ->when(! $request->filled('q'), fn ($w) => $w->whereBetween('created_at', [$date->copy()->timezone($tz)->startOfDay()->utc(), $date->copy()->timezone($tz)->endOfDay()->utc()]))
                ->latest('id')->paginate(50)->withQueryString(),
            'date'      => $date,
            'tz'        => $tz,
        ]);
    }

    public function show(Sale $sale): View
    {
        $sale->load('lines.order', 'tenders', 'customer', 'cashier', 'branch', 'shift', 'voider', 'returns.lines', 'returns.user');

        return view('pos.sale', ['pagetitle' => $sale->number, 'sale' => $sale, 'tz' => CurrentTenant::get()->timezone]);
    }

    public function receipt(Sale $sale): View
    {
        $sale->load('lines', 'tenders', 'customer', 'cashier', 'branch');

        return view('pos.receipt', ['sale' => $sale, 'house' => CurrentTenant::get(), 'tz' => CurrentTenant::get()->timezone]);
    }

    public function void(Request $request, Sale $sale): RedirectResponse
    {
        abort_unless($request->user()->can('Void sale'), 403);
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $this->pos->voidSale($sale, $data['reason'], $request->user());

        return back()->with('success', 'Sale voided. Stock is back on the shelf and the money is out of the drawer total.');
    }

    public function returnItems(Request $request, Sale $sale): RedirectResponse
    {
        abort_unless($request->user()->can('Void sale'), 403);
        $data = $request->validate([
            'quantities'    => 'required|array',
            'quantities.*'  => 'nullable|numeric|min:0',
            'refund_method' => 'required|in:cash,card,bank_transfer,mobile_money,store_credit',
            'restock'       => 'nullable|boolean',
            'reason'        => 'required|string|max:255',
        ]);
        $ret = $this->pos->returnItems($sale, $data['quantities'], $data['refund_method'], $request->boolean('restock'), $data['reason'], $request->user());

        return back()->with('success', 'Return ' . $ret->number . ' recorded — refund ' . \App\Support\Money::format($ret->amount) . '.');
    }
}
