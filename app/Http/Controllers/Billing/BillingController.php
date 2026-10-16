<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\Entitlements;
use App\Services\Billing\SubscriptionService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** A house's own plan: compare plans, pay, cancel, see receipts (owners and settings managers). */
class BillingController extends Controller
{
    public function __construct(protected SubscriptionService $subs, protected Entitlements $entitlements)
    {
        $this->middleware('permission:Manage tenant settings')->except('webhook');
    }

    public function index(): View
    {
        abort_unless($this->entitlements->enabled(), 404);
        $tenant = CurrentTenant::require();
        $sub = $this->subs->ensure($tenant)->load('plan', 'pendingPlan');

        return view('billing.index', [
            'pagetitle' => 'Plan & billing',
            'sub'       => $sub,
            'plans'     => Plan::where('is_active', true)->where(fn ($q) => $q->where('is_public', true)->orWhere('id', $sub->plan_id))->ordered()->get(),
            'usage'     => $this->entitlements->usageSummary($tenant),
            'invoices'  => SubscriptionInvoice::where('tenant_id', $tenant->id)->with('plan')->latest('id')->limit(24)->get(),
            'canPay'    => \App\Services\Payments\PaystackGateway::platform()->enabled(),
            'tz'        => $tenant->timezone,
        ]);
    }

    public function choose(Request $request): RedirectResponse
    {
        $data = $request->validate(['plan' => 'required|string|exists:plans,code', 'interval' => 'required|in:monthly,yearly']);
        $result = $this->subs->choose(CurrentTenant::require(), Plan::where('code', $data['plan'])->firstOrFail(), $data['interval'], $request->user());

        return isset($result['redirect']) ? redirect()->away($result['redirect']) : back()->with('success', $result['message']);
    }

    /** Paystack sends the owner back here; we check with Paystack before believing it. */
    public function return(Request $request): RedirectResponse
    {
        $reference = (string) $request->query('reference', $request->query('trxref', ''));
        $invoice = SubscriptionInvoice::where('reference', $reference)->where('tenant_id', CurrentTenant::id())->first();
        abort_unless($invoice, 404);
        $invoice = $this->subs->confirm($reference);

        return redirect()->route('billing.index')->with(...match ($invoice->status) {
            'paid'   => ['success', 'Payment received — you are on the ' . $invoice->plan->name . ' plan.'],
            'failed' => ['warning', 'The payment did not go through' . ($invoice->failure_reason ? ' (' . $invoice->failure_reason . ')' : '') . '. You can try again.'],
            default  => ['warning', 'We are still waiting for Paystack to confirm the payment. This page will update when it does.'],
        });
    }

    public function cancel(): RedirectResponse
    {
        $sub = $this->subs->cancel(CurrentTenant::require());

        return back()->with('success', $sub->current_period_end
            ? 'Your plan will not renew. You keep it until ' . $sub->current_period_end->format('j M Y') . ', then move to the Free plan. Nothing is deleted.'
            : 'Done.');
    }

    /** Platform Paystack account callback (no session, CSRF-exempt under webhooks/*). */
    public function webhook(Request $request): Response
    {
        [$code, $message] = $this->subs->handleWebhook($request->getContent(), $request->header('x-paystack-signature'));

        return response($message, $code);
    }
}
