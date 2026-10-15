<?php

namespace App\Http\Controllers\Measurements;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\MeasurementSession;
use App\Models\MeasurementTemplate;
use App\Models\MeasurementTemplateVersion;
use App\Services\Measurements\MeasurementService;
use App\Support\Measurements\Units;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Capture, review and approve measurement sessions (SRS §5.4, §6.1). */
class SessionController extends Controller
{
    public function __construct(protected MeasurementService $service)
    {
        $this->middleware('permission:View measurement', ['only' => ['index', 'show']]);
        $this->middleware('permission:Capture measurement', ['only' => ['create', 'store', 'edit', 'update', 'destroy', 'correct']]);
        $this->middleware('permission:Approve measurement', ['only' => ['approve', 'reject']]);
    }

    /** Review queue + recent sessions. */
    public function index(Request $request): View
    {
        $status = $request->input('status', 'submitted');

        $sessions = MeasurementSession::with(['customer:id,public_id,first_name,last_name,customer_number', 'templateVersion.template', 'measurer:id,name'])
            ->whereHas('customer')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($status === 'submitted', fn ($q) => $q->orderBy('submitted_at'), fn ($q) => $q->latest('updated_at'))
            ->paginate(30)->withQueryString();

        return view('measurements.sessions.index', [
            'pagetitle' => 'Measurements',
            'sessions'  => $sessions,
            'status'    => $status,
            'counts'    => MeasurementSession::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
            'tz'        => CurrentTenant::get()->timezone,
        ]);
    }

    public function create(Request $request, Customer $customer): View|RedirectResponse
    {
        abort_if($customer->status === 'merged', 403);

        $templates = MeasurementTemplate::usable()->with('currentVersion')->orderBy('name')->get();
        $versionId = $request->integer('template_version_id');

        if (! $versionId) {
            return view('measurements.sessions.choose', [
                'pagetitle' => 'New measurements',
                'customer'  => $customer,
                'templates' => $templates,
            ]);
        }

        $version = MeasurementTemplateVersion::where('status', 'published')->with('parameters', 'template')->findOrFail($versionId);
        $session = new MeasurementSession([
            'customer_id' => $customer->id, 'template_version_id' => $version->id, 'status' => 'draft',
            'display_unit' => $this->unit($request), 'measured_at' => now(),
        ]);
        $session->setRelation('templateVersion', $version)->setRelation('customer', $customer)->setRelation('values', collect());

        return view('measurements.sessions.form', $this->formData($session, $customer));
    }

    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $data = $this->validated($request);
        $version = MeasurementTemplateVersion::where('status', 'published')->findOrFail($data['template_version_id']);

        $session = $this->service->start($customer, $version, [
            'display_unit' => $data['display_unit'],
            'measured_at'  => $this->measuredAt($data),
            'notes'        => $data['notes'] ?? null,
        ]);

        return $this->saveAndRespond($request, $session, $data);
    }

    public function show(Request $request, MeasurementSession $session): View
    {
        $session->load(['customer', 'templateVersion.parameters', 'templateVersion.template', 'values', 'measurer', 'verifier', 'supersedes']);
        $previous = $this->service->previousApproved($session);

        return view('measurements.sessions.show', [
            'pagetitle'  => 'Measurements · ' . $session->customer->name,
            'session'    => $session,
            'unit'       => $this->unit($request, $session->display_unit),
            'comparison' => $this->service->compare($session, $previous),
            'previous'   => $previous,
            'missing'    => $session->isEditable() ? $this->service->missingRequired($session) : [],
            'tz'         => CurrentTenant::get()->timezone,
        ]);
    }

    public function edit(Request $request, MeasurementSession $session): View
    {
        abort_unless($session->isEditable(), 403, 'Approved or submitted measurements cannot be edited.');
        $session->load(['customer', 'templateVersion.parameters', 'templateVersion.template', 'values']);

        return view('measurements.sessions.form', $this->formData($session, $session->customer, $this->unit($request, $session->display_unit)));
    }

    public function update(Request $request, MeasurementSession $session): RedirectResponse
    {
        abort_unless($session->isEditable(), 403, 'Approved or submitted measurements cannot be edited.');
        $data = $this->validated($request, $session);
        $session->update(['measured_at' => $this->measuredAt($data), 'notes' => $data['notes'] ?? null]);

        return $this->saveAndRespond($request, $session, $data);
    }

    public function approve(Request $request, MeasurementSession $session): RedirectResponse
    {
        $this->service->approve($session, $request->user());

        return redirect()->route('measurements.show', $session)->with('success', 'Approved. These are now the customer\'s current measurements for this template.');
    }

    public function reject(Request $request, MeasurementSession $session): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $this->service->reject($session, $request->user(), $data['reason']);

        return redirect()->route('measurements.show', $session)->with('success', 'Sent back for correction.');
    }

    public function correct(Request $request, MeasurementSession $session): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $new = $this->service->correct($session, $data['reason']);

        return redirect()->route('measurements.edit', $new)->with('success', 'Correction started from the approved values. The approved session stays until this one is approved.');
    }

    public function destroy(MeasurementSession $session): RedirectResponse
    {
        abort_unless($session->status === 'draft', 403, 'Only drafts can be deleted.');
        $customer = $session->customer;
        $session->values()->delete();
        $session->delete();

        return redirect()->route('customers.show', $customer)->with('success', 'Draft deleted.');
    }

    /* ------------------------------------------------------------------ */

    protected function saveAndRespond(Request $request, MeasurementSession $session, array $data): RedirectResponse
    {
        $this->service->saveValues($session, $data['values'] ?? [], $data['display_unit'], $data['value_notes'] ?? []);

        if ($request->input('action') === 'submit') {
            $session = $this->service->submit($session->fresh());

            // A measurer allowed to approve may approve straight away (§6.1 step 6).
            if ($request->boolean('approve_now') && $request->user()->can('Approve measurement')) {
                $this->service->approve($session, $request->user());

                return redirect()->route('measurements.show', $session)->with('success', 'Saved and approved.');
            }

            return redirect()->route('measurements.show', $session)->with('success', 'Submitted for review.');
        }

        return redirect()->route('measurements.edit', $session)->with('success', 'Draft saved.');
    }

    protected function validated(Request $request, ?MeasurementSession $session = null): array
    {
        return $request->validate([
            'template_version_id' => $session ? 'nullable' : 'required|integer',
            'display_unit'        => ['required', Rule::in(array_keys(Units::TO_MM))],
            'measured_at'         => 'nullable|date|before_or_equal:now',
            'notes'               => 'nullable|string|max:2000',
            'values'              => 'nullable|array',
            'values.*'            => 'array',
            'values.*.*'          => 'nullable|string|max:255',
            'value_notes'         => 'nullable|array',
            'value_notes.*'       => 'nullable|string|max:255',
            'action'              => 'nullable|in:save,submit',
            'approve_now'         => 'nullable|boolean',
        ]);
    }

    protected function measuredAt(array $data): Carbon
    {
        return ! empty($data['measured_at'])
            ? Carbon::parse($data['measured_at'], CurrentTenant::get()->timezone)->utc()
            : now();
    }

    protected function formData(MeasurementSession $session, Customer $customer, ?string $unit = null): array
    {
        $previous = $session->exists ? $this->service->previousApproved($session)
            : MeasurementSession::where('customer_id', $customer->id)->where('status', 'approved')->latest('verified_at')->with('values')->first();

        return [
            'pagetitle' => 'Measure ' . $customer->name,
            'session'   => $session,
            'customer'  => $customer,
            'unit'      => $unit ?? $session->display_unit,
            'previous'  => $previous ? $previous->values->keyBy(fn ($v) => $v->key()) : collect(),
            'previousSession' => $previous,
            'tz'        => CurrentTenant::get()->timezone,
        ];
    }

    protected function unit(Request $request, ?string $default = null): string
    {
        $u = $request->input('unit', $default ?? CurrentTenant::get()->setting('measurement_display_unit', 'in'));

        return Units::isValid($u) ? $u : 'in';
    }
}
