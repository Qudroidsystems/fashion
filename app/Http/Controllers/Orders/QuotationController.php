<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Quotation;
use App\Services\Orders\OrderService;
use App\Support\Orders\Catalog;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Quotations (SRS FR-ORD-001, §6.2 steps 9–10). */
class QuotationController extends Controller
{
    public function __construct(protected OrderService $orders)
    {
        $this->middleware('permission:Manage quotation');
        $this->middleware('permission:Create order', ['only' => ['accept']]);
    }

    public function index(Request $request): View
    {
        $status = $request->input('status', 'open');

        return view('orders.quotations.index', [
            'pagetitle'  => 'Quotations',
            'quotations' => Quotation::with('customer:id,public_id,first_name,last_name,customer_number', 'order:id,public_id,number')
                ->when($status === 'open', fn ($q) => $q->whereIn('status', ['draft', 'sent']))
                ->when(! in_array($status, ['open', 'all'], true), fn ($q) => $q->where('status', $status))
                ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', "%{$request->q}%")
                    ->orWhere('title', 'like', "%{$request->q}%")->orWhereHas('customer', fn ($c) => $c->search($request->q))))
                ->latest('id')->paginate(30)->withQueryString(),
            'status'     => $status,
        ]);
    }

    public function create(Request $request): View
    {
        $customer = $request->filled('customer') ? Customer::where('public_id', $request->customer)->first() : null;

        return view('orders.quotations.form', $this->formData(new Quotation(['customer_id' => $customer?->id]), []));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $q = $this->orders->saveQuotation(null, $data, $data['lines']);
        if ($request->input('action') === 'send') {
            $this->orders->sendQuotation($q);
        }

        return redirect()->route('quotations.show', $q)->with('success', 'Quotation saved.');
    }

    public function show(Quotation $quotation): View
    {
        $quotation->load('customer', 'lines.styleVersion.style', 'order', 'creator', 'branch');

        return view('orders.quotations.show', ['pagetitle' => $quotation->number, 'q' => $quotation, 'tz' => CurrentTenant::get()->timezone,
            'house' => CurrentTenant::get()]);
    }

    public function edit(Quotation $quotation): View
    {
        abort_unless($quotation->isEditable(), 403, 'This quotation was already decided.');
        $quotation->load('lines');

        return view('orders.quotations.form', $this->formData($quotation, $quotation->lines->map->copyable()->all()));
    }

    public function update(Request $request, Quotation $quotation): RedirectResponse
    {
        $data = $this->validated($request, $quotation);
        $q = $this->orders->saveQuotation($quotation, $data, $data['lines']);
        if ($request->input('action') === 'send') {
            $this->orders->sendQuotation($q);
        }

        return redirect()->route('quotations.show', $q)->with('success', 'Quotation saved.');
    }

    public function send(Quotation $quotation): RedirectResponse
    {
        $this->orders->sendQuotation($quotation);

        return back()->with('success', 'Marked as sent. Print it or share it with the customer.');
    }

    public function accept(Request $request, Quotation $quotation): RedirectResponse
    {
        $data = $request->validate(['promised_date' => 'nullable|date']);
        $order = $this->orders->acceptQuotation($quotation, $data);

        return redirect()->route('orders.show', $order)->with('success', 'Quotation accepted — order ' . $order->number . ' is confirmed. Record the deposit when it is paid.');
    }

    public function decline(Request $request, Quotation $quotation): RedirectResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        $this->orders->declineQuotation($quotation, $data['reason'] ?? null);

        return back()->with('success', 'Marked as declined.');
    }

    protected function formData(Quotation $q, array $rows): array
    {
        return [
            'pagetitle' => $q->exists ? 'Edit ' . $q->number : 'New quotation',
            'q'         => $q,
            'rows'      => $rows,
            'catalog'   => Catalog::data(),
            'customers' => Customer::active()->orderBy('first_name')->limit(1000)->get(['id', 'first_name', 'last_name', 'customer_number', 'phone']),
            'depositPercent' => (float) CurrentTenant::get()->setting('deposit_percent', 50),
        ];
    }

    protected function validated(Request $request, ?Quotation $q = null): array
    {
        return $request->validate([
            'customer_id'     => [$q ? 'nullable' : 'required', Rule::exists('customers', 'id')->where('tenant_id', CurrentTenant::id())->where('status', 'active')],
            'title'           => 'nullable|string|max:150',
            'event_date'      => 'nullable|date',
            'valid_until'     => 'nullable|date',
            'order_discount'  => 'nullable|numeric|min:0',
            'deposit_percent' => 'nullable|numeric|min:0|max:100',
            'customer_notes'  => 'nullable|string|max:2000',
            'internal_notes'  => 'nullable|string|max:2000',
            'lines'           => 'required|array',
            'lines.*.kind'    => 'required|in:bespoke,ready_made,service,charge',
            'lines.*.style_id'=> 'nullable|integer',
            'lines.*.product_variant_id' => 'nullable|integer',
            'lines.*.description' => 'nullable|string|max:255',
            'lines.*.instructions' => 'nullable|string|max:2000',
            'lines.*.options' => 'nullable|array',
            'lines.*.quantity' => 'nullable|numeric|min:0',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.discount' => 'nullable|numeric|min:0',
            'action'          => 'nullable|in:save,send',
        ]);
    }
}
