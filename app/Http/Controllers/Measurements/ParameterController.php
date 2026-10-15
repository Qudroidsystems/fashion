<?php

namespace App\Http\Controllers\Measurements;

use App\Http\Controllers\Controller;
use App\Models\MeasurementParameter;
use App\Services\Audit\AuditLogger;
use App\Support\Measurements\Units;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Measurement parameters (SRS FR-MEA-001/003). Ranges are typed in the house's display unit. */
class ParameterController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:Manage measurement templates');
    }

    public function index(Request $request): View
    {
        $unit = $this->unit($request);

        return view('measurements.parameters', [
            'pagetitle'  => 'Measurement parameters',
            'parameters' => MeasurementParameter::orderBy('sort_order')->orderBy('label')->get(),
            'unit'       => $unit,
            'types'      => MeasurementParameter::TYPES,
            'areas'      => MeasurementParameter::AREAS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $param = MeasurementParameter::create($this->validated($request) + [
            'sort_order' => (int) MeasurementParameter::max('sort_order') + 1,
        ]);
        AuditLogger::log('measurement_parameter.created', $param, after: $param->only(['code', 'label', 'data_type']));

        return back()->with('success', "{$param->label} added. Add it to a template to start using it.");
    }

    public function update(Request $request, MeasurementParameter $parameter): RedirectResponse
    {
        $data = $this->validated($request, $parameter);
        $before = $parameter->only(array_keys($data));
        $parameter->update($data);
        [$b, $a] = AuditLogger::diff($before, $parameter->only(array_keys($data)));
        if ($a) {
            AuditLogger::log('measurement_parameter.updated', $parameter, $b, $a);
        }

        return back()->with('success', "{$parameter->label} saved. Published templates keep the old definition until you publish a new version.");
    }

    protected function validated(Request $request, ?MeasurementParameter $param = null): array
    {
        $data = $request->validate([
            'code'         => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('measurement_parameters', 'code')->where('tenant_id', CurrentTenant::id())->ignore($param?->id)],
            'label'        => 'required|string|max:80',
            'body_area'    => ['nullable', Rule::in(array_keys(MeasurementParameter::AREAS))],
            'data_type'    => ['required', Rule::in(array_keys(MeasurementParameter::TYPES))],
            'is_bilateral' => 'nullable|boolean',
            'options'      => 'nullable|string|max:1000',
            'min'          => 'nullable|numeric|min:0',
            'max'          => 'nullable|numeric|min:0|gte:min',
            'threshold'    => 'nullable|numeric|min:0',
            'instructions' => 'nullable|string|max:1000',
            'description'  => 'nullable|string|max:1000',
            'is_active'    => 'nullable|boolean',
        ], ['code.regex' => 'Use lowercase letters, numbers and underscores, e.g. sleeve_length.']);

        // The code is the comparison key across versions; don't let it change once in use.
        if ($param && $param->code !== $data['code'] && \App\Models\MeasurementTemplateParameter::where('parameter_id', $param->id)->exists()) {
            $data['code'] = $param->code;
        }

        $unit = $this->unit($request);
        $isLength = $data['data_type'] === 'length';

        return [
            'code'         => $data['code'],
            'label'        => $data['label'],
            'body_area'    => $data['body_area'] ?? null,
            'data_type'    => $data['data_type'],
            'is_bilateral' => $request->boolean('is_bilateral'),
            'options'      => $data['data_type'] === 'select'
                ? array_values(array_filter(array_map('trim', explode(',', (string) ($data['options'] ?? '')))))
                : null,
            'min_mm'       => $isLength && filled($data['min'] ?? null) ? Units::toMm($data['min'], $unit) : null,
            'max_mm'       => $isLength && filled($data['max'] ?? null) ? Units::toMm($data['max'], $unit) : null,
            'change_threshold_mm' => $isLength && filled($data['threshold'] ?? null) ? Units::toMm($data['threshold'], $unit) : null,
            'instructions' => $data['instructions'] ?? null,
            'description'  => $data['description'] ?? null,
            'is_active'    => $request->has('is_active') ? $request->boolean('is_active') : true,
        ];
    }

    protected function unit(Request $request): string
    {
        $u = $request->input('unit', CurrentTenant::get()->setting('measurement_display_unit', 'in'));

        return Units::isValid($u) ? $u : 'in';
    }
}
