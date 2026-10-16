{{-- People being dressed and their garments (FR-PRJ-003). --}}
@php
    use App\Models\OrderLine; use App\Support\Money;
    $people = $project->participants; $active = $people->where('status', 'active');
    $groups = $people->groupBy(fn ($p) => $p->group_name ?: 'Everyone else');
@endphp
<x-cb.card title="People & garments" icon="ri-group-line" :count="$active->count()" :flush="true">
    @if($people->isEmpty())
        <div class="empty-state"><i class="ri-group-line"></i><h6>Nobody added yet</h6><p class="small text-muted">Add the bride, groom, bridesmaids, family — everyone being dressed.</p></div>
    @endif
    @foreach($groups as $group => $members)
        <div class="px-3 pt-3 pb-1 small fw-semibold text-uppercase text-muted d-flex justify-content-between">
            <span>{{ $group }} <span class="fw-normal">· {{ $members->where('status', 'active')->count() }}</span></span>
        </div>
        @foreach($members as $p)
            @php $lines = $garments->get($p->id, collect())->where('status', '!=', 'cancelled'); $mySessions = $p->customer_id ? $sessions->get($p->customer_id, collect()) : collect(); @endphp
            <div class="px-3 py-2 border-bottom {{ $p->status === 'withdrawn' ? 'opacity-50' : '' }}">
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <div>
                        <span class="fw-semibold">{{ $p->name }}</span>
                        @if($p->role)<span class="small text-muted"> · {{ $p->role }}</span>@endif
                        @if($p->pays === 'self')<span class="status-pill st-warning ms-1">Pays own</span>@endif
                        @if($p->status === 'withdrawn')<span class="status-pill st-muted ms-1">Withdrawn</span>@endif
                        <div class="small text-muted">{{ $p->phone ?: 'No phone' }}{{ $p->customer ? ' · ' . $p->customer->customer_number : '' }}{{ $p->notes ? ' · ' . $p->notes : '' }}</div>
                    </div>
                    @if($canManage && $open && $p->status === 'active')
                        <div class="d-flex gap-1">
                            <a href="{{ route('projects.participants.measure', [$project, $p]) }}" class="btn btn-sm btn-light"><i class="ri-ruler-2-line me-1"></i>Measure</a>
                            <button class="btn btn-sm btn-light" type="button" data-bs-toggle="collapse" data-bs-target="#pe{{ $p->id }}" title="Edit"><i class="ri-edit-line"></i></button>
                        </div>
                    @endif
                </div>
                @foreach($lines as $l)
                    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mt-2 ps-2 border-start border-2">
                        <div class="small">
                            <span class="fw-semibold">{{ rtrim(rtrim(number_format($l->quantity, 2), '0'), '.') }} × {{ $l->description }}</span>
                            <span class="status-pill {{ $l->status_pill }} ms-1">{{ $l->status_label }}</span>
                            <a href="{{ route('orders.show', $l->order) }}" class="text-muted ms-1">{{ $l->order->number }}</a>
                            @if($canMoney)<span class="text-muted ms-1">{{ Money::format($l->line_total) }}</span>@endif
                        </div>
                        <div class="d-flex gap-1 align-items-center">
                            @if($l->kind === 'bespoke' && $canManage && $open)
                                <form method="POST" action="{{ route('projects.garments.measurements', [$project, $l]) }}" class="d-flex gap-1">@csrf
                                    <select name="measurement_session_id" class="form-select form-select-sm" style="max-width:210px" onchange="this.form.submit()">
                                        <option value="">{{ $mySessions->isEmpty() ? 'No approved measurements' : 'Link measurements…' }}</option>
                                        @foreach($mySessions as $ms)<option value="{{ $ms->id }}" @selected($l->measurement_session_id === $ms->id)>{{ $ms->templateVersion?->template?->name }} · {{ $ms->created_at->timezone($tz)->format('d M') }}</option>@endforeach
                                    </select></form>
                            @elseif($l->kind === 'bespoke')
                                <span class="small {{ $l->measurement_session_id ? 'text-success' : 'text-warning' }}"><i class="ri-ruler-2-line"></i> {{ $l->measurement_session_id ? 'Measured' : 'Not measured' }}</span>
                            @endif
                            @if($canManage && $open && $l->status === 'pending')
                                <form method="POST" action="{{ route('projects.garments.destroy', [$project, $l]) }}" onsubmit="return confirm('Remove this garment?')">@csrf @method('DELETE')
                                    <button class="btn btn-sm btn-link text-danger p-0" title="Remove"><i class="ri-delete-bin-line"></i></button></form>
                            @endif
                        </div>
                    </div>
                @endforeach
                @if($lines->isEmpty() && $p->status === 'active')<div class="small text-warning mt-1"><i class="ri-information-line"></i> No garment yet.</div>@endif

                @if($canManage && $open && $p->status === 'active')
                    <div class="collapse mt-2" id="pe{{ $p->id }}">
                        <form method="POST" action="{{ route('projects.participants.update', [$project, $p]) }}" class="row g-2">@csrf @method('PUT')
                            <div class="col-md-4"><input name="name" class="form-control form-control-sm" required maxlength="120" value="{{ $p->name }}"></div>
                            <div class="col-md-3"><input name="phone" class="form-control form-control-sm" maxlength="30" value="{{ $p->phone }}" placeholder="Phone"></div>
                            <div class="col-md-3"><input name="email" type="email" class="form-control form-control-sm" maxlength="160" value="{{ $p->email }}" placeholder="E-mail"></div>
                            <div class="col-md-2"><input name="role" class="form-control form-control-sm" maxlength="60" value="{{ $p->role }}" placeholder="Role"></div>
                            <div class="col-md-3"><input name="group_name" class="form-control form-control-sm" maxlength="60" value="{{ $p->group_name }}" placeholder="Group"></div>
                            <div class="col-md-3"><select name="pays" class="form-select form-select-sm">@foreach(\App\Models\ProjectParticipant::PAYS as $k => $v)<option value="{{ $k }}" @selected($p->pays === $k)>{{ $v }}</option>@endforeach</select></div>
                            <div class="col-md-4"><input name="notes" class="form-control form-control-sm" maxlength="500" value="{{ $p->notes }}" placeholder="Notes"></div>
                            <div class="col-md-2 d-grid"><button class="action-btn justify-content-center">Save</button></div>
                        </form>
                        <form method="POST" action="{{ route('projects.participants.withdraw', [$project, $p]) }}" class="d-flex gap-2 mt-2" onsubmit="return confirm('Withdraw {{ e($p->name) }}? Garments not yet started come off the order.')">@csrf
                            <input name="reason" class="form-control form-control-sm" required maxlength="255" placeholder="Reason for withdrawing">
                            <button class="btn btn-sm btn-light text-danger text-nowrap">Withdraw</button></form>
                    </div>
                @endif
            </div>
        @endforeach
    @endforeach

    @if($canManage && $open)
        <div class="p-3" style="background:var(--cb-surface-2)">
            <ul class="nav nav-pills gap-1 mb-2 small" role="tablist">
                <li class="nav-item"><button class="nav-link py-1 px-3 active" data-bs-toggle="tab" data-bs-target="#addOne" type="button">Add a person</button></li>
                <li class="nav-item"><button class="nav-link py-1 px-3" data-bs-toggle="tab" data-bs-target="#addMany" type="button">Paste a list</button></li>
                @if($active->isNotEmpty())<li class="nav-item"><button class="nav-link py-1 px-3" data-bs-toggle="tab" data-bs-target="#addGarment" type="button">Add garments</button></li>@endif
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="addOne">
                    <form method="POST" action="{{ route('projects.participants.store', $project) }}" class="row g-2">@csrf
                        <div class="col-md-4"><input name="name" class="form-control form-control-sm" required maxlength="120" placeholder="Name *"></div>
                        <div class="col-md-3"><input name="phone" class="form-control form-control-sm" maxlength="30" placeholder="Phone"></div>
                        <div class="col-md-2"><input name="role" class="form-control form-control-sm" maxlength="60" placeholder="Role, e.g. Bride"></div>
                        <div class="col-md-3"><input name="group_name" class="form-control form-control-sm" maxlength="60" placeholder="Group, e.g. Bridesmaids" list="projGroups"></div>
                        <div class="col-md-4"><select name="pays" class="form-select form-select-sm">@foreach(\App\Models\ProjectParticipant::PAYS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                        <div class="col-md-2 d-grid"><button class="action-btn justify-content-center"><i class="ri-user-add-line"></i>Add</button></div>
                    </form>
                </div>
                <div class="tab-pane fade" id="addMany">
                    <form method="POST" action="{{ route('projects.participants.store', $project) }}" class="row g-2">@csrf
                        <div class="col-12"><textarea name="bulk" class="form-control form-control-sm font-monospace" rows="4" placeholder="One person per line: name, phone, role&#10;Ada Eze, 08031110001, Chief bridesmaid&#10;Chioma Okeke, 08031110002"></textarea></div>
                        <div class="col-md-4"><input name="group_name" class="form-control form-control-sm" maxlength="60" placeholder="Group for all of them" list="projGroups"></div>
                        <div class="col-md-4"><select name="pays" class="form-select form-select-sm">@foreach(\App\Models\ProjectParticipant::PAYS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                        <div class="col-md-4 d-grid"><button class="action-btn justify-content-center"><i class="ri-group-line"></i>Add everyone</button></div>
                    </form>
                </div>
                @if($active->isNotEmpty())
                <div class="tab-pane fade" id="addGarment">@include('projects.partials.add-garments')</div>
                @endif
            </div>
            <datalist id="projGroups">@foreach($people->pluck('group_name')->filter()->unique() as $gname)<option value="{{ $gname }}">@endforeach</datalist>
        </div>
    @endif
</x-cb.card>
