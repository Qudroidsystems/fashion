<?php

namespace App\Http\Controllers\Sites;

use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use App\Models\StoreWishlistItem;
use App\Models\Tenant;
use App\Services\Store\StoreCatalog;
use App\Services\Store\StoreSettings;
use App\Services\Store\WhatsApp;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Shop listing, product pages, wishlist, reviews, policy pages and order tracking. */
class ShopController extends StorefrontController
{
    public function index(Request $request, string $site): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request) {
            $f = $request->only(['q', 'category', 'size', 'colour', 'min', 'max', 'sale', 'in_stock', 'sort', 'featured']);
            $products = $this->catalog->search($t, $f, (int) config('store.per_page', 24));

            return $this->page($request, $t, 'sites.shop', [
                'products' => $products,
                'facets'   => $this->catalog->facets(),
                'filters'  => $f,
                'sorts'    => StoreCatalog::SORTS,
                'category' => ! empty($f['category']) ? $this->catalog->categories()->firstWhere('id', (int) $f['category']) : null,
                'stock'    => $this->catalog->stock($t, $products->getCollection()->flatMap->variants),
            ]);
        });
    }

    public function show(Request $request, string $site, string $slug): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request, $slug) {
            $product = $this->catalog->bySlug($slug);
            abort_unless($product, 404);
            $stock = $this->catalog->stock($t, $product->variants);
            $reviews = ProductReview::where('product_id', $product->id)->approved()->latest()->take(20)->get();
            $account = $this->auth->account($t);
            $first = $product->variants->first(fn ($v) => ($stock[$v->id] ?? 1) === null || ($stock[$v->id] ?? 0) > 0) ?? $product->variants->first();
            $url = $this->abs($request, $t, 'product/' . $product->slug);

            return $this->page($request, $t, 'sites.product', [
                'product'  => $product,
                'stock'    => $stock,
                'first'    => $first,
                'related'  => $this->catalog->related($product),
                'reviews'  => $reviews,
                'canReview'=> $this->store->isOpen($t),
                'verified' => $account && ShopOrderItem::where('product_id', $product->id)
                    ->whereHas('shopOrder', fn ($q) => $q->where('account_id', $account->id)->whereNotNull('order_id'))->exists(),
                'waProduct'=> WhatsApp::link(WhatsApp::houseNumber($t), WhatsApp::productMessage($product, $first, $t, $url)),
                'shareUrl' => $url,
                'lowStock' => (int) StoreSettings::get($t, 'low_stock_badge', 3),
                'variantJson' => $product->variants->map(fn ($v) => [
                    'id' => $v->id, 'size' => $v->size, 'colour' => $v->colour, 'price' => (float) $v->price,
                    'compare' => $v->isOnSale() ? (float) $v->compare_at_price : null, 'stock' => $stock[$v->id] ?? null, 'sku' => $v->sku,
                    'wa' => WhatsApp::link(WhatsApp::houseNumber($t), WhatsApp::productMessage($product, $v, $t, $url)),
                ])->values(),
            ]);
        });
    }

    public function wishlist(Request $request, string $site): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request) {
            $ids = $this->carts->wishlistIds($request, $t, $this->auth->account($t));
            $products = $this->catalog->base()->whereIn('products.id', $ids)->get();

            return $this->page($request, $t, 'sites.wishlist', ['products' => $products]);
        }, true);
    }

    public function toggleWishlist(Request $request, string $site): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request) {
            $product = Product::inCatalogue()->where('public_id', (string) $request->input('product'))->firstOrFail();
            $saved = $this->carts->toggleWishlist($request, $t, $this->auth->account($t), $product);
            $count = count($this->carts->wishlistIds($request, $t, $this->auth->account($t)));

            return $request->expectsJson()
                ? response()->json(['saved' => $saved, 'count' => $count, 'message' => $saved ? 'Saved to your wishlist.' : 'Removed from your wishlist.'])
                : back()->with('toast', $saved ? 'Saved to your wishlist.' : 'Removed from your wishlist.');
        }, true);
    }

    public function review(Request $request, string $site, string $slug): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request, $slug) {
            $product = Product::inCatalogue()->where('slug', $slug)->firstOrFail();
            $data = $request->validate([
                'rating' => 'required|integer|min:1|max:5', 'name' => 'required|string|max:80', 'email' => 'nullable|email|max:160',
                'title' => 'nullable|string|max:120', 'body' => 'required|string|max:3000', 'website' => 'nullable|max:0',
            ], ['body.required' => 'Write a few words about the product.']);
            $account = $this->auth->account($t);
            $verified = $account && ShopOrderItem::where('product_id', $product->id)
                ->whereHas('shopOrder', fn ($q) => $q->where('account_id', $account->id)->whereNotNull('order_id'))->exists();
            ProductReview::create(collect($data)->except('website')->all() + ['product_id' => $product->id, 'account_id' => $account?->id,
                'verified_buyer' => $verified, 'status' => 'pending']);

            return redirect()->to($this->base($request, $t) . '/product/' . $product->slug . '#reviews')
                ->with('toast', 'Thank you! Your review will appear once the house has checked it.');
        }, true);
    }

    public function policy(Request $request, string $site, string $page): Response
    {
        $titles = ['shipping' => 'Delivery information', 'returns' => 'Returns & exchanges', 'terms' => 'Terms & conditions', 'privacy' => 'Privacy policy', 'size-guide' => 'Size guide'];
        abort_unless(isset($titles[$page]), 404);

        return $this->house($request, $site, function (Tenant $t) use ($request, $page, $titles) {
            $text = $page === 'size-guide' ? StoreSettings::get($t, 'size_guide') : StoreSettings::get($t, 'policies.' . $page);

            return $this->page($request, $t, 'sites.policy', ['title' => $titles[$page], 'text' => $text, 'slug' => $page]);
        });
    }

    /** Order tracking for guests: order number + the phone or e-mail used. */
    public function track(Request $request, string $site): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request) {
            if ($request->isMethod('post')) {
                $data = $request->validate(['number' => 'required|string|max:40', 'contact' => 'required|string|max:160']);
                $order = ShopOrder::where('number', strtoupper(trim($data['number'])))->first();
                $contact = trim($data['contact']);
                $match = $order && (mb_strtolower($contact) === $order->email
                    || (\App\Models\Customer::normalizePhone($contact) && \App\Models\Customer::normalizePhone($contact) === \App\Models\Customer::normalizePhone($order->phone)));
                if (! $match) {
                    return back()->withInput()->withErrors(['number' => 'We could not find an order with those details.']);
                }

                return redirect()->to($this->base($request, $t) . '/order/' . $order->token);
            }

            return $this->page($request, $t, 'sites.track');
        }, true);
    }
}
