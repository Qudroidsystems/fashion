@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Fashion House Settings" icon="ri-settings-3-line"
               subtitle="Your business profile, branding, currency, timezone and document numbering."
               :back="route('dashboard')" back-label="Dashboard">
        <x-slot:pills>
            <span class="cb-meta-pill">Account: {{ $tenant->slug }}</span>
            <span class="cb-meta-pill">Status: {{ ucfirst(str_replace('_', ' ', $tenant->status)) }}</span>
        </x-slot:pills>
    </x-cb.hero>

    @if(session('success'))
        <div class="cb-banner success"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>
    @endif
    @if($errors->any())
        <div class="cb-banner danger"><i class="ri-error-warning-line"></i>
            <div>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
        </div>
    @endif

    <form method="POST" action="{{ route('settings.tenant.update') }}" enctype="multipart/form-data">
        @csrf @method('PUT')
        <div class="row g-3">
            <div class="col-xl-8">
                <x-cb.card title="Business profile" icon="ri-store-2-line">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Fashion house name *</label>
                            <input type="text" name="name" class="form-control" value="{{ old('name', $tenant->name) }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Business e-mail</label>
                            <input type="email" name="email" class="form-control" value="{{ old('email', $tenant->email) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="{{ old('phone', $tenant->phone) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Address</label>
                            <input type="text" name="address" class="form-control" value="{{ old('address', $tenant->address) }}">
                        </div>
                    </div>
                </x-cb.card>

                <x-cb.card title="Region & units" icon="ri-global-line" class="mt-3">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Timezone *</label>
                            <select name="timezone" class="form-select" required>
                                @foreach($timezones as $tz)
                                    <option value="{{ $tz }}" @selected(old('timezone', $tenant->timezone) === $tz)>{{ $tz }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Used for reminders, birthdays and dates on documents.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Operating currency *</label>
                            <select name="currency_code" class="form-select" required>
                                @foreach($currencies as $code => $label)
                                    <option value="{{ $code }}" @selected(old('currency_code', $tenant->currency_code) === $code)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Used for prices, costs and stock value.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Measurement display unit *</label>
                            <select name="measurement_display_unit" class="form-select" required>
                                @foreach($units as $code => $label)
                                    <option value="{{ $code }}" @selected(old('measurement_display_unit', $tenant->setting('measurement_display_unit', 'in')) === $code)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Display only — measurements are stored in one canonical unit.</div>
                        </div>
                    </div>
                </x-cb.card>

                <x-cb.card title="Stock control" icon="ri-archive-line" class="mt-3">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Adjustment approval limit</label>
                            <div class="input-group"><span class="input-group-text">{{ \App\Support\Money::symbol($tenant->currency_code) }}</span>
                                <input type="number" min="0" step="1" name="stock_approval_threshold" class="form-control" value="{{ old('stock_approval_threshold', $tenant->setting('stock_approval_threshold', 20000)) }}"></div>
                            <div class="form-text">Stock adjustments, wastage and count differences worth more than this must be approved by someone other than the person who submitted them. In a house with only one approver, that person may approve their own and it is noted in the audit log.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Purchase order approval limit</label>
                            <div class="input-group"><span class="input-group-text">{{ \App\Support\Money::symbol($tenant->currency_code) }}</span>
                                <input type="number" min="0" step="1" name="po_approval_threshold" class="form-control" value="{{ old('po_approval_threshold', $tenant->setting('po_approval_threshold', 100000)) }}"></div>
                            <div class="form-text">Purchase orders above this need a second person to approve them.</div>
                        </div>
                        <div class="col-12">
                            <label class="d-flex gap-2"><input type="hidden" name="allow_negative_stock" value="0"><input type="checkbox" name="allow_negative_stock" value="1" @checked(old('allow_negative_stock', $tenant->setting('allow_negative_stock', false)))> Allow stock to go below zero</label>
                            <div class="form-text">Leave off so the system refuses to issue or sell more than you have. Turn on only while you are still entering opening balances.</div>
                        </div>
                    </div>
                </x-cb.card>

                <x-cb.card title="Document numbering" icon="ri-hashtag" class="mt-3">
                    <p class="small text-muted">Tokens: <code>{YYYY}</code> year, <code>{MM}</code> month, <code>{SEQ:5}</code> running number padded to 5 digits. Numbers are unique within your fashion house.</p>
                    <div class="row g-3">
                        @foreach(['customer' => 'Customer numbers', 'style' => 'Style codes', 'product' => 'Product codes', 'quotation' => 'Quotations', 'order' => 'Orders', 'invoice' => 'Invoices', 'receipt' => 'Receipts', 'item' => 'Stock item codes', 'supplier' => 'Supplier codes', 'purchase_order' => 'Purchase orders', 'goods_receipt' => 'Goods received notes', 'stock_transfer' => 'Stock transfers', 'stock_adjustment' => 'Stock adjustments & counts', 'material_issue' => 'Material issues'] as $key => $label)
                            <div class="col-md-6">
                                <label class="form-label">{{ $label }}</label>
                                <input type="text" name="numbering[{{ $key }}]" class="form-control font-monospace"
                                       value="{{ old('numbering.' . $key, $tenant->setting('numbering.' . $key) ?: \App\Services\Numbering\DocumentNumber::DEFAULT_FORMATS[$key]) }}">
                            </div>
                        @endforeach
                    </div>
                </x-cb.card>
            </div>

            <div class="col-xl-4">
                <x-cb.card title="Branding" icon="ri-palette-line">
                    <div class="text-center mb-3">
                        @if($tenant->logo_url)
                            <img src="{{ $tenant->logo_url }}" alt="Logo" style="max-height:110px;max-width:100%;border-radius:12px;background:#f8fafc;padding:6px;">
                        @else
                            <div class="d-inline-flex align-items-center justify-content-center rounded-3"
                                 style="width:110px;height:110px;background:{{ $tenant->brand_color ?: '#405189' }};color:#fff;font-size:36px;font-weight:700;">
                                {{ $tenant->initials }}
                            </div>
                        @endif
                    </div>
                    <label class="form-label">Logo (PNG/JPG/WebP, max 2 MB)</label>
                    <input type="file" name="logo" class="form-control mb-2" accept="image/png,image/jpeg,image/webp">
                    @if($tenant->logo_path)
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="remove_logo">
                            <label class="form-check-label small" for="remove_logo">Remove current logo</label>
                        </div>
                    @endif
                    <label class="form-label">Brand colour</label>
                    <input type="color" name="brand_color" class="form-control form-control-color w-100" value="{{ old('brand_color', $tenant->brand_color ?: '#405189') }}">
                    <div class="form-text">Used on receipts, invoices and the customer portal.</div>
                </x-cb.card>

                <div class="d-grid mt-3">
                    <button type="submit" class="action-btn btn-primary-cb justify-content-center py-2"><i class="ri-save-line"></i> Save settings</button>
                </div>
            </div>
        </div>
    </form>

</div>
</div>
</div>
@endsection
