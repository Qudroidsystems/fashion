@extends('sites.layout')
@section('title', 'Contact')

@push('css')
    .contact { display: grid; grid-template-columns: 1fr 1fr; gap: 3.5rem; align-items: start; }
    .kinds { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1.4rem; }
    .kinds a { padding: .5rem 1rem; border-radius: 999px; border: 1.5px solid var(--line); text-decoration: none; font-weight: 600; font-size: .92rem; }
    .kinds a[aria-current="true"] { border-color: var(--accent); color: var(--accent); }
    .two { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    .hp { position: absolute; left: -9999px; }
    @media (max-width: 900px) { .contact, .two { grid-template-columns: 1fr; } }
@endpush

@section('body')
    @php
        $titles = ['enquiry' => 'Send us a message', 'booking' => 'Book a fitting', 'measurements' => 'Send us your measurements'];
        $about = $aboutStyle?->name ?? $aboutProduct?->name;
    @endphp
    <section>
        <div class="wrap contact">
            <div>
                <h1 style="font-size:clamp(2.4rem,5vw,4rem)">{{ $titles[$type] }}</h1>
                <p class="muted" style="margin:1rem 0 2rem;max-width:46ch">
                    @if($type === 'booking')Tell us when suits you and we will confirm a time.
                    @elseif($type === 'measurements')Leave your details and we will send you a private link to enter your measurements, with a guide for each one.
                    @else We usually reply the same day.@endif
                </p>
                @if($house->phone)<p><strong>Call</strong> <a href="tel:{{ preg_replace('/[^0-9+]/', '', $house->phone) }}">{{ $house->phone }}</a></p>@endif
                @if($house->email)<p><strong>E-mail</strong> <a href="mailto:{{ $house->email }}">{{ $house->email }}</a></p>@endif
                @foreach($branches->take(3) as $b)<p class="muted" style="margin-top:.6rem">{{ $b->name }}{{ $b->address ? ' — ' . $b->address : '' }}{{ $b->city ? ', ' . $b->city : '' }}</p>@endforeach
                @if(! empty($site['hours']))<p class="muted" style="margin-top:1rem;white-space:pre-line">{{ $site['hours'] }}</p>@endif
            </div>
            <div>
                @if(session('sent'))
                    <div class="notice" role="status"><strong>Thank you — your message is with {{ $house->name }}.</strong><br>They will get back to you soon.</div>
                @else
                    @if($full)
                        <div class="kinds" role="tablist" aria-label="What would you like?">
                            @foreach($titles as $k => $t)<a href="?type={{ $k }}{{ $aboutStyle ? '&style=' . $aboutStyle->public_id : '' }}" aria-current="{{ $type === $k ? 'true' : 'false' }}">{{ ['enquiry' => 'Message', 'booking' => 'Fitting', 'measurements' => 'Measurements'][$k] }}</a>@endforeach
                        </div>
                    @endif
                    <form method="POST" action="{{ $base }}/contact" novalidate>
                        @csrf
                        <input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="_t" value="{{ time() }}">
                        @if($aboutStyle)<input type="hidden" name="style" value="{{ $aboutStyle->public_id }}">@endif
                        @if($aboutProduct)<input type="hidden" name="product" value="{{ $aboutProduct->public_id }}">@endif
                        <div class="hp" aria-hidden="true"><label>Leave empty<input name="website" tabindex="-1" autocomplete="off"></label></div>
                        @if($about)<p class="notice" style="margin-bottom:1.2rem">About: <strong>{{ $about }}</strong></p>@endif
                        <div class="field"><label for="name">Your name</label><input id="name" name="name" required maxlength="120" value="{{ old('name') }}" autocomplete="name">@error('name')<div class="err">{{ $message }}</div>@enderror</div>
                        <div class="two">
                            <div class="field"><label for="phone">Phone or WhatsApp</label><input id="phone" name="phone" maxlength="30" value="{{ old('phone') }}" inputmode="tel" autocomplete="tel">@error('phone')<div class="err">{{ $message }}</div>@enderror</div>
                            <div class="field"><label for="email">E-mail</label><input id="email" name="email" type="email" maxlength="160" value="{{ old('email') }}" autocomplete="email">@error('email')<div class="err">{{ $message }}</div>@enderror</div>
                        </div>
                        @if($type === 'booking')
                            <div class="two">
                                <div class="field"><label for="preferred_date">Preferred day</label><input id="preferred_date" name="preferred_date" type="date" min="{{ now()->toDateString() }}" value="{{ old('preferred_date') }}">@error('preferred_date')<div class="err">{{ $message }}</div>@enderror</div>
                                <div class="field"><label for="preferred_time">Time</label><select id="preferred_time" name="preferred_time"><option>Morning</option><option>Afternoon</option><option>Evening</option></select></div>
                            </div>
                        @endif
                        <div class="field"><label for="message">{{ $type === 'booking' ? 'What is the fitting for?' : ($type === 'measurements' ? 'Anything we should know?' : 'Your message') }}</label>
                            <textarea id="message" name="message" maxlength="3000">{{ old('message') }}</textarea>@error('message')<div class="err">{{ $message }}</div>@enderror</div>
                        <button class="btn" style="width:100%">{{ ['enquiry' => 'Send message', 'booking' => 'Request this time', 'measurements' => 'Send me the link'][$type] }}</button>
                        <p class="muted" style="font-size:.82rem;margin-top:.8rem">{{ $house->name }} uses your details only to reply to you.</p>
                    </form>
                @endif
            </div>
        </div>
    </section>
@endsection
