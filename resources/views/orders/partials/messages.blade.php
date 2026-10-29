{{-- Order page: messages to the customer about this order, and a box to send one. Expects $order. --}}
@php
    $cu = app(\App\Services\Messaging\CustomerUpdates::class);
    $omsgs = $cu->forOrder($order);
    $otz = \App\Support\Tenancy\CurrentTenant::get()->timezone;
    $canMsg = auth()->user()->can('Update order') && $order->customer && $order->status !== 'draft';
    $drafts = $canMsg ? collect(['order_ready', 'balance_reminder', 'order_confirmed'])->mapWithKeys(fn ($e) => [$e => $cu->draftFor($order, $e)]) : collect();
@endphp
@if($canMsg || $omsgs->isNotEmpty())
<x-cb.card title="Messages to {{ $order->customer?->first_name }}" icon="ri-chat-3-line" :count="$omsgs->count()" class="mt-3">
    @forelse($omsgs as $msg)
        <div class="py-2 {{ $loop->last ? '' : 'border-bottom' }}">
            <div class="d-flex justify-content-between gap-2 small">
                <span class="fw-semibold">{{ $msg->purpose === 'custom' ? 'Message' : (\App\Services\Messaging\CustomerUpdates::EVENTS[$msg->purpose][0] ?? ucfirst(str_replace('_', ' ', $msg->purpose))) }}</span>
                <span class="text-muted text-nowrap">{{ $msg->created_at->timezone($otz)->format('j M, g:i A') }}</span>
            </div>
            <div class="small text-muted text-truncate" title="{{ $msg->body }}">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $msg->body), 140) }}</div>
            <div class="small mt-1">
                @if($msg->status === 'sent')<span class="status-pill st-success">Sent · {{ $msg->result }}</span>
                @elseif($msg->status === 'failed')<span class="status-pill st-danger" title="{{ $msg->result }}">Not sent</span> <span class="text-muted">{{ $msg->result }}</span>
                @else<span class="status-pill st-muted">Sending…</span>@endif
                <span class="text-muted ms-1">{{ $msg->creator?->name ?? 'Automatic' }}</span>
            </div>
        </div>
    @empty
        <p class="small text-muted mb-2">No messages yet. Customers hear automatically when the order is confirmed, paid for and ready — <a href="{{ route('settings.customer-messages') }}">change what they get</a>.</p>
    @endforelse

    @if($canMsg)
        <form method="post" action="{{ route('orders.messages.store', $order) }}" class="mt-3">@csrf
            <div class="d-flex flex-wrap gap-1 mb-2">
                @foreach($drafts as $e => $text)
                    <button type="button" class="btn btn-light btn-sm py-0" onclick="this.form.text.value = this.dataset.text; this.form.text.focus()" data-text="{{ $text }}">{{ \App\Services\Messaging\CustomerUpdates::EVENTS[$e][0] }}</button>
                @endforeach
            </div>
            <textarea name="text" rows="3" maxlength="1000" required class="form-control form-control-sm" placeholder="Write to {{ $order->customer->first_name }}, or start from one of the above"></textarea>
            <div class="d-flex justify-content-end mt-2"><button class="btn btn-primary btn-sm"><i class="ri-send-plane-line me-1"></i>Send</button></div>
        </form>
    @endif
</x-cb.card>
@endif
