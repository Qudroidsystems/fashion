@if(session('success'))<div class="cb-banner success"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>@endif
@if($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>@endif
