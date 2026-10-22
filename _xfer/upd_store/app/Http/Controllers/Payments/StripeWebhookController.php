<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Store\StorePaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Stripe webhook for one fashion house's online shop (URL carries the house). */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, string $tenant, StorePaymentService $payments): Response
    {
        $house = Tenant::where('public_id', $tenant)->first();
        if (! $house) {
            return response('Unknown', 404);
        }
        [$status, $message] = $payments->handleStripeWebhook($house, $request->getContent(), $request->header('Stripe-Signature'));

        return response($message, $status);
    }
}
