{{-- Customer › Measurements tab (SRS §5.4, §5.5). Expects $mSessions, $mCurrent, $mRequests, $mTemplates. --}}
@php
    use App\Support\Measurements\Units;
    $mUnit = \App\Support\Tenancy\CurrentTenant::get()->setting('measurement_display_unit', 'in');
    $canCapture = auth()->user()->can('Capture measurement') && $c->status !== 'merged';
    $canSend = auth()->user()->can('Send measurement request') && $c->status !== 'merged';
    $link = session('measurement_link');
@endphp
<div class="tab-pane" id="measurements">

    @if($link)
        @php
            $msg = "Hello {$link['customer']}, please use this secure link to send your measurements to {$link['house']}: {$link['url']}";
            $wa = $link['phone'] ? preg_replace('/\D/', '', preg_replace('/^0/', '234', $link['phone'])) : '';
        @endphp
        <div class="cb-banner success align-items-start">
            <i class="ri-link"></i>
            <div class="flex-grow-1">
                <div class="fw-semibold mb-1">Measurement link created — copy it now, it is only shown once.</div>
                <div class="input-group input-group-sm mb-2">
                    <input type="text" class="form-control" value="{{ $link['url'] }}" id="mLinkUrl" readonly>
                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('mLinkUrl').value);this.innerText='Copied'">Copy</button>
                    <a class="btn btn-success" target="_blank" rel="noopener" href="https://wa.me/{{ $wa }}?text={{ rawurlencode($msg) }}"><i class="ri-whatsapp-line me-1"></i>WhatsApp</a>
                </div>
                <div class="small text-muted">Works until {{ \Illuminate\Support\Carbon::parse($link['expires'])->timezone($tz)->format('d M Y, H:i') }}.</div>
            </div>
        </div>
    @endif

    <x-cb.card title="Current measurements" icon="ri-ruler-2-line">
        <x-slot:tools>
            @if($canCapture && $mTemplates->isNotEmpty())
                <a href="{{ route('measurements.create', $c) }}" class="action-btn btn-primary-cb"><i class="ri-add-line"></i>New measurements</a>
            @endif
            @if($canSend && $mTemplates->isNotEmpty())
                <button type="button" class="action-btn" data-bs-toggle="collapse" data-bs-target="#sendLinkBox"><i class="ri-send-plane-line"></i>Send link</button>
            @endif
        </x-slot:tools>

        @if($canSend && $mTemplates->isNotEmpty())
            <div class="collapse mb-3" id="sendLinkBox">
                <form method="POST" action="{{ route('measurement-requests.store', $c) }}" class="row g-2 p-3 rounded-3" style="background:var(--cb-soft, #f6f4ff)">
                    @csrf
                    <div class="col-md-4">
                        <label class="form-label small">Template</label>
                        <select name="template_version_id" class="form-select" required>
                            @foreach($mTemplates as $t)<option value="{{ $t->current_version_id }}">{{ $t->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Link valid (days)</label>
                        <input type="number" name="days" class="form-control" value="7" min="1" max="60" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Send by (optional)</label>
                        <input type="date" name="deadline" class="form-control" min="{{ now($tz)->toDateString() }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Message (optional)</label>
                        <input type="text" name="message" class="form-control" maxlength="500" placeholder="e.g. For your wedding outfit">
                    </div>
                    <div class="col-12 d-flex justify-content-between align-items-center">
                        <span class="small text-muted">The customer opens the link on their phone — no account needed. Their values are reviewed before use.</span>
                        <button class="action-btn btn-primary-cb"><i class="ri-link"></i>Create link</button>
                    </div>
                </form>
            </div>
        @endif

        @forelse($mCurrent as $s)
            @php $vals = $s->values->whereNotNull('value_mm')->take(8); @endphp
            <div class="py-3 border-bottom">
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <div>
                        <a href="{{ route('measurements.show', $s) }}" class="fw-semibold">{{ $s->templateVersion->template->name }}</a>
                        <div class="small text-muted">Approved {{ $s->verified_at?->timezone($tz)->format('d M Y') }} · measured by {{ $s->measurer?->name ?? 'customer' }}</div>
                    </div>
                    <a href="{{ route('measurements.show', $s) }}" class="action-btn"><i class="ri-eye-line"></i>View all</a>
                </div>
                @if($vals->isNotEmpty())
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        @foreach($vals as $v)
                            <span class="term-chip">{{ $v->templateParameter?->label ?? $v->parameter_code }}{{ $v->side !== 'both' ? ' (' . substr($v->side, 0, 1) . ')' : '' }}: <strong>{{ Units::format($v->value_mm, $mUnit, true) }}</strong></span>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <div class="empty-state py-4">
                <i class="ri-ruler-2-line"></i>
                <p class="mb-0">No approved measurements yet.</p>
            </div>
        @endforelse
    </x-cb.card>

    <x-cb.card title="History" icon="ri-history-line" class="mt-3" :count="$mSessions->count()" :flush="true">
        @if($mSessions->isEmpty())
            <p class="text-muted small p-3 mb-0">No measurement sessions.</p>
        @else
            <div class="table-responsive">
                <table class="cb-table stack mb-0">
                    <thead><tr><th>Measured</th><th>Template</th><th>How</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @foreach($mSessions as $s)
                        <tr>
                            <td data-label="Measured">{{ $s->measured_at?->timezone($tz)->format('d M Y') }}</td>
                            <td data-label="Template">{{ $s->templateVersion->label }}</td>
                            <td data-label="How">{{ \App\Models\MeasurementSession::METHODS[$s->capture_method] ?? $s->capture_method }}</td>
                            <td data-label="Status"><span class="status-pill {{ $s->status_pill }}">{{ ucfirst($s->status) }}</span></td>
                            <td class="text-end">
                                @if($s->isEditable() && $canCapture)
                                    <a href="{{ route('measurements.edit', $s) }}" class="action-btn"><i class="ri-edit-line"></i>Continue</a>
                                @else
                                    <a href="{{ route('measurements.show', $s) }}" class="action-btn"><i class="ri-eye-line"></i>Open</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-cb.card>

    @if($mRequests->isNotEmpty())
        <x-cb.card title="Measurement links" icon="ri-link" class="mt-3" :flush="true">
            <div class="table-responsive">
                <table class="cb-table stack mb-0">
                    <thead><tr><th>Sent</th><th>Template</th><th>Status</th><th>Expires</th><th></th></tr></thead>
                    <tbody>
                    @foreach($mRequests as $r)
                        @php
                            $st = $r->effectiveStatus();
                            $pill = ['pending' => 'st-warning', 'submitted' => 'st-success', 'expired' => 'st-muted', 'revoked' => 'st-danger', 'locked' => 'st-danger'][$st] ?? 'st-muted';
                        @endphp
                        <tr>
                            <td data-label="Sent">{{ $r->created_at->timezone($tz)->format('d M Y') }}<div class="small text-muted">{{ $r->creator?->name }}</div></td>
                            <td data-label="Template">{{ $r->templateVersion?->template?->name }}</td>
                            <td data-label="Status"><span class="status-pill {{ $pill }}">{{ $st === 'pending' && $r->opened_at ? 'Opened' : ucfirst($st) }}</span></td>
                            <td data-label="Expires">{{ $r->expires_at->timezone($tz)->format('d M Y') }}</td>
                            <td class="text-end">
                                @if($r->session_id && $r->session)
                                    <a href="{{ route('measurements.show', $r->session) }}" class="action-btn"><i class="ri-eye-line"></i>Review</a>
                                @elseif($r->isOpen())
                                    <form method="POST" action="{{ route('measurement-requests.revoke', $r) }}" class="d-inline" onsubmit="return confirm('Revoke this link? It will stop working.')">
                                        @csrf
                                        <button class="action-btn text-danger"><i class="ri-close-circle-line"></i>Revoke</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-cb.card>
    @endif
</div>
