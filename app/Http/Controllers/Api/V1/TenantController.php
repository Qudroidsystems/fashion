<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/v1/tenants · GET /api/v1/tenants/current */
class TenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = $request->user()->memberships()->active()->whereHas('tenant')->with('tenant')->get()
            ->map(fn ($m) => [
                'public_id' => $m->tenant->public_id,
                'name'      => $m->tenant->name,
                'slug'      => $m->tenant->slug,
                'logo_url'  => $m->tenant->logo_url,
                'is_owner'  => $m->is_owner,
                'usable'    => $m->tenant->isUsable(),
            ])->values();

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function current(): JsonResponse
    {
        $tenant = CurrentTenant::require();

        return response()->json([
            'success' => true,
            'data'    => [
                'public_id'     => $tenant->public_id,
                'name'          => $tenant->name,
                'slug'          => $tenant->slug,
                'logo_url'      => $tenant->logo_url,
                'brand_color'   => $tenant->brand_color,
                'timezone'      => $tenant->timezone,
                'locale'        => $tenant->locale,
                'currency_code' => $tenant->currency_code,
                'status'        => $tenant->status,
                'measurement_display_unit' => $tenant->setting('measurement_display_unit', 'in'),
                'branches'      => Branch::active()->orderByDesc('is_main')->orderBy('name')
                    ->get(['id', 'name', 'code', 'type', 'is_main']),
                'membership'    => CurrentTenant::membership()?->only(['branch_id', 'job_title', 'is_owner']),
            ],
        ]);
    }
}
