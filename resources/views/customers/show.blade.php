@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php
        $c = $customer;
        $nextBirthday = $c->nextBirthday($tz);
        $canEdit = auth()->user()->can('Update customer');
    @endphp

    <x-cb.hero :title="$c->full_name" icon="ri-user-heart-line" :subtitle="$c->customer_number . ($c->branch ? ' · ' . $c->branch->name : '')"
               :back="route('customers.index')" back-label="Customers">
        <x-slot:pills>
            @if($c->is_vip)<span class="cb-meta-pill"><i class="ri-vip-crown-fill me-1 text-warning"></i>VIP</span>@endif
            @if($c->status !== 'active')<span class="cb-meta-pill">{{ ucfirst($c->status) }}</span>@endif
            @foreach($c->tags as $t)<span class="cb-meta-pill">{{ $t->name }}</span>@endforeach
            @if($nextBirthday)<span class="cb-meta-pill"><i class="ri-cake-2-line me-1"></i>Birthday {{ $nextBirthday->format('j M') }}{{ $nextBirthday->isToday() ? ' — today!' : '' }}</span>@endif
            <span class="cb-meta-pill">Customer since {{ $c->created_at->format('M Y') }}</span>
        </x-slot:pills>
        <x-slot:actions>
            @if($c->phone)<a href="tel:{{ $c->phone }}" class="cb-hero-btn"><i class="ri-phone-line me-1"></i>Call</a>@endif
            @if($c->whatsapp ?: $c->phone)
                <a href="https://wa.me/{{ preg_replace('/\D/', '', preg_replace('/^0/', '234', $c->whatsapp ?: $c->phone)) }}" target="_blank" rel="noopener" class="cb-hero-btn"><i class="ri-whatsapp-line me-1"></i>WhatsApp</a>
            @endif
            @if($canEdit && $c->status !== 'merged')
                <a href="{{ route('customers.edit', $c) }}" class="cb-hero-btn"><i class="ri-pencil-line me-1"></i>Edit</a>
            @endif
        </x-slot:actions>
    </x-cb.hero>

    @if(session('success'))
        <div class="cb-banner success"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>
    @endif
    @if($errors->any())
        <div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>
    @endif
    @if($c->status === 'merged' && $c->mergedInto)
        <div class="cb-banner info"><i class="ri-git-merge-line"></i><div>This record was merged into <a href="{{ route('customers.show', $c->mergedInto) }}">{{ $c->mergedInto->name }} ({{ $c->mergedInto->customer_number }})</a>. It is kept for history only.</div></div>
    @endif

    <div class="row g-3">
        {{-- Left: profile --}}
        <div class="col-xl-4">
            <x-cb.card title="Contact" icon="ri-contacts-line">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted fw-normal">Phone</dt><dd class="col-7">{{ $c->phone ?? '—' }}</dd>
                    @if($c->whatsapp)<dt class="col-5 text-muted fw-normal">WhatsApp</dt><dd class="col-7">{{ $c->whatsapp }}</dd>@endif
                    @if($c->alt_phone)<dt class="col-5 text-muted fw-normal">Other phone</dt><dd class="col-7">{{ $c->alt_phone }}</dd>@endif
                    <dt class="col-5 text-muted fw-normal">E-mail</dt><dd class="col-7 text-break">{{ $c->email ?? '—' }}</dd>
                    <dt class="col-5 text-muted fw-normal">Address</dt><dd class="col-7">{{ collect([$c->address, $c->city, $c->state])->filter()->implode(', ') ?: '—' }}</dd>
                    <dt class="col-5 text-muted fw-normal">Gender</dt><dd class="col-7">{{ ucfirst($c->gender ?? '—') }}</dd>
                    <dt class="col-5 text-muted fw-normal">Birthday</dt><dd class="col-7">{{ $c->birthday?->format('j F') ?? '—' }}</dd>
                    <dt class="col-5 text-muted fw-normal">Occupation</dt><dd class="col-7">{{ $c->occupation ?? '—' }}</dd>
                    <dt class="col-5 text-muted fw-normal">Source</dt><dd class="col-7">{{ \App\Models\Customer::SOURCES[$c->source] ?? '—' }}</dd>
                    <dt class="col-5 text-muted fw-normal">Account manager</dt><dd class="col-7">{{ $c->assignee?->name ?? '—' }}</dd>
                    <dt class="col-5 text-muted fw-normal">Added by</dt><dd class="col-7">{{ $c->creator?->name ?? '—' }}, {{ $c->created_at->timezone($tz)->format('d M Y') }}</dd>
                </dl>
            </x-cb.card>

            @if($c->preferences)
            <x-cb.card title="Style preferences" icon="ri-t-shirt-line" class="mt-3">
                <dl class="row mb-0 small">
                    @foreach(\App\Models\Customer::PREFERENCE_FIELDS as $key => $label)
                        @if(!empty($c->preferences[$key]))
                            <dt class="col-5 text-muted fw-normal">{{ $label }}</dt><dd class="col-7">{{ $c->preferences[$key] }}</dd>
                        @endif
                    @endforeach
                </dl>
            </x-cb.card>
            @endif

            <x-cb.card title="Contact consent" icon="ri-shield-check-line" class="mt-3">
                <table class="table table-sm mb-0 small align-middle">
                    <thead><tr><th></th><th class="text-center">Updates</th><th class="text-center">Promotions</th></tr></thead>
                    <tbody>
                    @foreach($channels as $ch => $chl)
                        <tr><td>{{ $chl }}</td>
                            @foreach(['transactional', 'marketing'] as $p)
                                @php $consent = $c->consents->first(fn ($x) => $x->channel === $ch && $x->purpose === $p); @endphp
                                <td class="text-center">
                                    @if(! $consent)<span class="text-muted" title="Not asked">–</span>
                                    @elseif($consent->granted)<i class="ri-checkbox-circle-fill text-success" title="Yes · {{ $consent->recorded_at?->timezone($tz)->format('d M Y') }}"></i>
                                    @else<i class="ri-close-circle-fill text-danger" title="No · {{ $consent->recorded_at?->timezone($tz)->format('d M Y') }}"></i>@endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </x-cb.card>

            @if($c->status !== 'merged')
            <x-cb.card title="Record" icon="ri-settings-3-line" class="mt-3">
                <div class="d-flex flex-wrap gap-2">
                    @can('Merge customer')
                        <a href="{{ route('customers.merge.form', $c) }}" class="action-btn"><i class="ri-git-merge-line"></i>Merge duplicate</a>
                    @endcan
                    @if($canEdit)
                        <form method="POST" action="{{ route('customers.archive', $c) }}">@csrf
                            <button class="action-btn"><i class="ri-archive-line"></i>{{ $c->status === 'archived' ? 'Restore' : 'Archive' }}</button>
                        </form>
                    @endif
                    @can('Delete customer')
                        <form method="POST" action="{{ route('customers.destroy', $c) }}" onsubmit="return confirm('Delete {{ addslashes($c->name) }}? Archive instead if you may need the history.')">
                            @csrf @method('DELETE')
                            <button class="action-btn btn-danger-soft"><i class="ri-delete-bin-line"></i>Delete</button>
                        </form>
                    @endcan
                </div>
            </x-cb.card>
            @endif
        </div>

        {{-- Right: activity --}}
        <div class="col-xl-8">
            <ul class="nav nav-pills arrow-navtabs nav-secondary gap-2 mb-3" role="tablist">
                <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#timeline"><i class="ri-history-line me-1"></i>Timeline</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#notes"><i class="ri-sticky-note-line me-1"></i>Notes <span class="badge bg-light text-body ms-1">{{ $notes->count() }}</span></a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#follow-ups"><i class="ri-alarm-line me-1"></i>Follow-ups <span class="badge bg-light text-body ms-1">{{ $followUps->where('status', 'open')->count() }}</span></a></li>
                @can('View measurement')
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#measurements"><i class="ri-ruler-2-line me-1"></i>Measurements <span class="badge bg-light text-body ms-1">{{ $mCurrent->count() }}</span></a></li>
                @endcan
                @can('View order')
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#orders"><i class="ri-file-list-3-line me-1"></i>Orders <span class="badge bg-light text-body ms-1">{{ $oOrders->whereIn('status', \App\Support\Orders\OrderStatus::OPEN)->count() }}</span></a></li>
                @endcan
            </ul>

            <div class="tab-content">
                {{-- Timeline --}}
                <div class="tab-pane active" id="timeline">
                    <x-cb.card title="Timeline" icon="ri-history-line">
                        @forelse($timeline as $item)
                            <div class="d-flex gap-3 pb-3 mb-3 border-bottom">
                                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-circle" style="width:36px;height:36px;background:var(--cb-surface-2);color:var(--cb-teal);"><i class="{{ $item['icon'] }}"></i></div>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between gap-2">
                                        <span class="fw-semibold">{{ $item['title'] }}</span>
                                        <span class="small text-muted text-nowrap">{{ $item['at']->timezone($tz)->format('d M Y, H:i') }}</span>
                                    </div>
                                    @if($item['body'])<div class="small" style="white-space:pre-line">{{ \Illuminate\Support\Str::limit($item['body'], 400) }}</div>@endif
                                    @if($item['by'])<div class="small text-muted">{{ $item['by'] }}</div>@endif
                                </div>
                            </div>
                        @empty
                            <div class="empty-state"><i class="ri-history-line"></i><h6>No activity yet</h6><p>Notes, follow-ups and changes will appear here.</p></div>
                        @endforelse
                    </x-cb.card>
                </div>

                {{-- Notes --}}
                <div class="tab-pane" id="notes">
                    <x-cb.card title="Notes" icon="ri-sticky-note-line">
                        @if($canEdit && $c->status !== 'merged')
                        <form method="POST" action="{{ route('customers.notes.store', $c) }}" class="mb-4">
                            @csrf
                            <textarea name="body" class="form-control mb-2" rows="3" required placeholder="Write a note — e.g. prefers fittings on Saturdays, allergic to wool…"></textarea>
                            <div class="d-flex flex-wrap gap-3 align-items-center">
                                @can('View customer sensitive notes')
                                <label class="small d-flex align-items-center gap-1 mb-0"><input type="checkbox" name="is_sensitive" value="1"> <i class="ri-lock-2-line"></i>Sensitive (only staff allowed to see sensitive notes)</label>
                                @endcan
                                <label class="small d-flex align-items-center gap-1 mb-0"><input type="checkbox" name="is_pinned" value="1"> <i class="ri-pushpin-line"></i>Pin to top</label>
                                <button class="action-btn btn-primary-cb ms-auto"><i class="ri-add-line"></i>Add note</button>
                            </div>
                        </form>
                        @endif

                        @if($hiddenNotes)
                            <div class="cb-banner info"><i class="ri-lock-2-line"></i><div>{{ $hiddenNotes }} sensitive note(s) are hidden from your role.</div></div>
                        @endif

                        @forelse($notes as $note)
                            <div class="p-3 mb-2 rounded-3" style="background:{{ $note->is_sensitive ? '#fff7ed' : 'var(--cb-surface-2)' }};border:1px solid var(--cb-border)">
                                <div class="d-flex justify-content-between gap-2 small text-muted mb-1">
                                    <span>
                                        @if($note->is_pinned)<i class="ri-pushpin-fill text-primary"></i>@endif
                                        @if($note->is_sensitive)<i class="ri-lock-2-fill text-warning"></i> Sensitive ·@endif
                                        {{ $note->author?->name ?? 'System' }} · {{ $note->created_at->timezone($tz)->format('d M Y, H:i') }}
                                    </span>
                                    @if($note->created_by === auth()->id() || auth()->user()->can('Delete customer'))
                                    <form method="POST" action="{{ route('customers.notes.destroy', [$c, $note]) }}" onsubmit="return confirm('Remove this note?')">
                                        @csrf @method('DELETE')<button class="btn btn-link btn-sm p-0 text-muted"><i class="ri-delete-bin-line"></i></button>
                                    </form>
                                    @endif
                                </div>
                                <div style="white-space:pre-line">{{ $note->body }}</div>
                            </div>
                        @empty
                            <p class="text-muted small mb-0">No notes yet.</p>
                        @endforelse
                    </x-cb.card>
                </div>

                {{-- Follow-ups --}}
                <div class="tab-pane" id="follow-ups">
                    <x-cb.card title="Follow-ups" icon="ri-alarm-line">
                        @if($canEdit && $c->status !== 'merged')
                        <form method="POST" action="{{ route('customers.follow-ups.store', $c) }}" class="row g-2 mb-4">
                            @csrf
                            <div class="col-md-5"><input name="title" class="form-control" required placeholder="e.g. Call to book first fitting"></div>
                            <div class="col-md-3"><input type="datetime-local" name="due_at" class="form-control" title="Due"></div>
                            <div class="col-md-3">
                                <select name="assigned_to" class="form-select">
                                    @foreach($staff as $s)<option value="{{ $s->id }}" @selected($s->id === auth()->id())>{{ $s->name }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col-md-1 d-grid"><button class="action-btn btn-primary-cb justify-content-center"><i class="ri-add-line"></i></button></div>
                        </form>
                        @endif

                        @forelse($followUps as $t)
                            <div class="d-flex align-items-center gap-3 py-2 border-bottom">
                                <i class="{{ $t->status === 'done' ? 'ri-checkbox-circle-fill text-success' : ($t->status === 'cancelled' ? 'ri-close-circle-line text-muted' : ($t->isOverdue() ? 'ri-alarm-warning-fill text-danger' : 'ri-alarm-line text-warning')) }} fs-5"></i>
                                <div class="flex-grow-1">
                                    <div class="{{ $t->status !== 'open' ? 'text-muted text-decoration-line-through' : 'fw-semibold' }}">{{ $t->title }}</div>
                                    <div class="small text-muted">
                                        {{ $t->assignee?->name ?? 'Unassigned' }}
                                        @if($t->due_at) · due {{ $t->due_at->timezone($tz)->format('d M Y, H:i') }}@endif
                                    </div>
                                </div>
                                @if($canEdit)
                                <form method="POST" action="{{ route('follow-ups.update', $t) }}" class="d-flex gap-1">
                                    @csrf @method('PATCH')
                                    @if($t->status === 'open')
                                        <button name="status" value="done" class="action-btn btn-primary-cb"><i class="ri-check-line"></i>Done</button>
                                        <button name="status" value="cancelled" class="action-btn" title="Cancel"><i class="ri-close-line"></i></button>
                                    @else
                                        <button name="status" value="open" class="action-btn">Reopen</button>
                                    @endif
                                </form>
                                @endif
                            </div>
                        @empty
                            <p class="text-muted small mb-0">No follow-ups.</p>
                        @endforelse
                    </x-cb.card>
                </div>

                @can('View measurement')
                @include('customers.partials.measurements')
                @endcan

                @can('View order')
                @include('customers.partials.orders')
                @endcan
            </div>
        </div>
    </div>

</div>
</div>
</div>

<script>
// Open the tab named in the URL hash (#notes, #follow-ups) after a redirect.
document.addEventListener('DOMContentLoaded', function () {
    const hash = window.location.hash;
    if (hash && window.bootstrap) {
        const link = document.querySelector('.nav-link[href="' + hash + '"]');
        if (link) new bootstrap.Tab(link).show();
    }
});
</script>
@endsection
