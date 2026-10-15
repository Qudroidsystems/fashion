{{-- Customer › Orders tab. Expects $oOrders, $oQuotes, $oMoney. --}}
@php use App\Support\Money; $active = $c->status !== 'merged'; @endphp
<div class="tab-pane" id="orders">
    @if($oMoney)
        <div class="row g-3 mb-3">
            <div class="col-md-4"><x-cb.stat label="Owes" :value="Money::format($oMoney['owed'])" icon="ri-alarm-warning-line" :accent="$oMoney['owed'] > 0 ? 'rose' : 'teal'" /></div>
            <div class="col-md-4"><x-cb.stat label="Credit" :value="Money::format($oMoney['credit'])" icon="ri-coins-line" accent="violet" hint="paid but not used yet" /></div>
            <div class="col-md-4"><x-cb.stat label="Paid to date" :value="Money::format($oMoney['paid'])" icon="ri-bank-card-line" accent="teal" /></div>
        </div>
    @endif

    <x-cb.card title="Orders" icon="ri-file-list-3-line" :count="$oOrders->count()" :flush="true">
        <x-slot:tools>
            @if($active)
                @can('Create order')<a href="{{ route('orders.create', ['customer' => $c->public_id]) }}" class="action-btn btn-primary-cb"><i class="ri-add-line"></i>New order</a>@endcan
                @can('Manage quotation')<a href="{{ route('quotations.create', ['customer' => $c->public_id]) }}" class="action-btn"><i class="ri-draft-line"></i>Quotation</a>@endcan
                @can('Record payment')<a href="{{ route('payments.create', ['customer' => $c->public_id]) }}" class="action-btn"><i class="ri-bank-card-line"></i>Payment</a>@endcan
            @endif
        </x-slot:tools>
        @if($oQuotes->isNotEmpty())
            <div class="px-3 pt-3">
                @foreach($oQuotes as $q)
                    <a href="{{ route('quotations.show', $q) }}" class="d-flex justify-content-between small py-1 text-body">
                        <span><i class="ri-draft-line me-1"></i>Quotation {{ $q->number }} {{ $q->title ? '· ' . $q->title : '' }}</span>
                        <span>{{ Money::format($q->total) }} <span class="status-pill {{ $q->status_pill }} ms-1">{{ $q->status_label }}</span></span></a>
                @endforeach
            </div>
        @endif
        @if($oOrders->isEmpty())
            <p class="text-muted small p-3 mb-0">No orders yet.</p>
        @else
        <div class="table-responsive"><table class="cb-table stack mb-0">
            <thead><tr><th>Order</th><th>Promised</th><th class="text-end">Items</th>@can('View payment')<th class="text-end">Total</th><th class="text-end">Balance</th>@endcan<th>Status</th></tr></thead>
            <tbody>
            @foreach($oOrders as $o)
                <tr onclick="location.href='{{ route('orders.show', $o) }}'" style="cursor:pointer">
                    <td data-label="Order"><a href="{{ route('orders.show', $o) }}" class="fw-semibold">{{ $o->number }}</a><div class="small text-muted">{{ $o->title }}</div></td>
                    <td data-label="Promised" class="{{ $o->isLate() ? 'text-danger fw-semibold' : '' }}">{{ $o->promised_date?->format('d M Y') ?? '—' }}</td>
                    <td data-label="Items" class="text-end">{{ $o->lines_count }}</td>
                    @can('View payment')
                        @php $b = $o->balance(); @endphp
                        <td data-label="Total" class="text-end">{{ Money::format($o->total) }}</td>
                        <td data-label="Balance" class="text-end {{ $b > 0 ? 'text-danger fw-semibold' : 'text-muted' }}">{{ $b == 0 ? '—' : Money::format($b) }}</td>
                    @endcan
                    <td data-label="Status"><span class="status-pill {{ $o->status_pill }}">{{ $o->status_label }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @endif
    </x-cb.card>
</div>
