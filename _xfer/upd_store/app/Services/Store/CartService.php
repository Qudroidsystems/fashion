<?php

namespace App\Services\Store;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreAccount;
use App\Models\StoreCart;
use App\Models\StoreCartItem;
use App\Models\StoreWishlistItem;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The shopper's basket. Guests are recognised by a long-lived cookie per house; signed-in
 * shoppers keep one basket on their account (a guest basket is merged in at sign-in).
 * Prices are always read from the catalogue, never from the browser.
 */
class CartService
{
    public const MAX_QTY = 99;

    public function __construct(protected StoreCatalog $catalog, protected CouponService $coupons, protected ShippingService $shipping, protected StoreSettings $settings)
    {
    }

    public static function cookieName(Tenant $tenant): string
    {
        return 'fh_cart_' . $tenant->id;
    }

    /** This browser's token for the house (made and remembered for 30 days if new). */
    public function token(Request $request, Tenant $tenant): string
    {
        $valid = fn ($t) => is_string($t) && preg_match('/^[A-Za-z0-9]{40}$/', $t);
        $token = $request->cookie(self::cookieName($tenant));
        if (! $valid($token)) {
            // Same visit before the cookie comes back (or cookies blocked): the session remembers it.
            $token = $request->attributes->get('store_token_' . $tenant->id)
                ?: ($request->hasSession() ? $request->session()->get('store_cart_' . $tenant->id) : null);
            $token = $valid($token) ? $token : Str::random(40);
            $request->attributes->set('store_token_' . $tenant->id, $token);
        }
        if ($request->hasSession()) {
            $request->session()->put('store_cart_' . $tenant->id, $token);
        }
        Cookie::queue(self::cookieName($tenant), $token, 60 * 24 * 30);

        return $token;
    }

    public function cart(Request $request, Tenant $tenant, ?StoreAccount $account, bool $create = false): ?StoreCart
    {
        $token = $this->token($request, $tenant);
        if ($account) {
            $cart = StoreCart::where('account_id', $account->id)->latest('id')->first();
            $guest = StoreCart::where('token', $token)->whereNull('account_id')->first();
            if ($guest && ! $cart) {
                $guest->update(['account_id' => $account->id]);
                $cart = $guest;
            } elseif ($guest && $cart && $guest->id !== $cart->id) {
                $this->mergeInto($guest, $cart);
            }
            if (! $cart && $create) {
                $cart = StoreCart::create(['token' => Str::random(40), 'account_id' => $account->id]);
            }

            return $cart;
        }
        $cart = StoreCart::where('token', $token)->whereNull('account_id')->first();

        return $cart ?? ($create ? StoreCart::create(['token' => $token]) : null);
    }

    /** Move a guest basket (and wishlist) into the signed-in shopper's. */
    public function mergeInto(StoreCart $from, StoreCart $to): void
    {
        DB::transaction(function () use ($from, $to) {
            foreach ($from->items as $item) {
                $existing = $to->items()->where('product_variant_id', $item->product_variant_id)->first();
                $existing ? $existing->update(['quantity' => min(self::MAX_QTY, $existing->quantity + $item->quantity)])
                    : $to->items()->create(['product_variant_id' => $item->product_variant_id, 'quantity' => $item->quantity]);
            }
            if ($from->coupon_code && ! $to->coupon_code) {
                $to->update(['coupon_code' => $from->coupon_code]);
            }
            $from->delete();
        });
    }

    public function claimWishlist(string $token, StoreAccount $account): void
    {
        $have = StoreWishlistItem::where('account_id', $account->id)->pluck('product_id')->all();
        StoreWishlistItem::where('token', $token)->whereNull('account_id')->get()->each(function ($w) use ($account, $have) {
            in_array($w->product_id, $have, true) ? $w->delete() : $w->update(['account_id' => $account->id, 'token' => null]);
        });
    }

    public function add(Tenant $tenant, StoreCart $cart, int $variantId, int $qty): StoreCartItem
    {
        $variant = $this->sellable($variantId);
        $qty = max(1, min(self::MAX_QTY, $qty));
        $item = $cart->items()->where('product_variant_id', $variant->id)->first();
        $want = min(self::MAX_QTY, ($item?->quantity ?? 0) + $qty);
        $this->assertStock($tenant, $variant, $want);

        if ($item) {
            $item->update(['quantity' => $want]);
        } else {
            $item = $cart->items()->create(['product_variant_id' => $variant->id, 'quantity' => $want]);
        }
        $cart->touch();

        return $item;
    }

    public function setQuantity(Tenant $tenant, StoreCart $cart, int $itemId, int $qty): void
    {
        $item = $cart->items()->whereKey($itemId)->first();
        if (! $item) {
            return;
        }
        if ($qty <= 0) {
            $item->delete();

            return;
        }
        $qty = min(self::MAX_QTY, $qty);
        $variant = $item->variant;
        if ($variant) {
            $this->assertStock($tenant, $variant, $qty);
        }
        $item->update(['quantity' => $qty]);
    }

    public function remove(StoreCart $cart, int $itemId): void
    {
        $cart->items()->whereKey($itemId)->delete();
    }

    public function sellable(int $variantId): ProductVariant
    {
        $variant = ProductVariant::with('product')->where('is_active', true)->find($variantId);
        if (! $variant || ! $variant->product || $variant->product->status !== 'active' || ! $variant->product->show_in_catalogue) {
            throw ValidationException::withMessages(['variant' => 'Sorry, that item is no longer available.']);
        }

        return $variant;
    }

    public function assertStock(Tenant $tenant, ProductVariant $variant, int $qty): void
    {
        $free = $this->catalog->stock($tenant, collect([$variant]))[$variant->id] ?? null;
        if ($free !== null && $qty > $free) {
            throw ValidationException::withMessages(['quantity' => $free <= 0
                ? 'Sorry, ' . $variant->product->name . ($variant->label !== 'Standard' ? ' (' . $variant->label . ')' : '') . ' is sold out.'
                : 'Only ' . $free . ' of ' . $variant->product->name . ($variant->label !== 'Standard' ? ' (' . $variant->label . ')' : '') . ' left.']);
        }
    }

    /**
     * Everything the cart, mini cart and checkout show, worked out on the server.
     * $opt: fulfilment (delivery|pickup), state, email, phone, coupon (override the cart's).
     * Money in the result is in major units (floats rounded to 2 places).
     */
    public function summary(Tenant $tenant, ?StoreCart $cart, array $opt = []): array
    {
        $items = $cart ? $cart->items()->with(['variant.product.images'])->get() : collect();
        $stock = $this->catalog->stock($tenant, $items->pluck('variant')->filter());
        $lines = [];
        $sub = 0;
        $problems = false;
        foreach ($items as $it) {
            $v = $it->variant;
            $p = $v?->product;
            $available = $v && $v->is_active && $p && $p->status === 'active' && $p->show_in_catalogue;
            $max = $available ? ($stock[$v->id] ?? null) : 0;
            $problem = ! $available ? 'No longer available' : ($max !== null && $it->quantity > $max ? ($max <= 0 ? 'Sold out' : 'Only ' . $max . ' left') : null);
            $unit = $available ? (int) round((float) $v->price * 100) : 0;
            $line = $problem && ! $available ? 0 : $unit * $it->quantity;
            if (! $problem) {
                $sub += $line;   // lines with a problem must be fixed before checkout
            }
            $problems = $problems || (bool) $problem;
            $lines[] = [
                'id' => $it->id, 'variant_id' => $v?->id, 'product_id' => $p?->id, 'slug' => $p?->slug,
                'name' => $p?->name ?? 'Item no longer available', 'label' => $v && $v->label !== 'Standard' ? $v->label : null,
                'size' => $v?->size, 'colour' => $v?->colour, 'sku' => $v?->sku,
                'image' => $p?->cover_url, 'image_path' => $p ? ($p->images->first()?->path ?? $p->image_path) : null,
                'unit' => $unit / 100, 'compare' => $v && $v->isOnSale() ? (float) $v->compare_at_price : null,
                'qty' => $it->quantity, 'line_total' => $line / 100, 'max' => $max, 'problem' => $problem,
            ];
        }

        $code = array_key_exists('coupon', $opt) ? $opt['coupon'] : $cart?->coupon_code;
        $coupon = $this->coupons->check($code, $sub, $opt['email'] ?? null, $opt['phone'] ?? null);
        $discount = $coupon['discount'];

        $fulfilment = ($opt['fulfilment'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery';
        $delivery = $this->shipping->delivery($tenant, $opt['state'] ?? null, ($sub - $discount) / 100);
        $shipping = 0;
        $shippingLabel = null;
        if ($fulfilment === 'delivery' && $delivery) {
            $shipping = $coupon['free_shipping'] ? 0 : (int) round($delivery['fee'] * 100);
            $shippingLabel = $delivery['label'];
        }

        return [
            'lines'          => $lines,
            'count'          => (int) collect($lines)->sum('qty'),
            'subtotal'       => $sub / 100,
            'coupon'         => $coupon['coupon'],
            'coupon_code'    => $coupon['coupon']?->code,
            'coupon_error'   => $code ? $coupon['error'] : null,
            'discount'       => $discount / 100,
            'free_shipping'  => $coupon['free_shipping'],
            'fulfilment'     => $fulfilment,
            'delivery'       => $delivery,
            'shipping'       => $shipping / 100,
            'shipping_label' => $shippingLabel,
            'total'          => max(0, $sub - $discount + $shipping) / 100,
            'problems'       => $problems,
            'free_threshold' => $this->shipping->freeThreshold(),
            'currency'       => $tenant->currency_code ?: 'NGN',
        ];
    }

    /** Wishlist product ids for this shopper. */
    public function wishlistIds(Request $request, Tenant $tenant, ?StoreAccount $account): array
    {
        return StoreWishlistItem::when($account, fn ($q) => $q->where('account_id', $account->id),
            fn ($q) => $q->whereNull('account_id')->where('token', $this->token($request, $tenant)))->pluck('product_id')->all();
    }

    /** Add or remove a product from the wishlist; returns true when it is now saved. */
    public function toggleWishlist(Request $request, Tenant $tenant, ?StoreAccount $account, Product $product): bool
    {
        $q = StoreWishlistItem::where('product_id', $product->id)
            ->when($account, fn ($w) => $w->where('account_id', $account->id), fn ($w) => $w->whereNull('account_id')->where('token', $this->token($request, $tenant)));
        if ($row = $q->first()) {
            $row->delete();

            return false;
        }
        StoreWishlistItem::create(['product_id' => $product->id, 'account_id' => $account?->id, 'token' => $account ? null : $this->token($request, $tenant)]);

        return true;
    }
}
