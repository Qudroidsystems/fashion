@extends('sites.layout')
@section('title', 'Contact')

@section('body')
    @php
        $titles = ['enquiry' => 'Send us a message', 'booking' => 'Book a fitting', 'measurements' => 'Send us your measurements'];
        $about = $aboutStyle?->name ?? $aboutProduct?->name;
    @endphp
    @include('sites.partials.page-title', ['title' => $titles[$type]])
    <section class="flat-spacing">
        <div class="container">
            <div class="row g-5">
                <div class="col-lg-5">
                    <h4 class="mb-3">{{ $titles[$type] }}</h4>
                    <p class="text-secondary mb-4">
                        @if($type === 'booking')Tell us when suits you and we will confirm a time.
                        @elseif($type === 'measurements')Leave your details and we will send you a private link to enter your measurements, with a guide for each one.
                        @else We usually reply the same day.@endif
                    </p>
                    <ul class="store-contact">
                        @if($house->phone)<li><i class="icon icon-phone"></i><span><strong>Call</strong><br><a href="tel:{{ preg_replace('/[^0-9+]/', '', $house->phone) }}" class="link">{{ $house->phone }}</a></span></li>@endif
                        @if($waNumber)<li><i class="icon icon-whatsapp"></i><span><strong>WhatsApp</strong><br><a href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener" class="link">Chat with us</a></span></li>@endif
                        @if($house->email)<li><i class="icon icon-mail"></i><span><strong>E-mail</strong><br><a href="mailto:{{ $house->email }}" class="link">{{ $house->email }}</a></span></li>@endif
                        @foreach($branches->take(3) as $b)<li><i class="icon icon-map-pin"></i><span><strong>{{ $b->name }}</strong><br>{{ collect([$b->address, $b->city])->filter()->implode(', ') }}</span></li>@endforeach
                    </ul>
                    @if(! empty($site['hours']))<p class="text-secondary mt-3" style="white-space:pre-line">{{ $site['hours'] }}</p>@endif
                </div>
                <div class="col-lg-7">
                    @if(session('sent'))
                        <div class="store-card" role="status"><h5>Thank you — your message is with {{ $house->name }}.</h5><p class="mb-0 text-secondary">They will get back to you soon.</p></div>
                    @else
                        @if($full)
                            <div class="store-kinds" role="tablist" aria-label="What would you like?">
                                @foreach($titles as $k => $t)<a href="?type={{ $k }}{{ $aboutStyle ? '&style=' . $aboutStyle->public_id : '' }}" aria-current="{{ $type === $k ? 'true' : 'false' }}">{{ ['enquiry' => 'Message', 'booking' => 'Fitting', 'measurements' => 'Measurements'][$k] }}</a>@endforeach
                            </div>
                        @endif
                        <form method="POST" action="{{ $base }}/contact" class="store-form" novalidate>
                            @csrf
                            <input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="_t" value="{{ time() }}">
                            @if($aboutStyle)<input type="hidden" name="style" value="{{ $aboutStyle->public_id }}">@endif
                            @if($aboutProduct)<input type="hidden" name="product" value="{{ $aboutProduct->public_id }}">@endif
                            <div class="d-none" aria-hidden="true"><label>Leave empty<input name="website" tabindex="-1" autocomplete="off"></label></div>
                            @if($about)<p class="store-alert is-info">About: <strong>{{ $about }}</strong></p>@endif
                            <label for="name">Your name</label><input id="name" name="name" required maxlength="120" value="{{ old('name') }}" autocomplete="name">@error('name')<div class="store-err">{{ $message }}</div>@enderror
                            <div class="grid-2">
                                <div><label for="phone">Phone or WhatsApp</label><input id="phone" name="phone" maxlength="30" value="{{ old('phone') }}" inputmode="tel" autocomplete="tel">@error('phone')<div class="store-err">{{ $message }}</div>@enderror</div>
                                <div><label for="email">E-mail</label><input id="email" name="email" type="email" maxlength="160" value="{{ old('email') }}" autocomplete="email">@error('email')<div class="store-err">{{ $message }}</div>@enderror</div>
                            </div>
                            @if($type === 'booking')
                                <div class="grid-2">
                                    <div><label for="preferred_date">Preferred day</label><input id="preferred_date" name="preferred_date" type="date" min="{{ now()->toDateString() }}" value="{{ old('preferred_date') }}">@error('preferred_date')<div class="store-err">{{ $message }}</div>@enderror</div>
                                    <div><label for="preferred_time">Time</label><div class="tf-select"><select id="preferred_time" name="preferred_time"><option>Morning</option><option>Afternoon</option><option>Evening</option></select></div></div>
                                </div>
                            @endif
                            <label for="message">{{ $type === 'booking' ? 'What is the fitting for?' : ($type === 'measurements' ? 'Anything we should know?' : 'Your message') }}</label>
                            <textarea id="message" name="message" maxlength="3000" rows="5">{{ old('message') }}</textarea>@error('message')<div class="store-err">{{ $message }}</div>@enderror
                            <button class="tf-btn btn-fill w-100"><span class="text">{{ ['enquiry' => 'Send message', 'booking' => 'Request this time', 'measurements' => 'Send me the link'][$type] }}</span></button>
                            <p class="text-caption-1 text-secondary mt-2">{{ $house->name }} uses your details only to reply to you.</p>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </section>
@endsection
