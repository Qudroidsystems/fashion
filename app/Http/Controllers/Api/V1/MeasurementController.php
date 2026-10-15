<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\MeasurementRequest;
use App\Models\MeasurementSession;
use App\Models\MeasurementTemplate;
use App\Models\MeasurementTemplateVersion;
use App\Models\MeasurementValue;
use App\Services\Measurements\MeasurementRequestService;
use App\Services\Measurements\MeasurementService;
use App\Support\Measurements\Units;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * SRS §8.2 Measurements (staff app):
 *   GET  /measurement-templates
 *   GET  /customers/{id}/measurement-sessions        POST (create; optional submit)
 *   GET  /measurement-sessions/{id}                  PATCH (draft/rejected only)
 *   POST /measurement-sessions/{id}/submit | approve | reject
 *   GET  /measurement-sessions/{id}/comparison
 *   POST /customers/{id}/measurement-requests        POST /measurement-requests/{id}/revoke
 *
 * Values are sent keyed by parameter code: {"chest": "38.5", "sleeve_length": {"left": "24", "right": "24 1/4"}}
 * in the session's `unit`. Lengths are returned in canonical millimetres plus the requested display unit.
 */
class MeasurementController extends Controller
{
    public function __construct(protected MeasurementService $service, protected MeasurementRequestService $requests)
    {
        $this->middleware('permission:View measurement', ['only' => ['templates', 'index', 'show', 'comparison']]);
        $this->middleware('permission:Capture measurement', ['only' => ['store', 'update', 'submit']]);
        $this->middleware('permission:Approve measurement', ['only' => ['approve', 'reject']]);
        $this->middleware('permission:Send measurement request', ['only' => ['storeRequest', 'revokeRequest']]);
    }

    public function templates(): JsonResponse
    {
        $templates = MeasurementTemplate::usable()->with('currentVersion.parameters')->orderBy('name')->get();

        return response()->json(['success' => true, 'data' => $templates->map(fn ($t) => [
            'id'               => $t->id,
            'code'             => $t->code,
            'name'             => $t->name,
            'garment_category' => $t->garment_category,
            'audience'         => $t->audience,
            'version_id'       => $t->current_version_id,
            'version_no'       => $t->currentVersion?->version_no,
            'parameters'       => $t->currentVersion?->parameters->map(fn ($p) => [
                'code' => $p->code, 'label' => $p->label, 'data_type' => $p->data_type, 'sides' => $p->sides(),
                'required' => (bool) $p->is_required, 'options' => $p->options, 'min_mm' => $p->min_mm, 'max_mm' => $p->max_mm,
                'instructions' => $p->instructions, 'guidance' => $p->guidance,
            ])->values() ?? [],
        ])]);
    }

    public function index(Request $request, Customer $customer): JsonResponse
    {
        $sessions = MeasurementSession::where('customer_id', $customer->id)->with('templateVersion.template')
            ->latest('measured_at')->get();

        return response()->json(['success' => true, 'data' => $sessions->map(fn ($s) => $this->summary($s))]);
    }

    public function store(Request $request, Customer $customer): JsonResponse
    {
        abort_if($customer->status === 'merged', 422, 'This customer was merged into another record.');
        $data = $this->validated($request, true);
        $version = MeasurementTemplateVersion::where('status', 'published')->with('parameters')->find($data['template_version_id']);
        abort_unless($version, 422, 'Choose a published template version.');

        $session = $this->service->start($customer, $version, [
            'display_unit'   => $data['unit'],
            'measured_at'    => isset($data['measured_at']) ? Carbon::parse($data['measured_at'])->utc() : now(),
            'notes'          => $data['notes'] ?? null,
            'capture_method' => 'staff_in_person',
        ]);
        $this->service->saveValues($session, $this->mapValues($version, $data['values'] ?? []), $data['unit']);

        if ($request->boolean('submit')) {
            $session = $this->service->submit($session->fresh());
        }

        return response()->json(['success' => true, 'data' => $this->detail($session->fresh(), $data['unit'])], 201);
    }

    public function show(Request $request, MeasurementSession $session): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->detail($session, $this->unit($request, $session))]);
    }

    public function update(Request $request, MeasurementSession $session): JsonResponse
    {
        abort_unless($session->isEditable(), 409, 'Only draft or sent-back measurements can be changed.');
        $data = $this->validated($request, false);
        $unit = $data['unit'] ?? $session->display_unit;

        $session->update(array_filter([
            'measured_at' => isset($data['measured_at']) ? Carbon::parse($data['measured_at'])->utc() : null,
            'notes'       => $data['notes'] ?? null,
        ], fn ($v) => $v !== null));

        if (array_key_exists('values', $data)) {
            // PATCH semantics: only the codes sent are changed.
            $mapped = $this->mapValues($session->templateVersion()->with('parameters')->first(), $data['values'] ?? []);
            $existing = $session->values()->get();
            $merged = [];
            foreach ($session->templateVersion->parameters as $tp) {
                foreach ($tp->sides() as $side) {
                    if (array_key_exists($tp->id, $mapped) && array_key_exists($side, $mapped[$tp->id])) {
                        $merged[$tp->id][$side] = $mapped[$tp->id][$side];
                    } elseif ($v = $existing->first(fn ($x) => $x->template_parameter_id === $tp->id && $x->side === $side)) {
                        $merged[$tp->id][$side] = $this->rawValue($v, $tp->isLength(), $unit);
                    }
                }
            }
            $this->service->saveValues($session, $merged, $unit);
        }

        return response()->json(['success' => true, 'data' => $this->detail($session->fresh(), $unit)]);
    }

    public function submit(Request $request, MeasurementSession $session): JsonResponse
    {
        abort_unless($session->isEditable(), 409, 'Only draft or sent-back measurements can be submitted.');
        $session = $this->service->submit($session);

        return response()->json(['success' => true, 'data' => $this->detail($session, $this->unit($request, $session))]);
    }

    public function approve(Request $request, MeasurementSession $session): JsonResponse
    {
        abort_unless($session->status === 'submitted', 409, 'Only submitted measurements can be approved.');
        $session = $this->service->approve($session, $request->user());

        return response()->json(['success' => true, 'data' => $this->detail($session, $this->unit($request, $session))]);
    }

    public function reject(Request $request, MeasurementSession $session): JsonResponse
    {
        abort_unless($session->status === 'submitted', 409, 'Only submitted measurements can be sent back.');
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $session = $this->service->reject($session, $request->user(), $data['reason']);

        return response()->json(['success' => true, 'data' => $this->detail($session, $this->unit($request, $session))]);
    }

    public function comparison(Request $request, MeasurementSession $session): JsonResponse
    {
        $unit = $this->unit($request, $session);
        $previous = $this->service->previousApproved($session);

        return response()->json(['success' => true, 'data' => [
            'session_id'        => $session->public_id,
            'compared_with'     => $previous?->public_id,
            'compared_with_date'=> $previous?->verified_at?->toIso8601String(),
            'unit'              => $unit,
            'rows'              => $this->service->compare($session, $previous)->map(fn ($r) => [
                'code'       => $r['code'],
                'label'      => $r['label'],
                'side'       => $r['side'],
                'current'    => $r['current'] ? $this->valueOut($r['current'], $unit) : null,
                'previous'   => $r['previous'] ? $this->valueOut($r['previous'], $unit) : null,
                'comparable' => $r['comparable'],
                'delta_mm'   => $r['delta_mm'],
                'delta'      => $r['delta_mm'] !== null ? round(Units::fromMm($r['delta_mm'], $unit), 2) : null,
                'delta_pct'  => $r['delta_pct'],
                'flagged'    => $r['flagged'],
            ])->values(),
        ]]);
    }

    public function storeRequest(Request $request, Customer $customer): JsonResponse
    {
        abort_if($customer->status === 'merged', 422, 'This customer was merged into another record.');
        $data = $request->validate([
            'template_version_id' => 'required|integer',
            'days'                => 'nullable|integer|min:1|max:60',
            'deadline'            => 'nullable|date|after_or_equal:today',
            'message'             => 'nullable|string|max:500',
        ]);
        $version = MeasurementTemplateVersion::where('status', 'published')->find($data['template_version_id']);
        abort_unless($version, 422, 'Choose a published template version.');

        [$req, $token] = $this->requests->create($customer, $version, (int) ($data['days'] ?? 7), $data['message'] ?? null, $data['deadline'] ?? null);

        return response()->json(['success' => true, 'data' => [
            'id'         => $req->public_id,
            'url'        => $this->requests->url($token),   // returned once; only the hash is stored
            'expires_at' => $req->expires_at->toIso8601String(),
            'status'     => $req->status,
        ]], 201);
    }

    public function revokeRequest(Request $request, MeasurementRequest $measurementRequest): JsonResponse
    {
        $this->requests->revoke($measurementRequest, $request->user());

        return response()->json(['success' => true, 'data' => ['id' => $measurementRequest->public_id, 'status' => 'revoked']]);
    }

    /* ------------------------------------------------------------------ */

    protected function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'template_version_id' => $creating ? 'required|integer' : 'prohibited',
            'unit'                => [$creating ? 'required' : 'nullable', Rule::in(array_keys(Units::TO_MM))],
            'measured_at'         => 'nullable|date|before_or_equal:now',
            'notes'               => 'nullable|string|max:2000',
            'values'              => 'nullable|array',
            'submit'              => 'nullable|boolean',
        ]);
    }

    /** {"chest": "38", "sleeve_length": {"left": "24"}} → [tpId => ['both' => '38'], …]; unknown codes are rejected. */
    protected function mapValues(MeasurementTemplateVersion $version, array $values): array
    {
        $byCode = $version->parameters->keyBy('code');
        $unknown = array_diff(array_keys($values), $byCode->keys()->all());
        if ($unknown) {
            throw \Illuminate\Validation\ValidationException::withMessages(['values' => 'Not in this template: ' . implode(', ', $unknown) . '.']);
        }

        $out = [];
        foreach ($values as $code => $value) {
            $tp = $byCode[$code];
            if (is_array($value)) {
                foreach (array_intersect_key($value, array_flip($tp->sides())) as $side => $v) {
                    $out[$tp->id][$side] = $v === null ? null : (string) $v;
                }
            } else {
                $out[$tp->id][$tp->sides()[0]] = $value === null ? null : (string) $value;
            }
        }

        return $out;
    }

    protected function rawValue(MeasurementValue $v, bool $isLength, string $unit): ?string
    {
        if ($isLength) {
            return $v->value_mm !== null ? (string) round(Units::fromMm($v->value_mm, $unit), 4) : null;
        }

        return $v->text_value ?? ($v->number_value !== null ? (string) $v->number_value : null);
    }

    protected function unit(Request $request, MeasurementSession $session): string
    {
        $u = $request->query('unit', $session->display_unit);

        return Units::isValid($u) ? $u : 'in';
    }

    protected function summary(MeasurementSession $s): array
    {
        return [
            'id'             => $s->public_id,
            'status'         => $s->status,
            'template'       => $s->templateVersion?->template?->name,
            'template_version_id' => $s->template_version_id,
            'version_no'     => $s->templateVersion?->version_no,
            'capture_method' => $s->capture_method,
            'measured_at'    => $s->measured_at?->toIso8601String(),
            'submitted_at'   => $s->submitted_at?->toIso8601String(),
            'verified_at'    => $s->verified_at?->toIso8601String(),
        ];
    }

    protected function detail(MeasurementSession $s, string $unit): array
    {
        $s->loadMissing('templateVersion.template', 'templateVersion.parameters', 'values', 'customer:id,public_id,first_name,last_name');

        return $this->summary($s) + [
            'customer'          => ['id' => $s->customer?->public_id, 'name' => $s->customer?->name],
            'unit'              => $unit,
            'entered_unit'      => $s->display_unit,
            'notes'             => $s->notes,
            'rejection_reason'  => $s->rejection_reason,
            'supersedes'        => $s->supersedes_id ? MeasurementSession::whereKey($s->supersedes_id)->value('public_id') : null,
            'change_summary'    => $s->change_summary,
            'missing_required'  => $s->isEditable() ? $this->service->missingRequired($s) : [],
            'values'            => $s->values->sortBy(fn ($v) => $v->templateParameter?->sort_order)->map(fn ($v) => $this->valueOut($v, $unit))->values(),
        ];
    }

    protected function valueOut(MeasurementValue $v, string $unit): array
    {
        return [
            'code'             => $v->parameter_code,
            'side'             => $v->side,
            'value_mm'         => $v->value_mm !== null ? (float) $v->value_mm : null,
            'value'            => $v->value_mm !== null ? (float) Units::format($v->value_mm, $unit) : ($v->number_value !== null ? (float) $v->number_value : $v->text_value),
            'original_value'   => $v->original_value,
            'original_unit'    => $v->original_unit,
            'validation_state' => $v->validation_state,
            'note'             => $v->note,
        ];
    }
}
