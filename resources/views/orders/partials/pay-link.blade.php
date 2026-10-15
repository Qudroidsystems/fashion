{{-- Online payment link: create, share, and recent links. Expects $target ('order'|'invoice'), $model, $due. --}}
@php
    use App\Support\Money;
    $tenant = \App\Support\Tenancy\CurrentTenant::get();
    $enabled = \App\Services\Payments\PaystackGateway::for($tenant)->enabled();
    $link = session('payment_link');
    $intents = \App\Models\PaymentIntent::where($target === 'order' ? 'order_id' : 'invoice_id', $model->id)->latest()->limit(5)->get();
@endphp
@can('Record payment')
    @if($link)
        @php $msg = "Hello {$link['customer']}, here is your secure link to pay " . Money::format($link['amount']) . " to {$tenant->name}: {$link['url']}";
             $wa = $link['phone'] ? preg_replace('/\D/', '', preg_replace('/^0/', '234', $link['phone'])) : ''; @endphp
        <div class="p-2 rounded-3 mb-2" style="background:#ecfdf5">
            <div class="small fw-semibold mb-1">Payment link for {{ Money::format($link['amount']) }}</div>
            <div class="input-group input-group-sm mb-1"><input class="form-control" value="{{ $link['url'] }}" id="payLinkUrl" readonly>
                <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('payLinkUrl').value);this.innerText='Copied'">Copy</button></div>
            <a class="btn btn-sm btn-success w-100" target="_blank" rel="noopener" href="https://wa.me/{{ $wa }}?text={{ rawurlencode($msg) }}"><i class="ri-whatsapp-line me-1"></i>Send on WhatsApp</a>
        </div>
    @endif
    @if($enabled && $due > 0)
        <button type="button" class="action-btn justify-content-center w-100" data-bs-toggle="collapse" data-bs-target="#payLinkBox"><i class="ri-secure-payment-line"></i>Send payment link</button>
        <div class="collapse" id="payLinkBox"><form method="POST" action="{{ $target === 'order' ? route('orders.payment-link', $model) : route('invoices.payment-link', $model) }}" class="p-2 rounded-3 mt-2" style="background:#f6f8fb">@csrf
            <input type="number" step="0.01" min="1" max="{{ $due }}" name="amount" class="form-control form-control-sm mb-2" value="{{ $due }}">
            <button class="btn btn-sm btn-primary w-100">Create Paystack link</button>
            <div class="small text-muted mt-1">Card or bank transfer. Confirmed automatically once Paystack verifies it.</div></form></div>
    @elseif(! $enabled && $due > 0 && auth()->user()->can('Manage tenant settings'))
        <a href="{{ route('settings.tenant.edit') }}#paystack" class="small text-muted d-block text-center mt-1">Set up online payments</a>
    @endif
    @foreach($intents as $pi)
        <div class="d-flex justify-content-between align-items-center small py-1 border-bottom">
            <span>Link {{ Money::format($pi->amount) }} · {{ $pi->created_at->diffForHumans() }}</span>
            @if($pi->status === 'pending')
                <form method="POST" action="{{ route('payment-intents.check', $pi) }}">@csrf<button class="btn btn-sm btn-light py-0">Check</button></form>
            @else
                <span class="status-pill {{ $pi->status === 'succeeded' ? 'st-success' : 'st-muted' }}">{{ $pi->status === 'succeeded' ? 'Paid' : ucfirst($pi->status) }}</span>
            @endif
        </div>
    @endforeach
@endcan
