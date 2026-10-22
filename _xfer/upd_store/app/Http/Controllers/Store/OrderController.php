<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use App\Services\Store\ShopOrderService;
use App\Services\Store\StoreCatalog;
use App\Services\Store\StoreSettings;
use App\Services\Store\WhatsApp;
use App\Support\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Online & WhatsApp orders in the back office. */
class OrderController extends Controller
{
    public function __construct(protected ShopOrderService $service, protected StoreSettings $settings, protected StoreCatalog $catalog)
    {
        $this->middleware('permission:View online orders', ['only' => ['dashboard', 'index', 'show']]);
        $this->middleware('permission:Manage online orders', ['only' => ['create', 'store', 'accept', 'advance', 'cancel', 'note', 'revise', 'variants']]);
        $this->middleware('permission:Confirm online payment', ['only' => ['payment', 'rejectProof']]);
    }

    public function dashboard(): View
    {
        $tenant = CurrentTenant::require();
        $tz = $tenant->timezone ?: config('app.timezone');
        $today = now($tz)->startOfDay()->utc();
        $month = now($tz)->startOfMonth()->utc();
        $live = ShopOrder::whereNotIn('status', ['cancelled', 'pending_payment']);

        return view('store-admin.dashboard', [
            'pagetitle' => 'Online store',
            'tenant'    => $tenant,
            'open'      => $this->settings->isOpen($tenant),
            'url'       => $this->settings->url($tenant),
            'stats'     => [
                'today'      => (float) (clone $live)->where('created_at', '>=', $today)->sum('total'),
                'today_n'    => (clone $live)->where('created_at', '>=', $today)->count(),
                'month'      => (float) (clone $live)->where('created_at', '>=', $month)->sum('total'),
                'month_n'    => (clone $live)->where('created_at', '>=', $month)->count(),
                'collected'  => (float) ShopOrder::where('created_at', '>=', $month)->sum('amount_paid'),
                'to_confirm' => ShopOrder::where('status', 'awaiting_confirmation')->count(),
                'proofs'     => ShopOrder::where('payment_status', 'pending')->whereNotIn('status', ['cancelled'])->count(),
                'to_ship'    => ShopOrder::whereIn('status', ['confirmed', 'processing'])->count(),
                'whatsapp'   => ShopOrder::where('payment_method', 'whatsapp')->where('status', 'awaiting_confirmation')->count(),
            ],
            'recent'    => ShopOrder::with('items')->latest('id')->take(8)->get(),
            'top'       => ShopOrderItem::whereHas('shopOrder', fn ($q) => $q->whereNotNull('order_id')->where('created_at', '>=', $month))
                ->selectRaw('product_id, name, sum(quantity) qty, sum(line_total) amount')->groupBy('product_id', 'name')->orderByDesc('qty')->take(6)->get(),
            'methods'   => $this->settings->methods($tenant),
            'lowStock'  => $this->lowStock($tenant),
        ]);
    }

    protected function lowStock($tenant)
    {
        $products = $this->catalog->base()->where('track_stock', true)->get();
        $stock = $this->catalog->stock($tenant, $products->flatMap->variants);
        $limit = (int) StoreSettings::get($tenant, 'low_stock_badge', 3);

        return $products->flatMap(fn ($p) => $p->variants->map(fn ($v) => ['product' => $p, 'variant' => $v, 'left' => $stock[$v->id] ?? null]))
            ->filter(fn ($r) => $r['left'] !== null && $r['left'] <= $limit)->sortBy('left')->take(8)->values();
    }

    public function index(Request $request): View
    {
        $tab = $request->input('tab', 'action');
        $tabs = [
            'action'    => ['Needs action', fn ($q) => $q->where(fn ($w) => $w->where('status', 'awaiting_confirmation')->orWhere('payment_status', 'pending')->orWhereIn('status', ['confirmed', 'processing']))->where('status', '!=', 'cancelled')],
            'confirm'   => ['To confirm', fn ($q) => $q->where('status', 'awaiting_confirmation')],
            'payments'  => ['Receipts to check', fn ($q) => $q->where('payment_status', 'pending')->where('status', '!=', 'cancelled')],
            'fulfil'    => ['To pack & send', fn ($q) => $q->whereIn('status', ['confirmed', 'processing'])],
            'transit'   => ['On the way / pickup', fn ($q) => $q->whereIn('status', ['shipped', 'ready_for_pickup'])],
            'card'      => ['Unpaid card checkouts', fn ($q) => $q->where('status', 'pending_payment')],
            'done'      => ['Delivered', fn ($q) => $q->where('status', 'delivered')],
            'cancelled' => ['Cancelled', fn ($q) => $q->where('status', 'cancelled')],
            'all'       => ['All', fn ($q) => $q],
        ];
        $tab = isset($tabs[$tab]) ? $tab : 'action';
        $term = trim((string) $request->q);

        $orders = ShopOrder::with('items')
            ->tap($tabs[$tab][1])
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', "%{$term}%")->orWhere('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")))
            ->when($request->filled('method'), fn ($q) => $q->where('payment_method', $request->method))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->source))
            ->latest('id')->paginate(25)->withQueryString();

        return view('store-admin.orders.index', [
            'pagetitle' => 'Online orders',
            'orders'    => $orders,
            'tab'       => $tab,
            'tabs'      => collect($tabs)->map(fn ($t, $k) => ['label' => $t[0], 'count' => in_array($k, ['all', 'done', 'cancelled'], true) ? null : ShopOrder::tap($t[1])->count()]),
            'tz'        => CurrentTenant::get()->timezone,
        ]);
    }

    public function show(ShopOrder $shopOrder): View
    {
        $tenant = CurrentTenant::require();
        $shopOrder->load(['items.variant', 'events.user', 'order.lines', 'customer', 'pickupBranch', 'zone', 'account', 'intents', 'handler']);
        $orderUrl = $this->settings->url($tenant, 'order/' . $shopOrder->token);

        return view('store-admin.orders.show', [
            'pagetitle' => 'Order ' . $shopOrder->number,
            'o'         => $shopOrder,
            'tz'        => $tenant->timezone,
            'orderUrl'  => $orderUrl,
            'waCustomer'=> WhatsApp::link(WhatsApp::normalize($shopOrder->phone, $tenant), WhatsApp::customerMessage($shopOrder, $tenant, $orderUrl)),
            'next'      => ShopOrderService::FLOW[$shopOrder->status] ?? [],
            'allowBalance' => (bool) $tenant->setting('release_with_balance', false),
            'variants'  => $shopOrder->isAwaiting() ? $this->variantOptions() : collect(),
            'movements' => \App\Models\StockMovement::with('item')->where('source_type', 'shop_order')->where('source_id', $shopOrder->id)->orderBy('id')->get(),
        ]);
    }

    public function create(): View
    {
        $tenant = CurrentTenant::require();

        return view('store-admin.orders.create', [
            'pagetitle' => 'Take an order',
            'variants'  => $this->variantOptions(),
            'branches'  => Branch::active()->orderByDesc('is_main')->get(),
            'methods'   => ShopOrder::PAYMENT_METHODS,
            'states'    => ($tenant->currency_code ?: 'NGN') === 'NGN' ? collect(json_decode((string) @file_get_contents(public_path('states_lgas.json')), true) ?: [])->pluck('state')->all() : [],
            'canOverride' => auth()->user()->can('Override price'),
        ]);
    }

    /** Sellable sizes/colours with price and free stock, for the order forms. */
    protected function variantOptions()
    {
        $tenant = CurrentTenant::require();
        $variants = ProductVariant::with('product')->where('is_active', true)->whereHas('product', fn ($q) => $q->where('status', 'active'))->get()
            ->sortBy(fn ($v) => $v->product->name . ' ' . $v->sort_order);
        $stock = $this->catalog->stock($tenant, $variants);

        return $variants->map(fn ($v) => ['id' => $v->id, 'name' => $v->product->name, 'label' => $v->label, 'sku' => $v->sku,
            'price' => (float) $v->price, 'stock' => $stock[$v->id] ?? null])->values();
    }

    public function variants(Request $request): JsonResponse
    {
        $term = trim((string) $request->q);

        return response()->json($this->variantOptions()->filter(fn ($v) => $term === '' || stripos($v['name'] . ' ' . $v['label'] . ' ' . $v['sku'], $term) !== false)->take(30)->values());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:80', 'last_name' => 'nullable|string|max:80', 'phone' => 'required|string|max:30',
            'email' => 'nullable|email|max:160', 'fulfilment' => 'required|in:pickup,delivery',
            'pickup_branch_id' => ['required_if:fulfilment,pickup', 'nullable', 'integer', Rule::exists('branches', 'id')->where('tenant_id', CurrentTenant::id())],
            'address_line1' => 'nullable|string|max:190', 'address_line2' => 'nullable|string|max:190', 'city' => 'nullable|string|max:80',
            'state' => 'nullable|string|max:80', 'shipping_fee' => 'nullable|numeric|min:0|max:99999999', 'discount' => 'nullable|numeric|min:0|max:99999999',
            'payment_method' => ['required', Rule::in(array_keys(ShopOrder::PAYMENT_METHODS))], 'source' => 'required|in:whatsapp,phone,instagram,walk_in,other',
            'customer_note' => 'nullable|string|max:1000', 'confirm' => 'nullable|boolean',
            'items' => 'required|array|min:1|max:50', 'items.*.variant_id' => 'required|integer', 'items.*.quantity' => 'required|integer|min:1|max:999',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0', 'paid_method' => 'nullable|in:bank_transfer,cash,card,mobile_money,other', 'paid_reference' => 'nullable|string|max:120',
        ], ['items.required' => 'Add at least one item.']);
        $tenant = CurrentTenant::require();
        $by = $request->user();

        $order = DB::transaction(function () use ($tenant, $data, $by, $request) {
            $order = $this->service->takeOrder($tenant, $data['items'], $data, $by);
            if (! empty($data['paid_amount']) && $data['paid_amount'] > 0) {
                abort_unless($by->can('Confirm online payment'), 403, 'You cannot record payments.');
                $this->service->recordPayment($order, (float) $data['paid_amount'], $data['paid_method'] ?? 'bank_transfer', $data['paid_reference'] ?? null, $by, null, 'online');
            }

            return $order;
        });

        return redirect()->route('store.orders.show', $order->public_id)->with('success', "Order {$order->number} saved. Send it to the customer on WhatsApp so they can see it and pay.");
    }

    public function accept(Request $request, ShopOrder $shopOrder): RedirectResponse
    {
        $this->service->accept($shopOrder, $request->user(), trim((string) $request->input('note')) ?: 'Confirmed by ' . $request->user()->name);

        return back()->with('success', 'Order confirmed. It is now order ' . $shopOrder->fresh('order')->order?->number . ' and the stock has left the shelf.');
    }

    public function payment(Request $request, ShopOrder $shopOrder): RedirectResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:999999999', 'method' => 'required|in:bank_transfer,cash,card,mobile_money,other',
            'reference' => 'nullable|string|max:120', 'received_at' => 'nullable|date|before_or_equal:now', 'notes' => 'nullable|string|max:500',
        ]);
        abort_if($shopOrder->status === 'cancelled', 422, 'This order was cancelled.');
        $tz = CurrentTenant::get()->timezone ?: config('app.timezone');
        $this->service->recordPayment($shopOrder, (float) $data['amount'], $data['method'], $data['reference'] ?? $shopOrder->payment_reference, $request->user(),
            $data['notes'] ?? null, 'online', ! empty($data['received_at']) ? Carbon::parse($data['received_at'], $tz)->utc() : null);

        return back()->with('success', 'Payment recorded on the order.');
    }

    public function rejectProof(Request $request, ShopOrder $shopOrder): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $this->service->rejectProof($shopOrder, $data['reason'], $request->user());

        return back()->with('success', 'The customer was told the transfer could not be matched.');
    }

    public function advance(Request $request, ShopOrder $shopOrder): RedirectResponse
    {
        $data = $request->validate([
            'to' => 'required|in:processing,ready_for_pickup,shipped,delivered', 'courier' => 'nullable|string|max:80',
            'tracking_number' => 'nullable|string|max:80', 'note' => 'nullable|string|max:500', 'recipient_name' => 'nullable|string|max:120',
            'outstanding' => 'nullable|string|max:500',
            'collect_amount' => 'nullable|numeric|min:0', 'collect_method' => 'nullable|in:cash,card,bank_transfer,mobile_money,other',
        ]);
        DB::transaction(function () use ($request, $shopOrder, $data) {
            if (($data['to'] === 'delivered') && ! empty($data['collect_amount']) && $data['collect_amount'] > 0) {
                abort_unless($request->user()->can('Confirm online payment'), 403, 'You cannot record payments.');
                $this->service->recordPayment($shopOrder, (float) $data['collect_amount'], $data['collect_method'] ?? 'cash', null, $request->user(), 'Collected on ' . ($shopOrder->fulfilment === 'pickup' ? 'pickup' : 'delivery'), 'online');
                $shopOrder->refresh();
            }
            $this->service->advance($shopOrder, $data['to'], $data, $request->user());
        });

        return back()->with('success', 'Order updated: ' . ShopOrder::STATUSES[$shopOrder->fresh()->status][0] . '.');
    }

    public function cancel(Request $request, ShopOrder $shopOrder): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $this->service->cancel($shopOrder, $data['reason'], $request->user());

        return back()->with('success', 'Order cancelled and the stock put back.' . ($shopOrder->amount_paid > 0 ? ' The money paid is now the customer’s credit — refund it from Payments if needed.' : ''));
    }

    public function note(Request $request, ShopOrder $shopOrder): RedirectResponse
    {
        $data = $request->validate(['note' => 'required|string|max:500', 'customer_visible' => 'nullable|boolean']);
        $this->service->event($shopOrder, 'note', null, $data['note'], $request->boolean('customer_visible'), $request->user());

        return back()->with('success', 'Note added.');
    }

    public function revise(Request $request, ShopOrder $shopOrder): RedirectResponse
    {
        $data = $request->validate([
            'items' => 'required|array|min:1|max:50', 'items.*.variant_id' => 'required|integer', 'items.*.quantity' => 'required|integer|min:0|max:999',
            'items.*.unit_price' => 'nullable|numeric|min:0', 'shipping_fee' => 'nullable|numeric|min:0', 'discount' => 'nullable|numeric|min:0',
        ]);
        $this->service->revise($shopOrder, $data['items'], isset($data['shipping_fee']) ? (float) $data['shipping_fee'] : null,
            isset($data['discount']) ? (float) $data['discount'] : null, $request->user());

        return back()->with('success', 'Order changed. Send the customer the updated order on WhatsApp.');
    }
}
