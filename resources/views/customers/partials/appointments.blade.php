{{-- Customer page: upcoming appointments and fittings. Expects $c and $tz. --}}
@can('Manage appointment')
@php
    $capps = \App\Models\Appointment::where('customer_id', $c->id)->where('status', 'booked')->where('starts_at', '>=', now()->subHours(2))->orderBy('starts_at')->limit(5)->get();
    $cfits = \App\Models\Fitting::where('customer_id', $c->id)->where('status', 'scheduled')->where('scheduled_at', '>=', now()->subHours(2))->orderBy('scheduled_at')->limit(5)->get();
    $cnext = $capps->map(fn ($a) => ['at' => $a->starts_at, 'label' => $a->type_label, 'url' => route('appointments.show', $a)])
        ->concat($cfits->map(fn ($f) => ['at' => $f->scheduled_at, 'label' => 'Fitting', 'url' => route('fittings.show', $f)]))->sortBy(fn ($x) => $x['at']->timestamp)->take(5);
@endphp
<x-cb.card title="Coming up" icon="ri-calendar-todo-line" :count="$cnext->count()" class="mt-3">
    <x-slot:tools><a href="{{ route('appointments.create', ['customer' => $c->public_id]) }}" class="btn btn-light btn-sm"><i class="ri-add-line"></i> Book</a></x-slot:tools>
    @forelse($cnext as $x)
        <a href="{{ $x['url'] }}" class="d-flex justify-content-between py-1 small text-body"><span>{{ $x['label'] }}</span><span class="text-muted">{{ $x['at']->copy()->timezone($tz)->format('D j M, g:i A') }}</span></a>
    @empty
        <p class="small text-muted mb-0">Nothing booked.</p>
    @endforelse
</x-cb.card>
@endcan
