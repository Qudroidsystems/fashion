<?php

namespace App\Http\Controllers\Measurements;

use App\Http\Controllers\Controller;
use App\Models\MeasurementRequest;
use App\Services\Measurements\MeasurementRequestService;
use App\Support\Measurements\Units;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page a customer opens from a measurement link — no account or app
 * needed (SRS FR-LNK-003/004). Rate-limited in routes; tenant context comes
 * from the request record, never from the URL.
 */
class PublicMeasurementController extends Controller
{
    public function __construct(protected MeasurementRequestService $service)
    {
    }

    public function form(Request $request, string $token): Response
    {
        $req = $this->service->findByToken($token);

        if (! $req) {
            return response()->view('measurements.public.closed', ['reason' => 'invalid', 'house' => null], 404);
        }

        return CurrentTenant::run($req->tenant, function ($tenant) use ($req, $request) {
            if (! $req->isOpen()) {
                return response()->view('measurements.public.closed', ['reason' => $req->effectiveStatus(), 'house' => $tenant], 410);
            }
            if (! $req->opened_at) {
                $req->forceFill(['opened_at' => now()])->save();
            }
            $req->load('templateVersion.parameters');
            $unit = $request->input('unit', $tenant->setting('measurement_display_unit', 'in'));

            return response()->view('measurements.public.form', [
                'req'    => $req,
                'house'  => $tenant,
                'unit'   => Units::isValid($unit) && in_array($unit, ['in', 'cm'], true) ? $unit : 'in',
                'token'  => $request->route('token'),
            ]);
        });
    }

    public function submit(Request $request, string $token): Response
    {
        $req = $this->service->findByToken($token);
        abort_unless($req, 404);

        return CurrentTenant::run($req->tenant, function ($tenant) use ($req, $request, $token) {
            if (! $req->isOpen()) {
                return response()->view('measurements.public.closed', ['reason' => $req->effectiveStatus(), 'house' => $tenant], 410);
            }

            $data = $request->validate([
                'unit'       => ['required', Rule::in(['in', 'cm'])],
                'values'     => 'required|array',
                'values.*'   => 'array',
                'values.*.*' => 'nullable|string|max:100',
                'note'       => 'nullable|string|max:1000',
                'confirm'    => 'accepted',
            ], ['confirm.accepted' => 'Please confirm the measurements are correct.']);

            try {
                $this->service->submit($req->fresh(['customer', 'templateVersion.parameters']), $data['values'], $data['unit'], $data['note'] ?? null);
            } catch (ValidationException $e) {
                return redirect()->route('measure.form', ['token' => $token, 'unit' => $data['unit']])->withErrors($e->errors())->withInput();
            }

            return response()->view('measurements.public.thanks', ['house' => $tenant, 'req' => $req]);
        });
    }
}
