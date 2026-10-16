{{-- Milestones; the client sees only published ones (FR-PRJ-005, scenario 39). --}}
<x-cb.card title="Milestones" icon="ri-flag-2-line" class="mt-3" :count="$project->milestones->count()" :flush="true">
    @forelse($project->milestones as $ms)
        <div class="d-flex justify-content-between align-items-start gap-2 px-3 py-2 border-bottom flex-wrap">
            <div class="d-flex gap-2">
                <i class="{{ $ms->status === 'done' ? 'ri-checkbox-circle-fill text-success' : ($ms->status === 'skipped' ? 'ri-indeterminate-circle-line text-muted' : ($ms->isLate() ? 'ri-error-warning-line text-danger' : 'ri-checkbox-blank-circle-line text-muted')) }} fs-5"></i>
                <div>
                    <span class="fw-semibold {{ $ms->status === 'skipped' ? 'text-decoration-line-through text-muted' : '' }}">{{ $ms->name }}</span>
                    @if($ms->isPublished())<span class="status-pill st-success ms-1" title="Published {{ $ms->published_at->timezone($tz)->format('d M H:i') }}"><i class="ri-eye-line"></i> Client sees it</span>
                    @elseif($ms->customer_visible)<span class="status-pill st-warning ms-1">Ready to publish</span>
                    @else<span class="status-pill st-muted ms-1"><i class="ri-eye-off-line"></i> Staff only</span>@endif
                    <div class="small {{ $ms->isLate() ? 'text-danger' : 'text-muted' }}">{{ $ms->due_date ? ($ms->status === 'pending' && $ms->isLate() ? 'Was due ' : 'Due ') . $ms->due_date->format('D d M') : 'No date' }}
                        {{ $ms->completed_at ? ' · ' . ($ms->status === 'done' ? 'done ' : 'skipped ') . $ms->completed_at->timezone($tz)->format('d M') : '' }}{{ $ms->notes ? ' · ' . $ms->notes : '' }}</div>
                </div>
            </div>
            <div class="d-flex gap-1">
                @if($canPublish && $open && ! $ms->isPublished() && $ms->status !== 'skipped')
                    <form method="POST" action="{{ route('projects.milestones.publish', [$project, $ms]) }}">@csrf<button class="btn btn-sm btn-light" title="Show it to the client"><i class="ri-send-plane-line me-1"></i>Publish</button></form>
                @endif
                @if($canManage && $open)
                    @if($ms->status === 'pending')
                        <form method="POST" action="{{ route('projects.milestones.update', [$project, $ms]) }}">@csrf @method('PUT')<input type="hidden" name="action" value="done">
                            <button class="btn btn-sm btn-light"><i class="ri-check-line me-1"></i>Done</button></form>
                    @else
                        <form method="POST" action="{{ route('projects.milestones.update', [$project, $ms]) }}">@csrf @method('PUT')<input type="hidden" name="action" value="reopen">
                            <button class="btn btn-sm btn-light" title="Reopen"><i class="ri-arrow-go-back-line"></i></button></form>
                    @endif
                    <button class="btn btn-sm btn-light" type="button" data-bs-toggle="collapse" data-bs-target="#ms{{ $ms->id }}" title="Edit"><i class="ri-edit-line"></i></button>
                @endif
            </div>
            @if($canManage && $open)
                <div class="collapse w-100" id="ms{{ $ms->id }}">
                    <form method="POST" action="{{ route('projects.milestones.update', [$project, $ms]) }}" class="row g-2 mt-1">@csrf @method('PUT')
                        <div class="col-md-5"><input name="name" class="form-control form-control-sm" required maxlength="120" value="{{ $ms->name }}"></div>
                        <div class="col-md-3"><input type="date" name="due_date" class="form-control form-control-sm" value="{{ $ms->due_date?->toDateString() }}"></div>
                        <div class="col-md-4"><label class="small d-flex gap-1"><input type="checkbox" name="customer_visible" value="1" @checked($ms->customer_visible)> The client may see this</label></div>
                        <div class="col-md-9"><input name="notes" class="form-control form-control-sm" maxlength="500" value="{{ $ms->notes }}" placeholder="Note (the client sees it if published)"></div>
                        <div class="col-md-3 d-grid"><button class="action-btn justify-content-center">Save</button></div>
                    </form>
                    @if($ms->status === 'pending')
                        <form method="POST" action="{{ route('projects.milestones.update', [$project, $ms]) }}" class="mt-1">@csrf @method('PUT')<input type="hidden" name="action" value="skip">
                            <button class="btn btn-sm btn-link text-muted p-0">Skip this milestone</button></form>
                    @endif
                </div>
            @endif
        </div>
    @empty
        <p class="small text-muted p-3 mb-0">No milestones yet.</p>
    @endforelse
    @if($canManage && $open)
        <form method="POST" action="{{ route('projects.milestones.store', $project) }}" class="row g-2 p-3" style="background:var(--cb-surface-2)">@csrf
            <div class="col-md-5"><input name="name" class="form-control form-control-sm" required maxlength="120" placeholder="New milestone, e.g. Bridal shower outfits"></div>
            <div class="col-md-3"><input type="date" name="due_date" class="form-control form-control-sm"></div>
            <div class="col-md-2"><label class="small d-flex gap-1 mt-1"><input type="checkbox" name="customer_visible" value="1" checked> Client may see</label></div>
            <div class="col-md-2 d-grid"><button class="action-btn justify-content-center"><i class="ri-add-line"></i>Add</button></div>
        </form>
    @endif
</x-cb.card>
