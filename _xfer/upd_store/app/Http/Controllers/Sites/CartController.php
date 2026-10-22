<?php

namespace App\Http\Controllers\Sites;

use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** The basket page, the slide-out mini cart (JSON) and changes to the basket. */
class CartController extends StorefrontController
{
    public function show(Request $request, string $site): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request) {
            $cart = $this->carts->cart($request, $t, $this->auth->account($t));

            return $this->page($request, $t, 'sites.cart', ['summary' => $this->carts->summary($t, $cart), 'cart' => $cart]);
        }, true);
    }

    public function mini(Request $request, string $site): Response
    {
        return $this->house($request, $site, fn (Tenant $t) => response()->json($this->payload($request, $t)), true);
    }

    public function add(Request $request, string $site): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request) {
            $data = $request->validate(['variant_id' => 'required|integer', 'quantity' => 'nullable|integer|min:1|max:99', 'buy_now' => 'nullable|boolean']);

            return $this->respond($request, $t, function () use ($request, $t, $data) {
                $cart = $this->carts->cart($request, $t, $this->auth->account($t), true);
                $this->carts->add($t, $cart, (int) $data['variant_id'], (int) ($data['quantity'] ?? 1));
            }, 'Added to your basket.', ! empty($data['buy_now']) ? '/checkout' : null);
        }, true);
    }

    public function update(Request $request, string $site): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request) {
            $data = $request->validate(['item' => 'required|integer', 'set' => 'nullable|integer|min:0|max:99', 'quantity' => 'required_without:set|nullable|integer|min:0|max:99']);
            $data['quantity'] = $data['set'] ?? $data['quantity'];   // +/- buttons send "set"; typing sends "quantity"

            return $this->respond($request, $t, function () use ($request, $t, $data) {
                $cart = $this->carts->cart($request, $t, $this->auth->account($t));
                $cart && $this->carts->setQuantity($t, $cart, (int) $data['item'], (int) $data['quantity']);
            }, 'Basket updated.');
        }, true);
    }

    public function remove(Request $request, string $site): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request) {
            $data = $request->validate(['item' => 'required|integer']);

            return $this->respond($request, $t, function () use ($request, $t, $data) {
                $cart = $this->carts->cart($request, $t, $this->auth->account($t));
                $cart && $this->carts->remove($cart, (int) $data['item']);
            }, 'Removed from your basket.');
        }, true);
    }

    public function coupon(Request $request, string $site): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request) {
            $code = mb_strtoupper(trim((string) $request->input('code')));

            return $this->respond($request, $t, function () use ($request, $t, $code) {
                $cart = $this->carts->cart($request, $t, $this->auth->account($t), true);
                if ($code === '' || $request->boolean('remove')) {
                    $cart->update(['coupon_code' => null]);

                    return;
                }
                $sum = $this->carts->summary($t, $cart, ['coupon' => $code]);
                if ($sum['coupon_error']) {
                    throw ValidationException::withMessages(['code' => $sum['coupon_error']]);
                }
                $cart->update(['coupon_code' => $code]);
            }, $code === '' || $request->boolean('remove') ? 'Code removed.' : 'Code applied.');
        }, true);
    }

    /** Run a change; answer JSON (mini cart) or redirect back with a message. */
    protected function respond(Request $request, Tenant $t, callable $change, string $ok, ?string $redirect = null): Response
    {
        try {
            $change();
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => collect($e->errors())->flatten()->first()] + $this->payload($request, $t), 422);
            }

            return back()->withErrors($e->errors())->with('toast_error', collect($e->errors())->flatten()->first());
        }
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $ok, 'redirect' => $redirect ? $this->base($request, $t) . $redirect : null] + $this->payload($request, $t));
        }

        return $redirect ? redirect()->to($this->base($request, $t) . $redirect) : back()->with('toast', $ok);
    }

    /** What the mini cart shows. */
    protected function payload(Request $request, Tenant $t): array
    {
        $cart = $this->carts->cart($request, $t, $this->auth->account($t));
        $s = $this->carts->summary($t, $cart);
        $cur = $s['currency'];

        return [
            'count'    => $s['count'],
            'subtotal' => Money::format($s['subtotal'], $cur),
            'discount' => $s['discount'] > 0 ? Money::format($s['discount'], $cur) : null,
            'total'    => Money::format($s['subtotal'] - $s['discount'], $cur),
            'coupon'   => $s['coupon_code'],
            'threshold'=> $s['free_threshold'] ? ['amount' => Money::format($s['free_threshold'], $cur),
                'left' => Money::format(max(0, $s['free_threshold'] - $s['subtotal'] + $s['discount']), $cur),
                'percent' => min(100, (int) round(($s['subtotal'] - $s['discount']) / $s['free_threshold'] * 100))] : null,
            'items'    => collect($s['lines'])->map(fn ($l) => [
                'id' => $l['id'], 'name' => $l['name'], 'label' => $l['label'], 'qty' => $l['qty'], 'image' => $l['image'],
                'url' => $l['slug'] ? $this->base($request, $t) . '/product/' . $l['slug'] : null,
                'price' => Money::format($l['unit'], $cur), 'total' => Money::format($l['line_total'], $cur), 'problem' => $l['problem'],
                'max' => $l['max'],
            ])->values(),
        ];
    }
}
