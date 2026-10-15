<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\PaymentIntent;
use App\Models\Tenant;
use App\Services\Payments\OnlinePaymentService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Paystack webhook and the page customers return to after paying (no login). */
class PublicPaymentController extends Controller
{
    public function __construct(protected OnlinePaymentService $online)
    {
    }

    public function webhook(Request $request, string $tenant): Response
    {
        $house = Tenant::where('public_id', $tenant)->first();
        if (! $house) {
            return response('Unknown', 404);
        }
        [$status, $message] = $this->online->handleWebhook($house, $request->getContent(), $request->header('x-paystack-signature'));

        return response($message, $status);
    }

    /** Paystack sends the customer here. We still verify with Paystack before showing "paid". */
    public function return(Request $request): Response
    {
        $ref = (string) $request->query('reference', $request->query('trxref', ''));
        $intent = $ref !== '' ? PaymentIntent::withoutGlobalScopes()->where('reference', $ref)->first() : null;
        if (! $intent) {
            return response()->view('payments.return', ['intent' => null, 'house' => null], 404);
        }
        $house = Tenant::find($intent->tenant_id);

        return CurrentTenant::run($house, function () use ($intent, $house) {
            try {
                $intent = $this->online->confirm($intent);
            } catch (\Throwable) {
                $intent = $intent->fresh();
            }

            return response()->view('payments.return', ['intent' => $intent->load('order', 'invoice', 'customer', 'payment'), 'house' => $house]);
        });
    }
}
