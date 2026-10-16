{{-- Client progress link (FR-PRJ-006). --}}
<x-cb.card title="Client progress page" icon="ri-share-forward-line" class="mt-3">
    @if($trackingUrl)
        <div class="input-group input-group-sm mb-2"><input class="form-control font-monospace small" readonly value="{{ $trackingUrl }}" id="trackUrl">
            <button class="btn btn-light" type="button" onclick="navigator.clipboard.writeText(document.getElementById('trackUrl').value); this.innerText='Copied'">Copy</button></div>
        <div class="small text-muted mb-2">Shows published milestones and updates and how many garments are ready — no prices or staff notes.
            {{ $project->tracking_expires_at ? 'Works until ' . $project->tracking_expires_at->timezone($tz)->format('d M Y') . '.' : 'No expiry.' }}
            {{ $project->tracking_viewed_at ? 'Last opened ' . $project->tracking_viewed_at->timezone($tz)->diffForHumans() . '.' : 'Not opened yet.' }}</div>
        <div class="d-flex gap-2">
            <a href="{{ $trackingUrl }}" target="_blank" class="btn btn-sm btn-light flex-grow-1"><i class="ri-external-link-line me-1"></i>Preview</a>
            @if($canManage)
                <form method="POST" action="{{ route('projects.tracking', $project) }}" onsubmit="return confirm('Switch the link off? Anyone with it will no longer see the page.')">@csrf<input type="hidden" name="action" value="revoke">
                    <button class="btn btn-sm btn-light text-danger">Switch off</button></form>
            @endif
        </div>
    @elseif($canManage && $project->status !== 'cancelled')
        <p class="small text-muted">Give the client a private link to follow progress without logging in.</p>
        <form method="POST" action="{{ route('projects.tracking', $project) }}" class="d-flex gap-2">@csrf
            <select name="days" class="form-select form-select-sm"><option value="">Never expires</option><option value="30">30 days</option><option value="90">90 days</option><option value="180">6 months</option></select>
            <button class="action-btn text-nowrap"><i class="ri-link"></i>Create link</button></form>
    @else
        <p class="small text-muted mb-0">No link has been shared with the client.</p>
    @endif
</x-cb.card>
