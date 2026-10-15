@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php
        $editing = $customer->exists;
        $consentMap = $customer->consents?->mapWithKeys(fn ($c) => [$c->channel . '.' . $c->purpose => $c->granted]) ?? collect();
        $tagValue = old('tags', $customer->tags?->pluck('name')->implode(', ') ?? '');
        $tagValue = is_array($tagValue) ? implode(', ', $tagValue) : $tagValue;
    @endphp

    <x-cb.hero :title="$editing ? 'Edit ' . $customer->name : 'New customer'" icon="ri-user-add-line"
               :subtitle="$editing ? $customer->customer_number : 'A customer number is assigned automatically.'"
               :back="$editing ? route('customers.show', $customer) : route('customers.index')" :back-label="$editing ? 'Back to customer' : 'Customers'" />

    @if($errors->any())
        <div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>
    @endif

    @if(session('duplicates'))
        <div class="cb-banner warning">
            <i class="ri-user-shared-line"></i>
            <div class="flex-grow-1">
                <strong>This may be a customer you already have.</strong>
                <ul class="mb-2 mt-1">
                    @foreach(session('duplicates') as $d)
                        <li><a href="{{ $d['url'] }}" target="_blank">{{ $d['name'] }} ({{ $d['number'] }})</a>
                            — same {{ implode(' and ', $d['reasons']) }} · {{ $d['phone'] }} {{ $d['email'] }}</li>
                    @endforeach
                </ul>
                Open the existing record instead, or save this as a separate customer below.
            </div>
        </div>
    @endif

    <form method="POST" action="{{ $editing ? route('customers.update', $customer) : route('customers.store') }}">
        @csrf
        @if($editing) @method('PUT') @endif
        @if(session('duplicates'))<input type="hidden" name="confirm_duplicate" value="1">@endif

        <div class="row g-3">
            <div class="col-xl-8">
                <x-cb.card title="Personal details" icon="ri-user-line">
                    <div class="row g-3">
                        <div class="col-md-2">
                            <label class="form-label">Title</label>
                            <input name="title" class="form-control" list="titles" value="{{ old('title', $customer->title) }}" placeholder="Mrs">
                            <datalist id="titles"><option>Mr</option><option>Mrs</option><option>Miss</option><option>Ms</option><option>Dr</option><option>Chief</option><option>Prof</option><option>Alhaji</option><option>Alhaja</option></datalist>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">First name *</label>
                            <input name="first_name" class="form-control" required value="{{ old('first_name', $customer->first_name) }}">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Last name</label>
                            <input name="last_name" class="form-control" value="{{ old('last_name', $customer->last_name) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Phone *</label>
                            <input name="phone" class="form-control" value="{{ old('phone', $customer->phone) }}" placeholder="0803…">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">WhatsApp</label>
                            <input name="whatsapp" class="form-control" value="{{ old('whatsapp', $customer->whatsapp) }}" placeholder="if different">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Other phone</label>
                            <input name="alt_phone" class="form-control" value="{{ old('alt_phone', $customer->alt_phone) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">E-mail</label>
                            <input type="email" name="email" class="form-control" value="{{ old('email', $customer->email) }}">
                            <div class="form-text">A phone number or an e-mail is required.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Gender</label>
                            <select name="gender" class="form-select">
                                <option value="">—</option>
                                @foreach(['female' => 'Female', 'male' => 'Male', 'other' => 'Other'] as $v => $l)
                                    <option value="{{ $v }}" @selected(old('gender', $customer->gender) === $v)>{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Birthday <span class="text-muted">(optional)</span></label>
                            <input type="date" name="birthday" class="form-control" value="{{ old('birthday', $customer->birthday?->format('Y-m-d')) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Address</label>
                            <input name="address" class="form-control" value="{{ old('address', $customer->address) }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">City</label>
                            <input name="city" class="form-control" value="{{ old('city', $customer->city) }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">State</label>
                            <input name="state" class="form-control" value="{{ old('state', $customer->state) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Occupation</label>
                            <input name="occupation" class="form-control" value="{{ old('occupation', $customer->occupation) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">How did they find you?</label>
                            <select name="source" class="form-select">
                                <option value="">—</option>
                                @foreach(\App\Models\Customer::SOURCES as $v => $l)
                                    <option value="{{ $v }}" @selected(old('source', $customer->source) === $v)>{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </x-cb.card>

                <x-cb.card title="Style preferences" icon="ri-t-shirt-line" class="mt-3">
                    <div class="row g-3">
                        @foreach(\App\Models\Customer::PREFERENCE_FIELDS as $key => $label)
                            <div class="col-md-6">
                                <label class="form-label">{{ $label }}</label>
                                <input name="preferences[{{ $key }}]" class="form-control" value="{{ old('preferences.' . $key, $customer->preferences[$key] ?? '') }}">
                            </div>
                        @endforeach
                    </div>
                </x-cb.card>
            </div>

            <div class="col-xl-4">
                <x-cb.card title="In your house" icon="ri-store-2-line">
                    <div class="mb-3">
                        <label class="form-label">Branch</label>
                        <select name="branch_id" class="form-select">
                            <option value="">—</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" @selected(old('branch_id', $customer->branch_id ?? \App\Support\Tenancy\CurrentTenant::membership()?->branch_id) == $b->id)>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Account manager</label>
                        <select name="assigned_to" class="form-select">
                            <option value="">—</option>
                            @foreach($staff as $s)
                                <option value="{{ $s->id }}" @selected(old('assigned_to', $customer->assigned_to) == $s->id)>{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tags</label>
                        <input name="tags" class="form-control" list="tagList" value="{{ $tagValue }}" placeholder="bride, corporate, referral…">
                        <datalist id="tagList">@foreach($allTags as $t)<option value="{{ $t }}">@endforeach</datalist>
                        <div class="form-text">Separate with commas. New tags are created automatically.</div>
                    </div>
                    <div class="form-check form-switch">
                        <input type="hidden" name="is_vip" value="0">
                        <input class="form-check-input" type="checkbox" name="is_vip" value="1" id="is_vip" @checked(old('is_vip', $customer->is_vip))>
                        <label class="form-check-label" for="is_vip">VIP customer</label>
                    </div>
                </x-cb.card>

                <x-cb.card title="Contact consent" icon="ri-shield-check-line" class="mt-3">
                    <p class="small text-muted">Record what the customer agreed to. Promotions are only sent where marketing consent is “yes”.</p>
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th></th>@foreach($purposes as $p => $pl)<th class="small text-center">{{ $p === 'marketing' ? 'Promotions' : 'Updates' }}</th>@endforeach</tr></thead>
                        <tbody>
                        @foreach($channels as $ch => $chl)
                            <tr>
                                <td class="small">{{ $chl }}</td>
                                @foreach($purposes as $p => $pl)
                                    @php $checked = old("consents.$ch.$p", $consentMap["$ch.$p"] ?? ($p === 'transactional' && ! $editing)); @endphp
                                    <td class="text-center">
                                        <input type="hidden" name="consents[{{ $ch }}][{{ $p }}]" value="0">
                                        <input type="checkbox" class="form-check-input" name="consents[{{ $ch }}][{{ $p }}]" value="1" @checked($checked)>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </x-cb.card>

                <div class="d-grid mt-3">
                    <button class="action-btn btn-primary-cb justify-content-center py-2">
                        <i class="ri-save-line"></i>{{ session('duplicates') ? 'Save as a separate customer' : ($editing ? 'Save changes' : 'Add customer') }}
                    </button>
                </div>
            </div>
        </div>
    </form>

</div>
</div>
</div>
@endsection
