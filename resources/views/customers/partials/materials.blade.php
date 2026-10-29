{{-- Customer › Materials tab: what they left with the house. Expects $c, $cMaterials, $cMaterialChannels. --}}
@php use App\Models\CustomerMaterial; $withUs = $cMaterials->filter->isWithHouse(); @endphp
<div class="tab-pane" id="materials">
    <x-cb.card title="With us" icon="ri-archive-drawer-line" :count="$withUs->count()">
        <x-slot:tools>
            @can('Manage customer materials')
                <a href="{{ route('materials.create', ['customer' => $c->public_id]) }}" class="btn btn-light btn-sm"><i class="ri-add-line"></i> Receive an item</a>
            @endcan
        </x-slot:tools>
        @forelse($withUs as $m)
            <a href="{{ route('materials.show', $m) }}" class="d-flex align-items-center gap-2 py-2 border-bottom text-reset text-decoration-none">
                @if($m->photos)<img src="{{ $m->photoUrls()[0] }}" alt="" width="44" height="44" class="rounded object-fit-cover">@else<i class="ri-t-shirt-line fs-3 text-muted"></i>@endif
                <div class="flex-grow-1 small">
                    <div class="fw-semibold">{{ $m->number }} · {{ $m->summary }}</div>
                    <div class="text-muted">{{ $m->location ?: 'No shelf noted' }}{{ $m->order ? ' · ' . $m->order->number : '' }} · since {{ $m->received_at->format('j M Y') }}</div>
                </div>
                <span class="status-pill {{ CustomerMaterial::STATUSES[$m->status][1] ?? 'st-muted' }}">{{ $m->status_label }}</span>
            </a>
        @empty
            <p class="small text-muted mb-0">Nothing of {{ $c->first_name }}’s is with you.</p>
        @endforelse
        @if($withUs->isNotEmpty())
            @can('Manage customer materials')
            <form method="post" action="{{ route('customers.materials.remind', $c) }}" class="d-flex flex-wrap gap-3 align-items-center mt-3">@csrf
                <span class="small fw-semibold">Remind {{ $c->first_name }} to collect:</span>
                @foreach(\App\Models\MessageLog::CHANNELS as $k => $label)
                    <label class="d-flex gap-1 small"><input type="checkbox" name="channels[]" value="{{ $k }}" @checked($cMaterialChannels[$k] ?? false) @disabled(! ($cMaterialChannels[$k] ?? false))> {{ $label }}</label>
                @endforeach
                <button class="btn btn-primary btn-sm">Send</button>
            </form>
            @endcan
        @endif
    </x-cb.card>

    @php $past = $cMaterials->reject->isWithHouse(); @endphp
    @if($past->isNotEmpty())
    <x-cb.card title="Returned before" icon="ri-history-line" :count="$past->count()" class="mt-3">
        @foreach($past as $m)
            <a href="{{ route('materials.show', $m) }}" class="d-flex justify-content-between py-2 border-bottom small text-reset text-decoration-none">
                <span>{{ $m->number }} · {{ $m->summary }}</span>
                <span class="text-muted">{{ $m->status_label }} {{ $m->returned_at?->format('j M Y') }}{{ $m->returned_to ? ' · ' . $m->returned_to : '' }}</span>
            </a>
        @endforeach
    </x-cb.card>
    @endif
</div>
