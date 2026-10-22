<div class="page-title store-page-title">
    <div class="container">
        <h3 class="heading text-center">{{ $title }}</h3>
        <ul class="breadcrumbs d-flex align-items-center justify-content-center">
            <li><a class="link" href="{{ $base ?: '/' }}">Home</a></li>
            @foreach($crumbs ?? [] as $label => $href)
                <li><i class="icon-arrRight"></i></li>
                <li>@if($href)<a class="link" href="{{ $href }}">{{ $label }}</a>@else{{ $label }}@endif</li>
            @endforeach
            <li><i class="icon-arrRight"></i></li>
            <li>{{ $title }}</li>
        </ul>
        @isset($subtitle)<p class="text-center text-secondary mt-2 mb-0">{{ $subtitle }}</p>@endisset
    </div>
</div>
