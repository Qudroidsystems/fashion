@extends('sites.account._layout')

@section('account')
    <div class="row g-4">
        @foreach($account->addresses as $a)
            <div class="col-md-6">
                <div class="store-card h-100">
                    <h6>{{ $a->label ?: 'Address' }} @if($a->is_default)<span class="store-pill is-good">Default</span>@endif</h6>
                    <p class="mb-2">{{ $a->name }}<br><span class="text-secondary">{{ $a->one_line }}</span>@if($a->phone)<br><span class="text-secondary">{{ $a->phone }}</span>@endif</p>
                    <form method="POST" action="{{ $base }}/account/addresses/{{ $a->id }}" onsubmit="return confirm('Remove this address?')">@csrf @method('DELETE')<button class="btn btn-link p-0 text-secondary text-caption-1">Remove</button></form>
                </div>
            </div>
        @endforeach
        <div class="col-12">
            <form method="POST" action="{{ $base }}/account/addresses" class="store-form store-card">
                @csrf
                <h6>Add an address</h6>
                <div class="grid-2"><input type="text" name="label" placeholder="Label (Home, Office…)" maxlength="40" aria-label="Label"><input type="text" name="name" value="{{ $account->name }}" placeholder="Recipient name *" required maxlength="120" aria-label="Recipient"></div>
                <input type="text" name="line1" placeholder="Street address *" required maxlength="190" aria-label="Street address">
                <input type="text" name="line2" placeholder="Apartment, landmark" maxlength="190" aria-label="Address line 2">
                <div class="grid-2">
                    <input type="text" name="city" placeholder="Town / city *" required maxlength="80" aria-label="City">
                    @if($states)<div class="tf-select"><select name="state" aria-label="State"><option value="">State</option>@foreach($states as $st)<option>{{ $st }}</option>@endforeach</select></div>
                    @else<input type="text" name="state" placeholder="State / region" maxlength="80" aria-label="State">@endif
                </div>
                <div class="grid-2"><input type="tel" name="phone" value="{{ $account->phone }}" placeholder="Phone" maxlength="30" aria-label="Phone"><input type="text" name="postal_code" placeholder="Postal code" maxlength="20" aria-label="Postal code"></div>
                <label class="d-flex gap-2 text-caption-1"><input type="checkbox" name="is_default" value="1" class="tf-check"> Make this my default address</label>
                @if($errors->any())<div class="store-err">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
                <button class="tf-btn btn-fill"><span class="text">Save address</span></button>
            </form>
        </div>
    </div>
@endsection
