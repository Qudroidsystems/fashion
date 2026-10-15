@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content pos-page">
<div class="container-fluid">

<style>
    .pos-page .pos-grid { display:grid; grid-template-columns: minmax(0,1fr) 420px; gap:16px; }
    @media (max-width: 1199px) { .pos-page .pos-grid { grid-template-columns: 1fr; } }
    .pos-scan { font-size:1.15rem; padding:.8rem 1rem .8rem 2.6rem; border-radius:14px; }
    .pos-scan-wrap { position:relative; } .pos-scan-wrap i { position:absolute; left:14px; top:50%; transform:translateY(-50%); font-size:1.2rem; color:#8a94a6; }
    .pos-products { display:grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap:12px; }
    .pos-card { background:var(--cb-surface); color:var(--cb-text); border:1px solid var(--cb-border); border-radius:14px; padding:10px; cursor:pointer; text-align:left; transition:transform .08s, box-shadow .08s; }
    .pos-card:hover { transform:translateY(-1px); box-shadow:0 6px 16px rgba(17,24,39,.08); }
    .pos-card:disabled { opacity:.45; cursor:not-allowed; }
    .pos-thumb { aspect-ratio: 4/3; border-radius:10px; background:var(--cb-surface-2) center/cover no-repeat; display:grid; place-items:center; color:#a0a8b8; font-size:28px; margin-bottom:8px; }
    .pos-cart { background:var(--cb-surface); border-radius:18px; border:1px solid var(--cb-border); display:flex; flex-direction:column; position:sticky; top:86px; max-height:calc(100vh - 110px); }
    .pos-lines { overflow:auto; flex:1; min-height:120px; }
    .pos-line { display:grid; grid-template-columns: 1fr auto; gap:4px 10px; padding:10px 14px; border-bottom:1px solid var(--cb-border); }
    .pos-qty { display:inline-flex; align-items:center; border:1px solid var(--cb-border); border-radius:10px; overflow:hidden; }
    .pos-qty button { border:0; background:var(--cb-surface-2); color:var(--cb-text); width:30px; height:30px; } .pos-qty input { width:46px; border:0; background:transparent; color:var(--cb-text); text-align:center; height:30px; }
    .pos-total { font-size:2rem; font-weight:700; letter-spacing:-.02em; }
    .pos-tender input { font-size:1.05rem; }
    .pos-kbd { font-size:11px; border:1px solid #d6dbe4; border-bottom-width:2px; border-radius:5px; padding:0 5px; color:var(--cb-muted); background:var(--cb-surface); }
    @media (max-width: 1199px) { .pos-cart { position:static; max-height:none; } }
    @media (max-width: 767px) {
        .pos-products { grid-template-columns: repeat(2, minmax(0,1fr)); gap:8px; max-height:52vh; overflow:auto; }
        .pos-thumb { aspect-ratio: 16/9; font-size:20px; margin-bottom:6px; }
        .pos-card { padding:8px; }
        .pos-total { font-size:1.6rem; }
    }
</style>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h4 class="mb-0"><i class="ri-shopping-cart-2-line me-1"></i>Point of sale</h4>
            <div class="small text-muted">{{ $shift->branch->name }} · shift {{ $shift->number }} · <span id="posSummary">{{ $summary['sales_count'] }} sale(s), {{ \App\Support\Money::format($summary['sales_total']) }}</span></div>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('pos.sales.index') }}" class="action-btn"><i class="ri-receipt-line"></i>Sales</a>
            <a href="{{ route('pos.shifts.show', $shift) }}" class="action-btn"><i class="ri-safe-2-line"></i>Shift & close</a>
        </div>
    </div>

    <div class="pos-grid">
        <div>
            <div class="pos-scan-wrap mb-3">
                <i class="ri-barcode-line"></i>
                <input id="posScan" class="form-control pos-scan" placeholder="Scan a barcode or type a name / SKU…" autocomplete="off" autofocus>
            </div>
            <div class="d-flex gap-2 mb-3 flex-wrap">
                <button type="button" class="action-btn" id="posAddOrder"><i class="ri-file-list-3-line"></i>Payment on an order</button>
                <span class="small text-muted align-self-center"><span class="pos-kbd">Enter</span> add first match · <span class="pos-kbd">F9</span> pay</span>
            </div>
            <div id="posProducts" class="pos-products"></div>
            <div id="posEmpty" class="empty-state d-none"><i class="ri-search-line"></i><h6>Nothing found</h6></div>
        </div>

        <div class="pos-cart">
            <div class="p-3 border-bottom">
                <div class="d-flex gap-2 align-items-center">
                    <i class="ri-user-line text-muted"></i>
                    <input id="posCustomerSearch" class="form-control form-control-sm" placeholder="Walk-in customer — search to attach" autocomplete="off">
                    <button type="button" id="posCustomerClear" class="btn btn-sm btn-light d-none" title="Remove"><i class="ri-close-line"></i></button>
                </div>
                <div id="posCustomerResults" class="list-group mt-1 position-absolute shadow-sm" style="z-index:20;width:380px;max-width:calc(100vw - 48px)"></div>
            </div>
            <div class="pos-lines" id="posLines"><div class="text-center text-muted small p-4" id="posLinesEmpty">Scan or tap an item to start.</div></div>
            <div class="p-3 border-top">
                <div class="d-flex justify-content-between small text-muted"><span>Subtotal</span><span id="posSub">0</span></div>
                <div class="d-flex justify-content-between small text-muted"><span>Discounts</span><span id="posDisc">0</span></div>
                <div class="d-flex justify-content-between align-items-end mt-1"><span class="fw-semibold">Total</span><span class="pos-total" id="posTotal">{{ $currency }}0</span></div>
            </div>
            <div class="p-3 border-top pos-tender">
                <div class="row g-2">
                    <div class="col-6"><label class="small text-muted mb-0">Cash received</label><input type="number" min="0" step="0.01" class="form-control" data-method="cash" id="tCash"></div>
                    <div class="col-6"><label class="small text-muted mb-0">Card / POS</label><input type="number" min="0" step="0.01" class="form-control" data-method="card" id="tCard"></div>
                    <div class="col-6"><label class="small text-muted mb-0">Transfer</label><input type="number" min="0" step="0.01" class="form-control" data-method="bank_transfer" id="tTransfer"></div>
                    <div class="col-6"><label class="small text-muted mb-0">Mobile money</label><input type="number" min="0" step="0.01" class="form-control" data-method="mobile_money" id="tMobile"></div>
                    <div class="col-12"><input class="form-control form-control-sm" id="tRef" placeholder="Card / transfer reference (optional)" maxlength="120"></div>
                </div>
                <div class="d-flex gap-1 mt-2 flex-wrap" id="posQuickCash"></div>
                <div class="d-flex justify-content-between mt-2"><span class="small text-muted" id="posDueLabel">To pay</span><span class="fw-semibold" id="posDue">0</span></div>
                <div class="alert alert-danger py-2 small mt-2 d-none" id="posError"></div>
                <button type="button" class="btn btn-lg w-100 mt-2 text-white fw-semibold" style="background:var(--cb-teal,#0d9488)" id="posPay" disabled><i class="ri-check-double-line me-1"></i>Complete sale</button>
                <button type="button" class="btn btn-link btn-sm w-100 text-muted" id="posClear">Clear basket</button>
            </div>
        </div>
    </div>

    {{-- Order payment picker --}}
    <div class="modal fade" id="orderModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Payment on an order</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <input id="orderSearch" class="form-control mb-2" placeholder="Order number or customer…" autocomplete="off">
            <div id="orderResults" class="list-group"></div>
        </div>
    </div></div></div>

    {{-- Done --}}
    <div class="modal fade" id="doneModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-dialog-centered"><div class="modal-content text-center p-4">
        <i class="ri-checkbox-circle-fill" style="font-size:56px;color:var(--cb-teal,#0d9488)"></i>
        <h4 class="mt-2 mb-1" id="doneNumber"></h4>
        <div class="fs-5" id="doneChange"></div>
        <div class="d-flex gap-2 justify-content-center mt-3">
            <a href="#" target="_blank" class="btn btn-light" id="doneReceipt"><i class="ri-printer-line me-1"></i>Print receipt</a>
            <button type="button" class="btn btn-primary" id="doneNext">Next sale <span class="pos-kbd ms-1">Enter</span></button>
        </div>
    </div></div></div>

</div>
</div>
</div>
@push('scripts')
<script>
window.POS = {
    currency: @json($currency),
    canOverride: @json($canOverride),
    urls: { products: @json(route('pos.products')), customers: @json(route('pos.customers')), orders: @json(route('pos.orders')), sale: @json(route('pos.sales.store')) },
    csrf: @json(csrf_token()),
};
</script>
<script src="{{ asset('js/pos.js') }}?v=2"></script>
@endpush
@endsection
