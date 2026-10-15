<?php

namespace App\Http\Controllers\Measurements;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\MeasurementRequest;
use App\Models\MeasurementTemplateVersion;
use App\Services\Measurements\MeasurementRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Staff side of customer measurement links (SRS FR-LNK-001/002). */
class RequestController extends Controller
{
    public function __construct(protected MeasurementRequestService $service)
    {
        $this->middleware('permission:Send measurement request');
    }

    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'template_version_id' => 'required|integer',
            'days'                => 'required|integer|min:1|max:60',
            'deadline'            => 'nullable|date|after_or_equal:today',
            'message'             => 'nullable|string|max:500',
        ]);
        $version = MeasurementTemplateVersion::where('status', 'published')->findOrFail($data['template_version_id']);

        [$req, $token] = $this->service->create($customer, $version, (int) $data['days'], $data['message'] ?? null, $data['deadline'] ?? null);

        // The link is shown once; only its hash is stored.
        return redirect()->to(route('customers.show', $customer) . '#measurements')->with('measurement_link', [
            'url'      => $this->service->url($token),
            'expires'  => $req->expires_at,
            'customer' => $customer->first_name,
            'phone'    => $customer->whatsapp ?: $customer->phone,
            'house'    => $customer->tenant?->name,
        ]);
    }

    public function revoke(Request $request, MeasurementRequest $measurementRequest): RedirectResponse
    {
        $this->service->revoke($measurementRequest, $request->user());

        return back()->with('success', 'Link revoked. It no longer works.');
    }
}
