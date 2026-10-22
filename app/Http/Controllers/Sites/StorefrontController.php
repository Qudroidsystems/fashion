<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Tenant;
use App\Services\Sites\SiteService;
use App\Services\Store\CartService;
use App\Services\Store\StoreAuth;
use App\Services\Store\StoreCatalog;
use App\Services\Store\StoreSettings;
use App\Services\Store\WhatsApp;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared plumbing for a house's public site and shop: {slug}.SITES_DOMAIN, /s/{slug},
 * or the house's own verified domain. Every page runs inside the house (tenant scope).
 */
abstract class StorefrontController extends Controller
{
    public function __construct(
        protected SiteService $sites,
        protected StoreSettings $store,
        protected StoreCatalog $catalog,
        protected CartService $carts,
        protected StoreAuth $auth,
    ) {
    }

    /** Run $fn inside the house, or show "site not available". $needShop: the online shop must be open too. */
    protected function house(Request $request, string $site, Closure $fn, bool $needShop = false): Response
    {
        $tenant = $this->sites->bySlug($site);
        if (! $tenant) {
            return response()->view('sites.missing', ['slug' => $site], 404);
        }

        return CurrentTenant::run($tenant, function (Tenant $t) use ($request, $fn, $needShop) {
            if ($needShop && ! $this->store->isOpen($t)) {
                return redirect()->to($this->base($request, $t) . '/');
            }
            $result = $fn($t);

            return $result instanceof Response ? $result : response($result);
        });
    }

    /** Render a site page with everything the layout needs. */
    protected function page(Request $request, Tenant $t, string $view, array $data = [], int $status = 200): Response
    {
        return response()->view($view, $data + $this->shared($request, $t), $status);
    }

    protected function shared(Request $request, Tenant $t): array
    {
        $account = $this->auth->account($t);
        $open = $this->store->isOpen($t);
        $cart = $open ? $this->carts->cart($request, $t, $account) : null;

        return [
            'house'       => $t,
            'site'        => (array) $t->setting('website', []),
            'base'        => $this->base($request, $t),
            'full'        => $this->sites->full($t),
            'branches'    => Branch::active()->orderByDesc('is_main')->get(),
            'platform'    => config('landing.brand') ?: config('app.name'),
            'shopOpen'    => $open,
            'shop'        => StoreSettings::get($t),
            'account'     => $account,
            'cartCount'   => $cart ? (int) $cart->items()->sum('quantity') : 0,
            'wishlistIds' => $open ? $this->carts->wishlistIds($request, $t, $account) : [],
            'menuCategories' => $this->catalog->categories(),
            'hasProducts' => \App\Models\Product::inCatalogue()->whereHas('variants', fn ($v) => $v->where('is_active', true))->exists(),
            'waNumber'    => WhatsApp::houseNumber($t) && ($t->setting('website.whatsapp') || StoreSettings::get($t, 'whatsapp.number')) ? WhatsApp::houseNumber($t) : null,
            'assets'      => asset(config('store.assets', 'ecommerce_website')),
        ];
    }

    /** Link prefix: "" on a subdomain or own domain, "/s/{slug}" on the preview address. */
    protected function base(Request $request, Tenant $tenant): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'sitepath.') ? '/s/' . $tenant->slug : '';
    }

    /** Absolute link on the site (for e-mails, payment returns and WhatsApp messages). */
    protected function abs(Request $request, Tenant $tenant, string $path): string
    {
        return $request->getSchemeAndHttpHost() . $this->base($request, $tenant) . '/' . ltrim($path, '/');
    }
}
