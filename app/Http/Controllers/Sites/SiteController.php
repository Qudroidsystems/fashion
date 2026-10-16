<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\FashionStyle;
use App\Models\Tenant;
use App\Services\Sites\SiteService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A fashion house's public website: {slug}.SITES_DOMAIN, /s/{slug}, or the
 * house's own verified domain (rewritten to the subdomain by ResolveSiteDomain).
 */
class SiteController extends Controller
{
    public function __construct(protected SiteService $sites)
    {
    }

    public function home(Request $request, string $site): Response
    {
        return $this->page($request, $site, 'sites.home', fn (Tenant $t) => [
            'featured' => $this->sites->featured($t),
            'products' => $this->sites->products($t)->take(4),
        ]);
    }

    public function collection(Request $request, string $site): Response
    {
        return $this->page($request, $site, 'sites.collection', fn (Tenant $t) => ['styles' => $this->sites->styles($t)]);
    }

    public function style(Request $request, string $site, string $style): Response
    {
        return $this->page($request, $site, 'sites.style', function (Tenant $t) use ($style) {
            $s = FashionStyle::inCatalogue()->with('category', 'currentVersion.images')->where('public_id', $style)->first();
            abort_unless($s, 404);

            return ['style' => $s, 'more' => $this->sites->styles($t)->where('id', '!=', $s->id)->take(3)];
        });
    }

    public function shop(Request $request, string $site): Response
    {
        return $this->page($request, $site, 'sites.shop', fn (Tenant $t) => ['products' => $this->sites->products($t)]);
    }

    public function contact(Request $request, string $site): Response
    {
        return $this->page($request, $site, 'sites.contact', fn (Tenant $t) => [
            'branches' => \App\Models\Branch::active()->orderByDesc('is_main')->get(),
            'type' => in_array($request->query('type'), ['booking', 'measurements'], true) && $this->sites->full($t) ? $request->query('type') : 'enquiry',
            'aboutStyle' => $request->filled('style') ? FashionStyle::inCatalogue()->where('public_id', $request->query('style'))->first() : null,
            'aboutProduct' => $request->filled('product') ? \App\Models\Product::inCatalogue()->where('public_id', $request->query('product'))->first() : null,
        ]);
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

    /* ------------------------------- helpers ------------------------------- */

    protected function page(Request $request, string $site, string $view, callable $data): Response
    {
        $tenant = $this->sites->bySlug($site);
        if (! $tenant) {
            return response()->view('sites.missing', ['slug' => $site], 404);
        }

        return CurrentTenant::run($tenant, fn (Tenant $t) => response()->view($view, $data($t) + [
            'house' => $t, 'site' => (array) $t->setting('website', []), 'base' => $this->base($request, $t),
            'full' => $this->sites->full($t), 'branches' => \App\Models\Branch::active()->orderByDesc('is_main')->get(),
            'platform' => config('landing.brand') ?: config('app.name'),
        ]));
    }

    /** Link prefix: "" on a subdomain or own domain, "/s/{slug}" on the preview address. */
    protected function base(Request $request, Tenant $tenant): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'sitepath.') ? '/s/' . $tenant->slug : '';
    }
}
