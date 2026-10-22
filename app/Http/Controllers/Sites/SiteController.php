<?php

namespace App\Http\Controllers\Sites;

use App\Models\FashionStyle;
use App\Models\Product;
use App\Models\StoreBanner;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A fashion house's public website: home, made-to-measure collection, contact.
 * (The shop pages are in ShopController, CartController, CheckoutController and AccountController.)
 */
class SiteController extends StorefrontController
{
    public function home(Request $request, string $site): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request) {
            $open = $this->store->isOpen($t);

            return $this->page($request, $t, 'sites.home', [
                'featured'    => $this->sites->featured($t),
                'slides'      => $open ? StoreBanner::live('hero')->get() : collect(),
                'promos'      => $open ? StoreBanner::live('promo')->take(2)->get() : collect(),
                'categories'  => $this->catalog->categories(),
                'newest'      => $this->catalog->newest(8),
                'bestSellers' => $this->catalog->bestSellers(8),
                'onSale'      => $this->catalog->onSale(8),
                'products'    => $this->catalog->featured(8),
            ]);
        });
    }

    public function collection(Request $request, string $site): Response
    {
        return $this->house($request, $site, fn (Tenant $t) => $this->page($request, $t, 'sites.collection', ['styles' => $this->sites->styles($t)]));
    }

    public function style(Request $request, string $site, string $style): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request, $style) {
            $s = FashionStyle::inCatalogue()->with('category', 'currentVersion.images')->where('public_id', $style)->first();
            abort_unless($s, 404);

            return $this->page($request, $t, 'sites.style', ['style' => $s, 'more' => $this->sites->styles($t)->where('id', '!=', $s->id)->take(4)]);
        });
    }

    public function contact(Request $request, string $site): Response
    {
        return $this->house($request, $site, fn (Tenant $t) => $this->page($request, $t, 'sites.contact', [
            'type' => in_array($request->query('type'), ['booking', 'measurements'], true) && $this->sites->full($t) ? $request->query('type') : 'enquiry',
            'aboutStyle' => $request->filled('style') ? FashionStyle::inCatalogue()->where('public_id', $request->query('style'))->first() : null,
            'aboutProduct' => $request->filled('product') ? Product::inCatalogue()->where('public_id', $request->query('product'))->first() : null,
        ]));
    }

    public function enquire(Request $request, string $site): RedirectResponse
    {
        $tenant = $this->sites->bySlug($site);
        abort_unless($tenant, 404);
        $data = $request->validate([
            'type'           => 'nullable|in:enquiry,booking,measurements',
            'name'           => 'required|string|max:120',
            'phone'          => 'required_without:email|nullable|string|max:30',
            'email'          => 'required_without:phone|nullable|email|max:160',
            'message'        => 'nullable|string|max:3000',
            'style'          => 'nullable|string|max:40',
            'product'        => 'nullable|string|max:40',
            'preferred_date' => 'nullable|date|after_or_equal:today',
            'preferred_time' => 'nullable|string|max:40',
            'website'        => 'nullable|string|max:0',   // honeypot: people never fill it
            '_t'             => 'nullable|integer',
        ], ['website.max' => 'Please try again.', 'phone.required_without' => 'Leave a phone number or an e-mail so we can reply.']);
        CurrentTenant::run($tenant, fn () => $this->sites->enquire($tenant, $data, $request));

        return redirect()->to($this->base($request, $tenant) . '/contact')->with('sent', true);
    }
}
