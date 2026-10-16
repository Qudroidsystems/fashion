<?php

namespace App\Http\Controllers\Production;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderLine;
use App\Services\Production\DeliveryService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Handover / collection (SRS FR-PRD-008). */
class DeliveryController extends Controller
{
    public function __construct(protected DeliveryService $deliveries)
    {
        $this->middleware('permission:Manage delivery', ['only' => ['store']]);
        $this->middleware('permission:View order', ['only' => ['show']]);
    }

    public function store(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'line_ids'               => 'required|array|min:1',
            'line_ids.*'             => 'integer',
            'method'                 => 'required|in:collection,delivery',
            'recipient_name'         => 'required|string|max:120',
            'recipient_phone'        => 'nullable|string|max:30',
            'relationship'           => 'nullable|string|max:60',
            'confirmation'           => ['required', Rule::in(array_keys(Delivery::CONFIRMATIONS))],
            'confirmation_ref'       => 'nullable|string|max:120',
            'outstanding_conditions' => 'nullable|string|max:1000',
            'address'                => 'nullable|string|max:255',
            'evidence'               => 'nullable|image|max:5120',
        ]);
        $delivery = $this->deliveries->handOver($order, $data, $request->user(), $request->file('evidence'));

        return redirect()->route('deliveries.show', $delivery)->with('success', 'Handover recorded.');
    }

    /** Printable handover note. */
    public function show(Delivery $delivery): View
    {
        $delivery->load('order.customer', 'user');

        return view('production.delivery', [
            'pagetitle' => $delivery->number,
            'delivery'  => $delivery,
            'lines'     => OrderLine::whereIn('id', $delivery->line_ids)->get(),
            'house'     => CurrentTenant::get(),
            'tz'        => CurrentTenant::get()->timezone,
        ]);
    }
}
