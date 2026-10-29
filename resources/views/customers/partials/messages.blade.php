{{-- Customer page: the last messages sent to this customer, and a box to send one. Expects $c. --}}
@php
    $clogs = \App\Models\OutgoingMessage::where('customer_id', $c->id)->with('creator:id,name')->latest('id')->limit(8)->get();
    $ctz = \App\Support\Tenancy\CurrentTenant::get()->timezone;
    $canMsgC = auth()->user()->can('Update customer') && $c->status !== 'merged';
@endphp
<x-cb.card title="Messages" icon="ri-chat-3-line" :count="$clogs->count()" class="mt-3">
    @forelse($clogs as $msg)
        <div class="py-2 small {{ $loop->last ? '' : 'border-bottom' }}">
            <div class="d-flex justify-content-between gap-2">
                <span class="fw-semibold">{{ $msg->purpose === 'custom' ? 'Message' : (\App\Services\Messaging\CustomerUpdates::EVENTS[$msg->purpose][0] ?? ucfirst(str_replace('_', ' ', $msg->purpose))) }}</span>
                <span class="text-muted text-nowrap">{{ $msg->created_at->timezone($ctz)->format('j M') }}</span>
            </div>
            <div class="text-muted text-truncate" title="{{ $msg->body }}">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $msg->body), 90) }}</div>
            <span class="status-pill {{ $msg->status === 'sent' ? 'st-success' : ($msg->status === 'failed' ? 'st-danger' : 'st-muted') }}" title="{{ $msg->result }}">{{ $msg->status === 'sent' ? 'Sent · ' . $msg->result : ($msg->status === 'failed' ? 'Not sent' : 'Sending…') }}</span>
        </div>
    @empty
        <p class="small text-muted mb-0">No messages yet.</p>
    @endforelse
    @if($canMsgC)
        <form method="post" action="{{ route('customers.messages.store', $c) }}" class="mt-2">@csrf
            <textarea name="text" rows="2" maxlength="1000" required class="form-control form-control-sm" placeholder="Write to {{ $c->first_name }}…"></textarea>
            <div class="text-end mt-2"><button class="btn btn-light btn-sm"><i class="ri-send-plane-line me-1"></i>Send</button></div>
        </form>
    @endif
</x-cb.card>
