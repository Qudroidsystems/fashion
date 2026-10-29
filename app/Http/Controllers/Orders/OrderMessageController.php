<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Messaging\CustomerUpdates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Staff send the customer a message from an order or the customer's page; it goes through the outbox. */
class OrderMessageController extends Controller
{
    public function __construct(protected CustomerUpdates $updates)
    {
        $this->middleware('permission:Update order', ['only' => ['order']]);
        $this->middleware('permission:Update customer', ['only' => ['customer']]);
    }

    public function order(Request $request, Order $order): RedirectResponse
    {
        $data = $this->validated($request);
        $this->updates->custom($order->customer, $data['text'], $order, $request->user(), $data['channels'] ?? null);

        return back()->with('success', 'Message on its way to ' . $order->customer->first_name . '.');
    }

    public function customer(Request $request, Customer $customer): RedirectResponse
    {
        $data = $this->validated($request);
        $this->updates->custom($customer, $data['text'], $customer, $request->user(), $data['channels'] ?? null);

        return back()->with('success', 'Message on its way to ' . $customer->first_name . '.');
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'text' => 'required|string|max:1000',
            'channels' => 'nullable|array',
            'channels.*' => [Rule::in(array_keys(CustomerUpdates::CHANNEL_LABELS))],
        ]);
    }
}
