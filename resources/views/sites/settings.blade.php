@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    @php $featured = (array) ($site['featured_style_ids'] ?? []); @endphp
    <x-cb.hero title="Website" icon="ri-global-line" subtitle="Your public site: your collection, ready-to-wear pieces and a way for new customers to reach you.">
        <x-slot:actions>@if($live)<a href="{{ $url }}" target="_blank" rel="noopener" class="cb-hero-btn"><i class="ri-external-link-line me-1"></i>Open my site</a>@endif</x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.flash')
    @if(! $allowed)<div class="cb-banner warning"><i class="ri-lock-line"></i><div>{{ app(\App\Services\Billing\Entitlements::class)->upgradeMessage($tenant, 'website') }} <a href="{{ route('billing.index') }}">See plans</a></div></div>@endif

    <form method="POST" action="{{ route('website.update') }}" enctype="multipart/form-data">@csrf @method('PUT')
    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="Address" icon="ri-link">
                <label class="d-flex gap-2 mb-3 fw-semibold"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $site['enabled'] ?? false))> The website is switched on</label>
                <label class="form-label">Your address</label>
                <div class="input-group">
                    @if($subdomains)<span class="input-group-text">https://</span>@else<span class="input-group-text">{{ url('/s') }}/</span>@endif
                    <input name="slug" class="form-control font-monospace" required minlength="3" maxlength="40" value="{{ old('slug', $tenant->slug) }}">
                    @if($subdomains)<span class="input-group-text">.{{ config('sites.domain') }}</span>@endif
                </div>
                <div class="form-text">Lower-case letters, numbers and dashes. Changing it breaks links you have already shared.</div>
                @if($live)<div class="small mt-2">Live at <a href="{{ $url }}" target="_blank" rel="noopener">{{ $url }}</a>@if($subdomains) · preview: <a href="{{ url('/s/' . $tenant->slug) }}" target="_blank" rel="noopener">{{ url('/s/' . $tenant->slug) }}</a>@endif</div>@endif
            </x-cb.card>

            <x-cb.card title="Words" icon="ri-text" class="mt-3">
                <div class="row g-3">
                    <div class="col-12"><label class="form-label">Headline</label><input name="headline" class="form-control" maxlength="120" value="{{ old('headline', $site['headline'] ?? '') }}" placeholder="{{ $tenant->name }}"></div>
                    <div class="col-12"><label class="form-label">Tagline</label><input name="tagline" class="form-control" maxlength="200" value="{{ old('tagline', $site['tagline'] ?? '') }}" placeholder="e.g. Bridal and occasion wear, made to measure in Lagos"></div>
                    <div class="col-12"><label class="form-label">About the house</label><textarea name="about" class="form-control" rows="5" maxlength="3000" placeholder="Who you are, what you make, what customers can expect.">{{ old('about', $site['about'] ?? '') }}</textarea></div>
                    <div class="col-md-6"><label class="form-label">Opening hours</label><textarea name="hours" class="form-control" rows="3" maxlength="300" placeholder="Mon–Fri 9am–6pm&#10;Sat 10am–4pm">{{ old('hours', $site['hours'] ?? '') }}</textarea></div>
                    <div class="col-md-6"><label class="form-label">Hero picture</label><input type="file" name="hero" class="form-control" accept="image/*">
                        <div class="form-text">A tall photo works best. Without one, the first featured style's picture is used.</div>
                        @if(! empty($site['hero_path']))<div class="d-flex align-items-center gap-2 mt-2"><img src="{{ asset('storage/' . $site['hero_path']) }}" alt="" style="width:56px;height:70px;object-fit:cover;border-radius:8px"><label class="small d-flex gap-1"><input type="checkbox" name="remove_hero" value="1"> Remove</label></div>@endif</div>
                </div>
            </x-cb.card>

            <x-cb.card title="Featured styles" icon="ri-star-line" class="mt-3">
                @if($styles->isEmpty())
                    <p class="small text-muted mb-0">Publish styles and tick “show in catalogue” to put them on your site.</p>
                @else
                    <p class="small text-muted">Pick up to six for the home page; otherwise the first six are shown. Every published style marked “show in catalogue” is in the collection.</p>
                    <div class="row">@foreach($styles as $s)<div class="col-md-4"><label class="d-flex gap-2 small"><input type="checkbox" name="featured[]" value="{{ $s->id }}" @checked(in_array($s->id, $featured))> {{ $s->name }}</label></div>@endforeach</div>
                @endif
            </x-cb.card>
        </div>
        <div class="col-xl-4">
            <x-cb.card title="Contact & social" icon="ri-chat-3-line">
                <label class="form-label">WhatsApp number</label><input name="whatsapp" class="form-control mb-3" maxlength="30" value="{{ old('whatsapp', $site['whatsapp'] ?? '') }}" placeholder="0803 123 4567">
                <label class="form-label">Instagram</label><input name="instagram" class="form-control mb-3" maxlength="60" value="{{ old('instagram', $site['instagram'] ?? '') }}" placeholder="@yourhouse">
                <label class="form-label">TikTok</label><input name="tiktok" class="form-control mb-3" maxlength="60" value="{{ old('tiktok', $site['tiktok'] ?? '') }}" placeholder="@yourhouse">
                <label class="form-label">Facebook page</label><input name="facebook" class="form-control mb-3" maxlength="100" value="{{ old('facebook', $site['facebook'] ?? '') }}" placeholder="yourhouse">
                <label class="d-flex gap-2"><input type="hidden" name="show_prices" value="0"><input type="checkbox" name="show_prices" value="1" @checked(old('show_prices', $site['show_prices'] ?? true))> Show prices on the site</label>
                <p class="small text-muted mt-3 mb-0">Phone, e-mail, logo and colour come from <a href="{{ route('settings.tenant.edit') }}">House settings</a>; addresses from Branches.</p>
            </x-cb.card>

            <x-cb.card title="Your own domain" icon="ri-global-line" class="mt-3">
                @if($canDomain)
                    <input name="domain" class="form-control mb-2" maxlength="190" value="{{ old('domain', $tenant->domain) }}" placeholder="adunnicouture.com">
                    @if($tenant->domain)
                        <p class="small mb-2">{!! $tenant->domain_verified_at ? '<span class="status-pill st-success">Live</span>' : '<span class="status-pill st-warning">Waiting for DNS</span>' !!}</p>
                        @unless($tenant->domain_verified_at)
                            <p class="small text-muted">At your domain provider, add
                                @if(config('sites.cname_target'))a <strong>CNAME</strong> record pointing to <code>{{ config('sites.cname_target') }}</code>@endif
                                @if(config('sites.cname_target') && config('sites.server_ip')) or @endif
                                @if(config('sites.server_ip'))an <strong>A</strong> record pointing to <code>{{ config('sites.server_ip') }}</code>@endif,
                                then check below.</p>
                        @endunless
                    @endif
                @else
                    <p class="small text-muted mb-0">Use your own domain (like adunnicouture.com) on the Business plan. <a href="{{ route('billing.index') }}">See plans</a></p>
                @endif
            </x-cb.card>
            <button class="action-btn btn-primary-cb w-100 justify-content-center py-2 mt-3" @disabled(! $allowed)><i class="ri-save-line"></i>Save website</button>
        </div>
    </div>
    </form>
    @if($canDomain && $tenant->domain && ! $tenant->domain_verified_at)
        <form method="POST" action="{{ route('website.domain-check') }}" class="mt-2 text-end">@csrf<button class="action-btn"><i class="ri-refresh-line"></i>Check my domain</button></form>
    @endif
</div>
</div>
</div>
@endsection
