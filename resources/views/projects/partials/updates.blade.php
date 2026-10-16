{{-- Progress notes and pictures; the client sees published ones only. --}}
<x-cb.card title="Updates" icon="ri-chat-smile-2-line" class="mt-3" :count="$project->updates->count()" :flush="true">
    @if($canManage && $open)
        <form method="POST" action="{{ route('projects.updates.store', $project) }}" enctype="multipart/form-data" class="row g-2 p-3 border-bottom">@csrf
            <div class="col-md-12"><input name="title" class="form-control form-control-sm" maxlength="160" placeholder="Title (optional), e.g. The lace has arrived"></div>
            <div class="col-12"><textarea name="body" class="form-control form-control-sm" rows="2" required maxlength="5000" placeholder="What happened? Written for the client if you will publish it."></textarea></div>
            <div class="col-md-5"><input type="file" name="images[]" class="form-control form-control-sm" accept="image/*" multiple></div>
            <div class="col-md-3"><label class="small d-flex gap-1 mt-1"><input type="checkbox" name="customer_visible" value="1" checked> For the client</label></div>
            <div class="col-md-4 d-flex gap-1">
                <button class="action-btn flex-grow-1 justify-content-center">Save</button>
                @if($canPublish)<button class="action-btn btn-primary-cb flex-grow-1 justify-content-center" name="publish" value="1"><i class="ri-send-plane-line"></i>Publish</button>@endif
            </div>
        </form>
    @endif
    @forelse($project->updates as $u)
        <div class="px-3 py-2 border-bottom">
            <div class="d-flex justify-content-between gap-2">
                <div><span class="fw-semibold">{{ $u->title ?: 'Update' }}</span>
                    @if($u->isPublished())<span class="status-pill st-success ms-1"><i class="ri-eye-line"></i> Published</span>
                    @elseif($u->customer_visible)<span class="status-pill st-warning ms-1">Draft for client</span>
                    @else<span class="status-pill st-muted ms-1"><i class="ri-eye-off-line"></i> Staff only</span>@endif
                    <div class="small text-muted">{{ $u->created_at->timezone($tz)->format('d M, H:i') }} · {{ $u->author?->name }}</div></div>
                @if($canPublish && $open)
                    @if($u->isPublished())
                        <form method="POST" action="{{ route('projects.updates.unpublish', [$project, $u]) }}">@csrf<button class="btn btn-sm btn-light">Hide</button></form>
                    @else
                        <form method="POST" action="{{ route('projects.updates.publish', [$project, $u]) }}">@csrf<button class="btn btn-sm btn-light"><i class="ri-send-plane-line me-1"></i>Publish</button></form>
                    @endif
                @endif
            </div>
            <p class="small mb-1 mt-1">{!! nl2br(e($u->body)) !!}</p>
            @if($u->images)<div class="d-flex gap-2 flex-wrap">@foreach($u->imageUrls() as $src)<a href="{{ $src }}" target="_blank"><img src="{{ $src }}" alt="" style="width:84px;height:84px;object-fit:cover;border-radius:8px"></a>@endforeach</div>@endif
        </div>
    @empty
        <p class="small text-muted p-3 mb-0">No updates yet.</p>
    @endforelse
</x-cb.card>
